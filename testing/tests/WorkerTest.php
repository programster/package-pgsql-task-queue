<?php

/*
 * Tests of a worker running tasks in this process, with a fake clock: success and the tasks it releases, temporary
 * and permanent failures, retries, deferral, lost leases, interrupted tasks, retrying dead tasks, priorities, stop
 * conditions, signals and deleting old tasks.
 */

use Programster\PgsqlTaskQueue\Exceptions\ExceptionCantRetryTask;
use Programster\PgsqlTaskQueue\InterruptedTaskPolicy;
use Programster\PgsqlTaskQueue\NewTaskCollection;
use Programster\PgsqlTaskQueue\QueueName;
use Programster\PgsqlTaskQueue\QueueNameCollection;
use Programster\PgsqlTaskQueue\RetrySchedule;
use Programster\PgsqlTaskQueue\TaskHandlerCollection;
use Programster\PgsqlTaskQueue\TaskOptions;
use Programster\PgsqlTaskQueue\TaskStatus;
use Programster\PgsqlTaskQueue\Testing\FakeClock;
use Programster\PgsqlTaskQueue\Testing\Fixtures;
use Programster\PgsqlTaskQueue\Testing\HandledLog;
use Programster\PgsqlTaskQueue\Testing\RecordingLogger;
use Programster\PgsqlTaskQueue\Testing\SmokeTest;
use Programster\PgsqlTaskQueue\Testing\TestDatabase;
use Programster\PgsqlTaskQueue\Testing\TestTaskBehaviour;
use Programster\PgsqlTaskQueue\Testing\TestTaskHandler;
use Programster\PgsqlTaskQueue\WorkerStopConditions;
use Programster\PgsqlTaskQueue\WorkerStopReason;

/** @var SmokeTest $smoke */


$successReleasesDependents = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $a = Fixtures::createTask('a');
    $b = Fixtures::createTask('b');
    $c = Fixtures::createTask('c', TestTaskBehaviour::SUCCEED, new TaskOptions(), $a, $b);
    $queued = $queue->enqueueAll(new NewTaskCollection($c, $b, $a));
    $log = new HandledLog();
    $reason = Fixtures::createWorker($queue, $clock, $log)->run();

    SmokeTest::assertSame(WorkerStopReason::IDLE, $reason, "why the worker stopped");
    SmokeTest::assertSame(['a#1', 'b#1', 'c#1'], $log->getEntries(), "what ran, in order");
    $c = $queue->fetchTask($queued->get(0)->getId());
    SmokeTest::assertSame(TaskStatus::SUCCEEDED, $c->getStatus(), "c's status");
    SmokeTest::assertSame(['label' => 'c'], $c->getResultData(), "c's result data");
    SmokeTest::assertSame('ran c', $c->getOutput(), "c's output");
    assert($c->getFinishedAt() == $clock->now() && $c->getDurationMs() !== null, "Its finish is recorded.");
};

$smoke->run("worker: a task runs once all its dependencies have succeeded", $successReleasesDependents);

$priorities = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $low = Fixtures::createTask('low', options: new TaskOptions(priority: -1));
    $normal = Fixtures::createTask('normal');
    $high = Fixtures::createTask('high', options: new TaskOptions(priority: 10));
    $queue->enqueueAll(new NewTaskCollection($low, $normal, $high));
    $log = new HandledLog();
    Fixtures::createWorker($queue, $clock, $log)->run();
    SmokeTest::assertSame(['high#1', 'normal#1', 'low#1'], $log->getEntries(), "the order they ran in");
};

$smoke->run("worker: tasks with a higher priority are claimed first", $priorities);

$slowestFirst = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $queue->enqueue(Fixtures::createTask('quick', options: new TaskOptions(name: 'quick')));
    $queue->enqueue(Fixtures::createTask('slow', options: new TaskOptions(name: 'slow')));
    Fixtures::createWorker($queue, $clock, new HandledLog())->run();
    TestDatabase::get()->query("UPDATE task_queue_test.task SET duration_ms = 5000 WHERE name = 'slow'");

    // Named like the last ones, the slow one is expected to take longer, so it's claimed first, though added last.
    $queue->enqueue(Fixtures::createTask('quick again', options: new TaskOptions(name: 'quick')));
    $queue->enqueue(Fixtures::createTask('slow again', options: new TaskOptions(name: 'slow')));
    $log = new HandledLog();
    Fixtures::createWorker($queue, $clock, $log)->run();
    SmokeTest::assertSame(['slow again#1', 'quick again#1'], $log->getEntries(), "the order they ran in");
};

