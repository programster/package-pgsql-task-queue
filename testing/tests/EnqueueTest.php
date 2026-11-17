<?php

/*
 * Tests of adding tasks: their statuses with and without dependencies, the order enqueueAll() adds them in,
 * deduplication, dependencies that can't be resolved, batches, and fetching tasks back.
 */

use Programster\PgsqlTaskQueue\Exceptions\ExceptionBatchNotFound;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionDependencyNotQueued;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskNotFound;
use Programster\PgsqlTaskQueue\NewTask;
use Programster\PgsqlTaskQueue\NewTaskCollection;
use Programster\PgsqlTaskQueue\QueuedTask;
use Programster\PgsqlTaskQueue\QueueName;
use Programster\PgsqlTaskQueue\TaskOptions;
use Programster\PgsqlTaskQueue\TaskStatus;
use Programster\PgsqlTaskQueue\Testing\FakeClock;
use Programster\PgsqlTaskQueue\Testing\Fixtures;
use Programster\PgsqlTaskQueue\Testing\SmokeTest;
use Programster\PgsqlTaskQueue\Testing\TestDatabase;
use Programster\PgsqlTaskQueue\Testing\TestTask;
use Programster\PgsqlTaskQueue\Testing\TestTaskBehaviour;

/** @var SmokeTest $smoke */


$getLabel = function (QueuedTask $task) : string {
    $recreated = $task->getTask();
    assert($recreated instanceof TestTask);
    return $recreated->getLabel();
};

$getId = function (QueuedTask $task) : int {
    return $task->getId();
};

$statuses = function () use ($getLabel) : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $first = $queue->enqueue(Fixtures::createTask('first', options: new TaskOptions(name: 'the first')));
    $second = $queue->enqueue(Fixtures::createTask('second', TestTaskBehaviour::SUCCEED, new TaskOptions(), $first));

    assert($first->getStatus() === TaskStatus::WAITING, "A task with no dependencies is waiting.");
    assert($second->getStatus() === TaskStatus::BLOCKED, "A task with an unfinished dependency is blocked.");
    SmokeTest::assertSame('the first', $first->getName(), "the task's name");
    SmokeTest::assertSame(0, $first->getAttempts(), "attempts before it runs");
    // It's stored as JSONB, which doesn't keep the order of an object's keys.
    $payload = $first->getPayload();
    ksort($payload);
    SmokeTest::assertSame(['behaviour' => 'succeed', 'label' => 'first', 'sleepMs' => 0], $payload, "the payload");
    SmokeTest::assertSame('first', $getLabel($first), "the recreated task's label");
    assert($first->getCreatedAt() == $clock->now(), "Its creation time comes from the queue's clock.");
    SmokeTest::assertSame('second', $getLabel($queue->fetchTask($second->getId())), "the task fetched again");
};

$smoke->run("enqueue: a task waits, unless it has an unfinished dependency, which blocks it", $statuses);

$dependenciesFirst = function () use ($getLabel, $getId) : void {
    $queue = Fixtures::createQueue(new FakeClock());
    $a = Fixtures::createTask('a');
    $b = Fixtures::createTask('b', TestTaskBehaviour::SUCCEED, new TaskOptions(), $a);
    $c = Fixtures::createTask('c', TestTaskBehaviour::SUCCEED, new TaskOptions(), $a, $b);

    // Given with the dependencies last, but they're added first, and returned in the order given.
    $queued = $queue->enqueueAll(new NewTaskCollection($c, $b, $a));
    SmokeTest::assertSame(['c', 'b', 'a'], $queued->map($getLabel), "the order they're returned in");
    $ids = $queued->map($getId);
    assert($ids[2] < $ids[1] && $ids[1] < $ids[0], "Each is added after its dependencies.");
    SmokeTest::assertSame(TaskStatus::WAITING, $queued->get(2)->getStatus(), "a's status");
    SmokeTest::assertSame(TaskStatus::BLOCKED, $queued->get(0)->getStatus(), "c's status");
};

$smoke->run("enqueue: enqueueAll() adds each task after the tasks it depends on, in any order", $dependenciesFirst);

$dependencyNotQueued = function () : void {
    $queue = Fixtures::createQueue(new FakeClock());
    $a = Fixtures::createTask('a');
    $queue->enqueue($a);
    $b = Fixtures::createTask('b', TestTaskBehaviour::SUCCEED, new TaskOptions(), $a);

    $enqueueB = function () use ($queue, $b) : void {
        $queue->enqueue($b);
    };

    SmokeTest::expectException(ExceptionDependencyNotQueued::class, $enqueueB, "QueuedTask");

    // Nothing of a failed enqueueAll() is added.
    $c = Fixtures::createTask('c');

    $enqueueBoth = function () use ($queue, $b, $c) : void {
        $queue->enqueueAll(new NewTaskCollection($c, $b));
    };

    SmokeTest::expectException(ExceptionDependencyNotQueued::class, $enqueueBoth);
    $countResult = TestDatabase::get()->query("SELECT count(*) FROM task_queue_test.task");
    SmokeTest::assertSame('1', pg_fetch_result($countResult, 0, 0), "tasks in the queue");
};

$smoke->run("enqueue: depending on a NewTask not enqueued with it is rejected, adding nothing", $dependencyNotQueued);

