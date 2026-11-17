<?php

/*
 * Tests with real worker processes (testing/worker-process.php), using the real clock: several workers on one batch
 * never run a task twice and respect its dependencies, a worker killed partway through a task has it recovered once
 * its lease runs out, and an idle worker starts on a new task as soon as it's added, rather than at its next poll.
 */

use Programster\PgsqlTaskQueue\InterruptedTaskPolicy;
use Programster\PgsqlTaskQueue\NewTask;
use Programster\PgsqlTaskQueue\NewTaskCollection;
use Programster\PgsqlTaskQueue\QueueName;
use Programster\PgsqlTaskQueue\RetrySchedule;
use Programster\PgsqlTaskQueue\SystemClock;
use Programster\PgsqlTaskQueue\TaskOptions;
use Programster\PgsqlTaskQueue\TaskQueue;
use Programster\PgsqlTaskQueue\TaskStatus;
use Programster\PgsqlTaskQueue\Testing\Fixtures;
use Programster\PgsqlTaskQueue\Testing\SmokeTest;
use Programster\PgsqlTaskQueue\Testing\TestDatabase;
use Programster\PgsqlTaskQueue\Testing\TestTask;
use Programster\PgsqlTaskQueue\Testing\TestTaskBehaviour;

/**
 * Start a worker process on a batch, with its output kept in a temporary file.
 * @param int $batchId
 * @param float $pollSeconds
 * @return array{process: resource, pid: int}
 */
function startWorkerProcess(int $batchId, float $pollSeconds) : array
{
    $command = [PHP_BINARY, '-d', 'zend.assertions=1', __DIR__ . '/../worker-process.php', $batchId, $pollSeconds];
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $process = proc_open(array_map('strval', $command), $descriptors, $pipes);
    assert(is_resource($process), "The worker process should start.");
    return ['process' => $process, 'pid' => proc_get_status($process)['pid']];
}


/**
 * Wait for a worker process to exit by itself.
 * @param array{process: resource, pid: int} $worker
 * @param float $timeoutSeconds
 */
function waitForWorkerProcess(array $worker, float $timeoutSeconds) : void
{
    $isFinished = function () use ($worker) : bool {
        return !proc_get_status($worker['process'])['running'];
    };

    waitUntil($isFinished, $timeoutSeconds, "the worker process to exit");
    proc_close($worker['process']);
}


/**
 * Wait until a condition is true, checking every 20ms.
 * @param callable(): bool $condition
 * @param float $timeoutSeconds
 * @param string $what - what's being waited for, for the message if it times out.
 * @return float - how long it took, in seconds.
 */
function waitUntil(callable $condition, float $timeoutSeconds, string $what) : float
{
    $startedAt = microtime(true);

    while (!$condition())
    {
        if (microtime(true) - $startedAt > $timeoutSeconds)
        {
            throw new \Exception("Timed out after {$timeoutSeconds}s waiting for {$what}.");
        }

        usleep(20000);
    }

    return microtime(true) - $startedAt;
}


/**
 * @param string $query
 * @return int - the first column of the query's first row.
 */
function fetchCount(string $query) : int
{
    return (int)pg_fetch_result(TestDatabase::get()->query($query), 0, 0);
}


/** @var SmokeTest $smoke */

$manyWorkers = function () : void {
    TestDatabase::reset();
    $queue = new TaskQueue(TestDatabase::get(), new SystemClock(), TestDatabase::SCHEMA);
    $batch = $queue->createBatch('many workers');
    $tasks = [];

    // 40 chains of 5 tasks, each depending on the one before it in its chain.
    for ($chain = 1; $chain <= 40; $chain++)
    {
        $previous = null;

        for ($link = 1; $link <= 5; $link++)
        {
            $dependencies = ($previous === null) ? [] : [$previous];
            $task = new TestTask("{$chain}.{$link}", TestTaskBehaviour::RECORD, 5);
            $previous = new NewTask($task, new QueueName(Fixtures::QUEUE), new TaskOptions(), ...$dependencies);
            $tasks[] = $previous;
        }
    }

    $queue->enqueueAll(new NewTaskCollection(...$tasks), $batch);
    $workers = [];

    for ($i = 0; $i < 6; $i++)
    {
        $workers[] = startWorkerProcess($batch->getId(), 1);
    }

    foreach ($workers as $worker)
    {
        waitForWorkerProcess($worker, 60);
    }

    $summary = $queue->fetchBatchSummary($batch);
    SmokeTest::assertSame(200, $summary->countWithStatus(TaskStatus::SUCCEEDED), "tasks that succeeded");
    SmokeTest::assertSame(200, fetchCount("SELECT count(*) FROM public.test_run"), "times a task was run");
    SmokeTest::assertSame(200, fetchCount("SELECT count(DISTINCT label) FROM public.test_run"), "tasks run");
    assert(fetchCount("SELECT count(DISTINCT worker) FROM public.test_run") > 1, "More than one worker ran tasks.");

    // Each link started after the link before it in its chain had finished.
    $outOfOrderQuery = <<<'SQL'
        SELECT count(*) FROM public.test_run AS later
        JOIN public.test_run AS earlier
            ON split_part(earlier.label, '.', 1) = split_part(later.label, '.', 1)
            AND split_part(earlier.label, '.', 2)::int = split_part(later.label, '.', 2)::int - 1
        WHERE later.started_at < earlier.finished_at
        SQL;

    SmokeTest::assertSame(0, fetchCount($outOfOrderQuery), "tasks that started before their dependency finished");
};