$smoke->run("worker: among equal priorities, the task expected to take longest is claimed first", $slowestFirst);

$temporaryFailureRetried = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $options = new TaskOptions(retrySchedule: RetrySchedule::createUsingDelaysInSeconds(60, 300));
    $task = $queue->enqueue(Fixtures::createTask('flaky', TestTaskBehaviour::FAIL_TEMPORARILY, $options));
    $log = new HandledLog();
    $logger = new RecordingLogger();
    Fixtures::createWorker($queue, $clock, $log, $logger)->run();

    $afterFirst = $queue->fetchTask($task->getId());
    SmokeTest::assertSame(TaskStatus::WAITING, $afterFirst->getStatus(), "the status after a temporary failure");
    assert($afterFirst->getAvailableAt() == $clock->now()->modify('+60 seconds'), "It's retried after 60 seconds.");
    assert(str_contains((string)$afterFirst->getError(), "flaky failed temporarily"), "The error is recorded.");
    assert($logger->hasLineContaining("notice: Task 1, attempt 1 of 3, failed, and will be retried"), "It's logged.");

    // Not before its retry is due.
    Fixtures::createWorker($queue, $clock, $log)->run();
    SmokeTest::assertSame(['flaky#1'], $log->getEntries(), "what ran before the retry was due");

    $clock->advance(60);
    Fixtures::createWorker($queue, $clock, $log)->run();
    $clock->advance(300);
    Fixtures::createWorker($queue, $clock, $log, $logger)->run();
    SmokeTest::assertSame(['flaky#1', 'flaky#2', 'flaky#3'], $log->getEntries(), "every attempt");
    SmokeTest::assertSame(TaskStatus::DEAD, $queue->fetchTask($task->getId())->getStatus(), "the status at the end");
    assert($logger->hasLineContaining("error: Task 1, attempt 3 of 3, died"), "Its death is logged as an error.");
};

$smoke->run("worker: a temporary failure is retried after each delay, then dies", $temporaryFailureRetried);

$permanentFailureSkipsChain = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $options = new TaskOptions(retrySchedule: RetrySchedule::createUsingDelaysInSeconds(60), name: 'broken');
    $a = Fixtures::createTask('a', TestTaskBehaviour::FAIL_PERMANENTLY, $options);
    $b = Fixtures::createTask('b', TestTaskBehaviour::SUCCEED, new TaskOptions(), $a);
    $c = Fixtures::createTask('c', TestTaskBehaviour::SUCCEED, new TaskOptions(), $b);
    $unrelated = Fixtures::createTask('unrelated');
    $batch = $queue->createBatch('run');
    $queued = $queue->enqueueAll(new NewTaskCollection($a, $b, $c, $unrelated), $batch);
    $log = new HandledLog();
    Fixtures::createWorker($queue, $clock, $log)->run();

    SmokeTest::assertSame(['a#1', 'unrelated#1'], $log->getEntries(), "what ran");
    SmokeTest::assertSame(TaskStatus::DEAD, $queue->fetchTask($queued->get(0)->getId())->getStatus(), "a's status");

    foreach ([1, 2] as $index)
    {
        $skipped = $queue->fetchTask($queued->get($index)->getId());
        SmokeTest::assertSame(TaskStatus::SKIPPED, $skipped->getStatus(), "a dependent's status");
        SmokeTest::assertSame($queued->get(0)->getId(), $skipped->getSkippedBecauseId(), "what it was skipped for");
        assert(str_contains((string)$skipped->getError(), "task 1 (broken), which it depends on, died"));
    }

    // A task added later that depends on the dead one is skipped straight away.
    $late = Fixtures::createTask('late', TestTaskBehaviour::SUCCEED, new TaskOptions(), $queued->get(2));
    SmokeTest::assertSame(TaskStatus::SKIPPED, $queue->enqueue($late)->getStatus(), "a late dependent's status");

    $summary = $queue->fetchBatchSummary($batch);
    assert($summary->isFinished() && !$summary->isAllSucceeded(), "The batch finished, but not all succeeded.");
    SmokeTest::assertSame(2, $summary->countWithStatus(TaskStatus::SKIPPED), "skipped tasks in the batch");
};