$dependencyDeleted = function () : void {
    $queue = Fixtures::createQueue(new FakeClock());
    $a = $queue->enqueue(Fixtures::createTask('a'));
    TestDatabase::get()->query("DELETE FROM task_queue_test.task");

    $enqueue = function () use ($queue, $a) : void {
        $queue->enqueue(Fixtures::createTask('b', TestTaskBehaviour::SUCCEED, new TaskOptions(), $a));
    };

    SmokeTest::expectException(ExceptionTaskNotFound::class, $enqueue);
};

$smoke->run("enqueue: depending on a task that no longer exists is rejected", $dependencyDeleted);

$deduplication = function () use ($getLabel) : void {
    $queue = Fixtures::createQueue(new FakeClock());
    $options = new TaskOptions(deduplicationKey: 'notice:1');
    $first = $queue->enqueue(Fixtures::createTask('first', options: $options));
    $second = $queue->enqueue(Fixtures::createTask('second', options: $options));
    SmokeTest::assertSame($first->getId(), $second->getId(), "a duplicate's ID");
    SmokeTest::assertSame('first', $getLabel($second), "the task returned for a duplicate");

    // A task in another queue, or with no key, isn't a duplicate.
    $inOtherQueue = new NewTask(new TestTask('other'), new QueueName('other'), $options);
    assert($queue->enqueue($inOtherQueue)->getId() !== $first->getId(), "Another queue's task isn't a duplicate.");
    assert($queue->enqueue(Fixtures::createTask('no key'))->getId() !== $first->getId(), "No key isn't either.");
};

$smoke->run("enqueue: a task with the key of an unfinished task in its queue isn't added again", $deduplication);

$notBefore = function () : void {
    $clock = new FakeClock();
    $queue = Fixtures::createQueue($clock);
    $later = $clock->now()->modify('+1 day');
    $task = $queue->enqueue(Fixtures::createTask('later', options: new TaskOptions(notBefore: $later)));
    assert($task->getAvailableAt() == $later, "It's available from the time given.");
};

$smoke->run("enqueue: a task can be held back until a time", $notBefore);

$batches = function () : void {
    $queue = Fixtures::createQueue(new FakeClock());
    $batch = $queue->createBatch('run 1');
    SmokeTest::assertSame('run 1', $queue->fetchBatch($batch->getId())->getName(), "the batch's name");
    $queue->enqueueAll(new NewTaskCollection(Fixtures::createTask('a'), Fixtures::createTask('b')), $batch);
    $queue->enqueue(Fixtures::createTask('not in it'));
    $summary = $queue->fetchBatchSummary($batch);
    SmokeTest::assertSame(2, $summary->getTasks()->count(), "tasks in the batch");
    SmokeTest::assertSame(2, $summary->countWithStatus(TaskStatus::WAITING), "waiting tasks in the batch");
    assert(!$summary->isFinished() && !$summary->isAllSucceeded(), "The batch hasn't finished.");

    $fetchMissing = function () use ($queue) : void {
        $queue->fetchBatch(999);
    };

    SmokeTest::expectException(ExceptionBatchNotFound::class, $fetchMissing);

    $createUnnamed = function () use ($queue) : void {
        $queue->createBatch('');
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $createUnnamed);
};

$smoke->run("enqueue: tasks can be put in a batch, and summarised together", $batches);

$fetching = function () use ($getLabel) : void {
    $queue = Fixtures::createQueue(new FakeClock());

    $fetchMissing = function () use ($queue) : void {
        $queue->fetchTask(999);
    };

    SmokeTest::expectException(ExceptionTaskNotFound::class, $fetchMissing);

    for ($i = 1; $i <= 5; $i++)
    {
        $queue->enqueue(Fixtures::createTask("task {$i}"));
    }

    $page = $queue->fetchPage(TaskStatus::WAITING, new QueueName(Fixtures::QUEUE), 2, 1);
    SmokeTest::assertSame(['task 4', 'task 3'], $page->map($getLabel), "the second page of two, newest first");

    $badPage = function () use ($queue) : void {
        $queue->fetchPage(TaskStatus::WAITING, new QueueName(Fixtures::QUEUE), 0);
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $badPage);
};

$smoke->run("enqueue: tasks can be fetched by ID, and a page at a time by status", $fetching);

$inCallersTransaction = function () : void {
    $queue = Fixtures::createQueue(new FakeClock());
    $database = TestDatabase::get();

    // Rolled back by the caller, so the task never existed.
    $database->query('BEGIN');
    $queue->enqueue(Fixtures::createTask('rolled back'));
    assert(pg_transaction_status($database->getResource()) === PGSQL_TRANSACTION_INTRANS, "It's still open.");
    $database->query('ROLLBACK');
    $count = $database->query("SELECT count(*) FROM task_queue_test.task");
    SmokeTest::assertSame('0', pg_fetch_result($count, 0, 0), "tasks after the caller rolled back");

    // A failed enqueue leaves the caller's transaction usable.
    $database->query('BEGIN');
    $queue->enqueue(Fixtures::createTask('kept'));

    $notQueued = Fixtures::createTask('a');

    $failingEnqueue = function () use ($queue, $notQueued) : void {
        $queue->enqueue(Fixtures::createTask('b', TestTaskBehaviour::SUCCEED, new TaskOptions(), $notQueued));
    };

    SmokeTest::expectException(ExceptionDependencyNotQueued::class, $failingEnqueue);
    $database->query('COMMIT');
    $count = $database->query("SELECT count(*) FROM task_queue_test.task");
    SmokeTest::assertSame('1', pg_fetch_result($count, 0, 0), "tasks after the caller committed");
};

$smoke->run("enqueue: a task added in the caller's transaction is kept only if it commits", $inCallersTransaction);