$smoke->run("processes: six workers on 200 dependent tasks run each once, in dependency order", $manyWorkers);

$killedWorker = function () : void {
    foreach ([InterruptedTaskPolicy::RETRY, InterruptedTaskPolicy::FAIL] as $policy)
    {
        TestDatabase::reset();
        $queue = new TaskQueue(TestDatabase::get(), new SystemClock(), TestDatabase::SCHEMA);
        $batch = $queue->createBatch("killed worker, {$policy->value}");

        $options = new TaskOptions(
            retrySchedule: RetrySchedule::createUsingDelaysInSeconds(1),
            onInterruption: $policy,
            leaseSeconds: 2
        );

        $task = $queue->enqueue(Fixtures::createTask('hangs', TestTaskBehaviour::HANG, $options), $batch);
        $first = startWorkerProcess($batch->getId(), 0.2);

        $hasStarted = function () : bool {
            return (fetchCount("SELECT count(*) FROM public.test_run") === 1);
        };

        waitUntil($hasStarted, 10, "the task to start");
        posix_kill($first['pid'], SIGKILL);
        waitForWorkerProcess($first, 10);

        // Make the retry finish quickly, rather than hanging again.
        $recordPayload = '{"label": "hangs", "behaviour": "record", "sleepMs": 0}';
        $update = 'UPDATE task_queue_test.task SET payload = $1 WHERE id = $2';
        TestDatabase::get()->query($update, [$recordPayload, $task->getId()]);

        $second = startWorkerProcess($batch->getId(), 0.2);
        waitForWorkerProcess($second, 20);
        $recovered = $queue->fetchTask($task->getId());

        if ($policy === InterruptedTaskPolicy::RETRY)
        {
            SmokeTest::assertSame(TaskStatus::SUCCEEDED, $recovered->getStatus(), "the retried task's status");
            SmokeTest::assertSame(2, $recovered->getAttempts(), "attempts, including the interrupted one");
            SmokeTest::assertSame(2, fetchCount("SELECT count(DISTINCT worker) FROM public.test_run"), "workers");
        }
        else
        {
            SmokeTest::assertSame(TaskStatus::DEAD, $recovered->getStatus(), "the failed task's status");
            assert(str_contains((string)$recovered->getError(), "may already have done"), "Why it failed is kept.");
            SmokeTest::assertSame(1, fetchCount("SELECT count(*) FROM public.test_run"), "times it ran");
        }
    }
};

$smoke->run("processes: a task whose worker was killed is retried or failed, by its policy", $killedWorker);

$notifyWakesWorker = function () : void {
    TestDatabase::reset();
    $queue = new TaskQueue(TestDatabase::get(), new SystemClock(), TestDatabase::SCHEMA);
    $batch = $queue->createBatch('notify');

    // A task that isn't due for a day keeps the batch unfinished, so the worker waits.
    $later = (new SystemClock())->now()->modify('+1 day');
    $queue->enqueue(Fixtures::createTask('tomorrow', options: new TaskOptions(notBefore: $later)), $batch);
    $worker = startWorkerProcess($batch->getId(), 30);
    usleep(500000);
    $now = new NewTask(new TestTask('now', TestTaskBehaviour::RECORD), new QueueName(Fixtures::QUEUE));
    $queue->enqueue($now, $batch);

    $hasRun = function () : bool {
        return (fetchCount("SELECT count(*) FROM public.test_run WHERE finished_at IS NOT NULL") === 1);
    };

    $seconds = waitUntil($hasRun, 10, "the new task to run");
    assert($seconds < 5, "The worker should start on it straight away, not at its next poll, in 30 seconds.");
    posix_kill($worker['pid'], SIGTERM);
    waitForWorkerProcess($worker, 10);
};

$smoke->run("processes: an idle worker starts on a new task at once, and stops on SIGTERM", $notifyWakesWorker);