$smoke->run("worker: a permanent failure dies at once, skipping all that depends on it", $permanentFailureSkipsChain);

$retryDeadUnskips = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $a = Fixtures::createTask('a', TestTaskBehaviour::FAIL_PERMANENTLY);
    $other = Fixtures::createTask('other');
    $b = Fixtures::createTask('b', TestTaskBehaviour::SUCCEED, new TaskOptions(), $a, $other);
    $c = Fixtures::createTask('c', TestTaskBehaviour::SUCCEED, new TaskOptions(), $b);
    $queued = $queue->enqueueAll(new NewTaskCollection($a, $other, $b, $c));
    Fixtures::createWorker($queue, $clock, new HandledLog())->run();

    $retryUnfinished = function () use ($queue, $queued) : void {
        $queue->retry($queued->get(2)->getId());
    };

    SmokeTest::expectException(ExceptionCantRetryTask::class, $retryUnfinished, "skipped");

    // Make it succeed this time, then retry it.
    $succeedPayload = '{"label": "a", "behaviour": "succeed", "sleepMs": 0}';
    $update = 'UPDATE task_queue_test.task SET payload = $1 WHERE id = $2';
    TestDatabase::get()->queryWithParams($update, [$succeedPayload, $queued->get(0)->getId()]);
    $queue->retry($queued->get(0)->getId());
    $c = $queue->fetchTask($queued->get(3)->getId());
    SmokeTest::assertSame(TaskStatus::BLOCKED, $c->getStatus(), "c's status, un-skipped");
    $log = new HandledLog();
    Fixtures::createWorker($queue, $clock, $log)->run();
    SmokeTest::assertSame(['a#1', 'b#1', 'c#1'], $log->getEntries(), "what ran after the retry");
};

$smoke->run("worker: retrying a dead task un-skips the tasks that depended on it", $retryDeadUnskips);

$deferral = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $task = $queue->enqueue(Fixtures::createTask('limited', TestTaskBehaviour::DEFER_ONCE));
    $log = new HandledLog();
    Fixtures::createWorker($queue, $clock, $log)->run();

    $deferred = $queue->fetchTask($task->getId());
    SmokeTest::assertSame(TaskStatus::WAITING, $deferred->getStatus(), "the status while deferred");
    SmokeTest::assertSame(0, $deferred->getAttempts(), "attempts used by a deferral");
    assert($deferred->getAvailableAt() == $clock->now()->modify('+1 hour'), "It waits until the time given.");

    $clock->advance(3600);
    Fixtures::createWorker($queue, $clock, $log)->run();
    SmokeTest::assertSame(['limited#1', 'limited#1'], $log->getEntries(), "both runs were its first attempt");
    SmokeTest::assertSame(TaskStatus::SUCCEEDED, $queue->fetchTask($task->getId())->getStatus(), "its final status");
};

$smoke->run("worker: a deferred task waits until the time given, without using an attempt", $deferral);

$lostLease = function () : void {
    foreach ([InterruptedTaskPolicy::RETRY, InterruptedTaskPolicy::FAIL] as $policy)
    {
        $clock = new FakeClock();
        $queue = Fixtures::createQueue($clock);

        $options = new TaskOptions(
            retrySchedule: RetrySchedule::createUsingDelaysInSeconds(1),
            onInterruption: $policy,
            leaseSeconds: 60
        );

        $task = $queue->enqueue(Fixtures::createTask('slow', TestTaskBehaviour::LOSE_LEASE, $options));
        $logger = new RecordingLogger();
        Fixtures::createWorker($queue, $clock, new HandledLog(), $logger, maxTasks: 1)->run();

        // The worker's result was ignored, as another worker (here, the handler) had recovered the task.
        assert($logger->hasLineContaining("no longer holds task"), "The lost lease is logged.");
        $recovered = $queue->fetchTask($task->getId());
        $expected = ($policy === InterruptedTaskPolicy::RETRY) ? TaskStatus::WAITING : TaskStatus::DEAD;
        SmokeTest::assertSame($expected, $recovered->getStatus(), "the status with {$policy->value}");
        assert(str_contains((string)$recovered->getError(), "Interrupted: worker"), "The interruption is recorded.");
        SmokeTest::assertSame(null, $recovered->getResultData(), "the ignored result");
    }
};

