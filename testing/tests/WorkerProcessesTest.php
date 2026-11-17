<?php

/*
 * Tests with real worker processes (testing/worker-process.php), using the real clock: several workers on one group
 * never run a task twice and keep to its dependencies, a task whose worker is killed partway through is removed once
 * its lease runs out, and an idle worker starts on a new task as soon as it's added, rather than when its wait is up.
 */

use Programster\PgsqlTaskQueue\TaskCollection;
use Programster\PgsqlTaskQueue\TaskQueue;
use Programster\PgsqlTaskQueue\Testing\SmokeTest;
use Programster\PgsqlTaskQueue\Testing\TestDatabase;
use Programster\PgsqlTaskQueue\Testing\TestHandler;

/**
 * Start a worker process on a group.
 * @param string $group
 * @param float $waitSeconds
 * @return array{process: resource, pid: int}
 */
function startWorkerProcess(string $group, float $waitSeconds) : array
{
    $script = __DIR__ . '/../worker-process.php';
    $command = [PHP_BINARY, '-d', 'zend.assertions=1', $script, $group, (string)$waitSeconds];
    $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
    $process = proc_open($command, $descriptors, $pipes);
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
    $queue = new TaskQueue(TestDatabase::get(), null, TestDatabase::SCHEMA);
    $tasks = [];

    // 40 chains of 5 tasks, each depending on the one before it in its chain.
    for ($chain = 1; $chain <= 40; $chain++)
    {
        $dependsOn = new TaskCollection();

        for ($link = 1; $link <= 5; $link++)
        {
            $task = TestHandler::createTask("{$chain}.{$link}", 'record', $dependsOn, group: 'many', sleepMs: 5);
            $dependsOn = new TaskCollection($task);
            $tasks[] = $task;
        }
    }

    $queue->enqueue(...$tasks);
    $workers = [];

    for ($i = 0; $i < 6; $i++)
    {
        $workers[] = startWorkerProcess('many', 1);
    }

    foreach ($workers as $worker)
    {
        waitForWorkerProcess($worker, 60);
    }

    SmokeTest::assertSame(0, TestDatabase::countTasks(), "the tasks left");
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
    TestDatabase::reset();
    $queue = new TaskQueue(TestDatabase::get(), null, TestDatabase::SCHEMA);
    $hangs = TestHandler::createTask('hangs', 'hang', group: 'killed', leaseSeconds: 2);
    $dependent = TestHandler::createTask('dependent', 'record', new TaskCollection($hangs), group: 'killed');
    $queue->enqueue($hangs, $dependent);
    $first = startWorkerProcess('killed', 0.2);

    $hasStarted = function () : bool {
        return (fetchCount("SELECT count(*) FROM public.test_run") === 1);
    };

    waitUntil($hasStarted, 10, "the task to start");
    posix_kill($first['pid'], SIGKILL);
    waitForWorkerProcess($first, 10);

    // The next worker removes it once its lease is up (and throws), with its dependent, so the group empties and it
    // stops.
    $second = startWorkerProcess('killed', 0.2);
    waitForWorkerProcess($second, 20);
    SmokeTest::assertSame(0, TestDatabase::countTasks(), "the tasks left");
    SmokeTest::assertSame(1, fetchCount("SELECT count(*) FROM public.test_run"), "the tasks that ran");
};

$smoke->run("processes: a task whose worker was killed is removed, with its dependents, in time", $killedWorker);

$notifyWakesWorker = function () : void {
    TestDatabase::reset();
    $queue = new TaskQueue(TestDatabase::get(), null, TestDatabase::SCHEMA);

    // A task that isn't due for a day keeps the group from emptying, so the worker waits.
    $tomorrow = new \DateTimeImmutable('+1 day');
    $queue->enqueue(TestHandler::createTask('tomorrow', group: 'notify', notBefore: $tomorrow));
    $worker = startWorkerProcess('notify', 30);
    usleep(500000);
    $queue->enqueue(TestHandler::createTask('now', 'record', group: 'notify'));

    $hasRun = function () : bool {
        return (fetchCount("SELECT count(*) FROM public.test_run WHERE finished_at IS NOT NULL") === 1);
    };

    $seconds = waitUntil($hasRun, 10, "the new task to run");
    assert($seconds < 5, "The worker should start on it straight away, not when its 30 second wait is up.");
    posix_kill($worker['pid'], SIGTERM);
    waitForWorkerProcess($worker, 10);
};

$smoke->run("processes: an idle worker starts on a new task at once, and stops on SIGTERM", $notifyWakesWorker);