$smoke->run("worker: a task whose lease ran out is retried or failed, by its policy, ignoring its result", $lostLease);

$extendLease = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $task = $queue->enqueue(Fixtures::createTask('long', options: new TaskOptions(leaseSeconds: 60)));
    $store = new \Programster\PgsqlTaskQueue\Internal\TaskStore(TestDatabase::get(), $clock, TestDatabase::SCHEMA);
    $queues = new QueueNameCollection(new QueueName(Fixtures::QUEUE));
    $claimed = $store->claimNext($queues, [\Programster\PgsqlTaskQueue\Testing\TestTask::class], 'me');
    assert($claimed !== null && $claimed->getTask()->getId() === $task->getId(), "It's claimed.");
    $context = new \Programster\PgsqlTaskQueue\TaskContext($store, $claimed);
    assert($context->isLastAttempt() && $context->getAttempt() === 1 && $context->getWorker() === 'me');

    $clock->advance(50);
    $context->extendLease(600);
    $clock->advance(500);
    SmokeTest::assertSame(0, $queue->recoverExpiredLeases(), "tasks recovered within the extended lease");

    $extendTooFar = function () use ($context) : void {
        $context->extendLease(86401);
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $extendTooFar);
    $clock->advance(101);
    SmokeTest::assertSame(1, $queue->recoverExpiredLeases(), "tasks recovered after the extended lease ran out");

    $extendLost = function () use ($context) : void {
        $context->extendLease(60);
    };

    SmokeTest::expectException(\Programster\PgsqlTaskQueue\Exceptions\ExceptionLeaseLost::class, $extendLost);
};

$smoke->run("worker: a handler can extend its task's lease, until it has lost it", $extendLease);

$stopConditions = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);

    for ($i = 1; $i <= 3; $i++)
    {
        $queue->enqueue(Fixtures::createTask("task {$i}"));
    }

    $log = new HandledLog();
    $reason = Fixtures::createWorker($queue, $clock, $log, maxTasks: 2)->run();
    SmokeTest::assertSame(WorkerStopReason::TASK_LIMIT, $reason, "why it stopped");
    SmokeTest::assertSame(2, count($log->getEntries()), "tasks run");

    $batch = $queue->createBatch('run');
    $queue->enqueue(Fixtures::createTask('in batch'), $batch);
    $handler = new TestTaskHandler($log, $clock, $queue, TestDatabase::get());

    $worker = $queue->createWorker(
        new TaskHandlerCollection($handler),
        new QueueNameCollection(new QueueName(Fixtures::QUEUE)),
        stopConditions: new WorkerStopConditions(untilBatchFinished: $batch),
        pollSeconds: 0.05
    );

    SmokeTest::assertSame(WorkerStopReason::BATCH_FINISHED, $worker->run(), "why it stopped, with a batch");

    $worker = $queue->createWorker(
        new TaskHandlerCollection($handler),
        new QueueNameCollection(new QueueName(Fixtures::QUEUE)),
        stopConditions: new WorkerStopConditions(maxSeconds: 1),
        pollSeconds: 0.05
    );

    $startedAt = microtime(true);
    SmokeTest::assertSame(WorkerStopReason::TIME_LIMIT, $worker->run(), "why it stopped, with a time limit");
    assert(microtime(true) - $startedAt < 2, "It stopped soon after its time limit.");

    $worker = $queue->createWorker(
        new TaskHandlerCollection($handler),
        new QueueNameCollection(new QueueName(Fixtures::QUEUE)),
        stopConditions: new WorkerStopConditions(maxMemoryBytes: 1),
        pollSeconds: 0.05
    );

    SmokeTest::assertSame(WorkerStopReason::MEMORY_LIMIT, $worker->run(), "why it stopped, with a memory limit");
};

$smoke->run("worker: it stops after its task, time or memory limit, or once its batch has finished", $stopConditions);

$signalStops = function () : void {
    if (!function_exists('pcntl_async_signals') || !function_exists('posix_kill'))
    {
        throw new \Exception("This test needs the pcntl and posix extensions.");
    }

    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $queue->enqueue(Fixtures::createTask('stopper', TestTaskBehaviour::STOP_WORKER));
    $queue->enqueue(Fixtures::createTask('after'));
    $handler = new TestTaskHandler(new HandledLog(), $clock, $queue, TestDatabase::get());

    $worker = $queue->createWorker(
        new TaskHandlerCollection($handler),
        new QueueNameCollection(new QueueName(Fixtures::QUEUE)),
        pollSeconds: 0.05
    );

    $before = pcntl_signal_get_handler(SIGTERM);
    SmokeTest::assertSame(WorkerStopReason::STOPPED, $worker->run(), "why it stopped");
    SmokeTest::assertSame(TaskStatus::SUCCEEDED, $queue->fetchTask(1)->getStatus(), "the task it was on");
    SmokeTest::assertSame(TaskStatus::WAITING, $queue->fetchTask(2)->getStatus(), "the next task");
    SmokeTest::assertSame($before, pcntl_signal_get_handler(SIGTERM), "the SIGTERM handler, put back");
};

$smoke->run("worker: SIGTERM stops it once it has finished the task it's on", $signalStops);

$badWorkers = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $handler = new TestTaskHandler(new HandledLog(), $clock, $queue, TestDatabase::get());
    $queues = new QueueNameCollection(new QueueName(Fixtures::QUEUE));

    $badCreations = [
        'no handlers' => function () use ($queue, $queues) : void {
            $queue->createWorker(new TaskHandlerCollection(), $queues);
        },
        'no queues' => function () use ($queue, $handler) : void {
            $queue->createWorker(new TaskHandlerCollection($handler), new QueueNameCollection());
        },
        'two handlers for one class' => function () use ($queue, $handler, $queues) : void {
            $queue->createWorker(new TaskHandlerCollection($handler, $handler), $queues);
        },
        'a poll time of 0' => function () use ($queue, $handler, $queues) : void {
            $queue->createWorker(new TaskHandlerCollection($handler), $queues, pollSeconds: 0);
        },
    ];

    foreach ($badCreations as $what => $create)
    {
        SmokeTest::expectException(\InvalidArgumentException::class, $create);
    }
};

$smoke->run("worker: one can't be created without handlers or queues, or with two handlers for a class", $badWorkers);

$otherClassesAndQueues = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $inOtherQueue = new \Programster\PgsqlTaskQueue\NewTask(
        new \Programster\PgsqlTaskQueue\Testing\TestTask('elsewhere'),
        new QueueName('other')
    );

    $queue->enqueue($inOtherQueue);
    $log = new HandledLog();
    Fixtures::createWorker($queue, $clock, $log)->run();
    SmokeTest::assertSame([], $log->getEntries(), "tasks run from a queue the worker doesn't take from");
};

$smoke->run("worker: it only takes tasks from its own queues", $otherClassesAndQueues);

$deleteOld = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $batch = $queue->createBatch('old run');
    $queue->enqueue(Fixtures::createTask('old'), $batch);
    $queue->enqueue(Fixtures::createTask('dies', TestTaskBehaviour::FAIL_PERMANENTLY));
    Fixtures::createWorker($queue, $clock, new HandledLog())->run();
    $clock->advance(86400);
    $queue->enqueue(Fixtures::createTask('unfinished'));
    $clock->advance(86400);
    $queue->enqueue(Fixtures::createTask('recent'));
    Fixtures::createWorker($queue, $clock, new HandledLog(), maxTasks: 1)->run();

    SmokeTest::assertSame(2, $queue->deleteFinishedBefore($clock->now()->modify('-1 hour')), "tasks deleted");
    $remaining = TestDatabase::get()->query("SELECT count(*) FROM task_queue_test.task");
    SmokeTest::assertSame('2', pg_fetch_result($remaining, 0, 0), "tasks left");
    $batches = TestDatabase::get()->query("SELECT count(*) FROM task_queue_test.batch");
    SmokeTest::assertSame('0', pg_fetch_result($batches, 0, 0), "batches left");
};

$smoke->run("worker: finished tasks older than a time can be deleted, with their empty batches", $deleteOld);
