<?php

/*
 * Tests of the queue (TaskQueue): enqueueing tasks (with dependencies, in any order, and inside the caller's
 * transaction), fetching them, groups, and deleting them.
 */

use Programster\PgsqlTaskQueue\DependencyFailurePolicy;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskNotFound;
use Programster\PgsqlTaskQueue\Task;
use Programster\PgsqlTaskQueue\TaskCollection;
use Programster\PgsqlTaskQueue\TaskIdCollection;
use Programster\PgsqlTaskQueue\TaskQueue;
use Programster\PgsqlTaskQueue\TaskStatus;
use Programster\PgsqlTaskQueue\Testing\FakeClock;
use Programster\PgsqlTaskQueue\Testing\SmokeTest;
use Programster\PgsqlTaskQueue\Testing\TestDatabase;
use Programster\PgsqlTaskQueue\Testing\TestHandler;

/**
 * Create a queue on the emptied test database.
 * @param FakeClock $clock
 * @return TaskQueue
 */
function queueTestCreateQueue(FakeClock $clock) : TaskQueue
{
    TestDatabase::reset();
    return new TaskQueue(TestDatabase::get(), $clock, TestDatabase::SCHEMA);
}


/** @var SmokeTest $smoke */

$getLabel = function (Task $task) : string {
    return TestHandler::getLabel($task);
};

$enqueued = function () use ($getLabel) : void {
    $clock = new FakeClock();
    $queue = queueTestCreateQueue($clock);
    $first = TestHandler::createTask('first', group: 'run-1', priority: 3);
    $added = $queue->enqueue($first)->first();

    assert($added->isQueued() && !$first->isQueued(), "The task returned is queued, and the one given isn't.");
    SmokeTest::assertSame(TaskStatus::WAITING, $added->getStatus(), "a task with no dependencies' status");
    SmokeTest::assertSame('first', $getLabel($added), "its data");
    SmokeTest::assertSame(3, $added->getPriority(), "its priority");
    SmokeTest::assertSame('run-1', $added->getGroup(), "its group");
    assert($added->getCreatedAt() == $clock->now(), "Its created time comes from the queue's clock.");
    assert($added->getAvailableAt() == $clock->now(), "It's available straight away.");

    $uuidV7Pattern = '#^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$#';
    $isUuidV7 = (preg_match($uuidV7Pattern, $added->getId()) === 1);
    assert($isUuidV7, "Its ID is a UUID v7, not \"{$added->getId()}\".");
    $fetched = $queue->fetch($added->getId());
    SmokeTest::assertSame('first', $getLabel($fetched), "the fetched task's data");

    foreach (['0190a1b2-0000-7000-8000-000000000000', 'not-a-uuid'] as $missingId)
    {
        $fetchMissing = function () use ($queue, $missingId) : void {
            $queue->fetch($missingId);
        };

        SmokeTest::expectException(ExceptionTaskNotFound::class, $fetchMissing);
    }
};

$smoke->run("queue: an enqueued task has an ID and a status, and can be fetched again", $enqueued);

$dependenciesFirst = function () use ($getLabel) : void {
    $queue = queueTestCreateQueue(new FakeClock());
    $a = TestHandler::createTask('a');
    $b = TestHandler::createTask('b', dependsOn: new TaskCollection($a));
    $c = TestHandler::createTask('c', dependsOn: new TaskCollection($a, $b, $a));

    // Given with the dependencies last, but they're added first, and returned in the order given.
    $added = $queue->enqueue($c, $b, $a);
    SmokeTest::assertSame(['c', 'b', 'a'], $added->map($getLabel), "the order they're returned in");
    [$addedC, $addedB, $addedA] = $added->toArray();
    SmokeTest::assertSame(TaskStatus::WAITING, $addedA->getStatus(), "a's status");
    SmokeTest::assertSame(TaskStatus::BLOCKED, $addedC->getStatus(), "c's status");
    SmokeTest::assertSame([$addedA->getId()], $addedB->getDependsOnIds()->toArray(), "b's dependencies");
    $cDependsOn = [$addedA->getId(), $addedB->getId()];
    SmokeTest::assertSame($cDependsOn, $addedC->getDependsOnIds()->toArray(), "c's dependencies");
    $fetchedC = $queue->fetch($addedC->getId());
    SmokeTest::assertSame($cDependsOn, $fetchedC->getDependsOnIds()->toArray(), "c's, fetched");

    // A task enqueued later can depend on one already in the queue.
    $d = $queue->enqueue(TestHandler::createTask('d', dependsOn: new TaskCollection($addedC)))->first();
    SmokeTest::assertSame([$addedC->getId()], $d->getDependsOnIds()->toArray(), "d's dependency on a queued task");
    SmokeTest::assertSame(TaskStatus::BLOCKED, $queue->fetch($d->getId())->getStatus(), "d's status");

    // Or on tasks already in the queue by their IDs, given in any case.
    $byIds = new TaskIdCollection(strtoupper($addedA->getId()), $d->getId(), $addedA->getId());
    $e = $queue->enqueue(TestHandler::createTask('e', dependsOn: $byIds))->first();
    SmokeTest::assertSame([$addedA->getId(), $d->getId()], $e->getDependsOnIds()->toArray(), "e's dependencies");
};

$smoke->run("queue: tasks are linked to the tasks they depend on, whatever order they're given in", $dependenciesFirst);

$missingDependencies = function () : void {
    $queue = queueTestCreateQueue(new FakeClock());
    $notEnqueued = TestHandler::createTask('not enqueued');

    $enqueueWithout = function () use ($queue, $notEnqueued) : void {
        $queue->enqueue(TestHandler::createTask('b', dependsOn: new TaskCollection($notEnqueued)));
    };

    SmokeTest::expectException(\LogicException::class, $enqueueWithout, "Enqueue them together");
    SmokeTest::assertSame(0, TestDatabase::countTasks(), "the tasks added");

    $gone = $queue->enqueue(TestHandler::createTask('gone'))->first();
    $queue->delete($gone);

    $enqueueOnGone = function () use ($queue, $gone) : void {
        $queue->enqueue(TestHandler::createTask('c', dependsOn: new TaskCollection($gone)));
    };

    SmokeTest::expectException(ExceptionTaskNotFound::class, $enqueueOnGone);
    SmokeTest::assertSame(0, TestDatabase::countTasks(), "the tasks added");

    $enqueueOnGoneId = function () use ($queue, $gone) : void {
        $queue->enqueue(TestHandler::createTask('c', dependsOn: new TaskIdCollection($gone->getId())));
    };

    SmokeTest::expectException(ExceptionTaskNotFound::class, $enqueueOnGoneId);
    SmokeTest::assertSame(0, TestDatabase::countTasks(), "the tasks added by its ID");

    // A whole collection is added atomically: one task depending on a task that's gone adds none of them.
    $first = TestHandler::createTask('first');
    $second = TestHandler::createTask('second', dependsOn: new TaskCollection($first));
    $onGone = TestHandler::createTask('on gone', dependsOn: new TaskCollection($gone));

    $enqueueCollection = function () use ($queue, $first, $second, $onGone) : void {
        $queue->enqueueAll(new TaskCollection($first, $second, $onGone));
    };

    SmokeTest::expectException(ExceptionTaskNotFound::class, $enqueueCollection);
    SmokeTest::assertSame(0, TestDatabase::countTasks(), "the tasks added from the collection");
    $added = $queue->enqueueAll(new TaskCollection($second, $first));
    $secondDependsOn = $added->get(0)->getDependsOnIds()->toArray();
    SmokeTest::assertSame([$added->get(1)->getId()], $secondDependsOn, "second's dependency");
};

$smoke->run("queue: depending on a task that isn't queued, or has gone, adds nothing", $missingDependencies);

$inCallersTransaction = function () : void {
    $queue = queueTestCreateQueue(new FakeClock());
    $database = TestDatabase::get();

    $database->query('BEGIN');
    $queue->enqueue(TestHandler::createTask('rolled back'));
    assert(pg_transaction_status($database->getResource()) === PGSQL_TRANSACTION_INTRANS, "It's still open.");
    $database->query('ROLLBACK');
    SmokeTest::assertSame(0, TestDatabase::countTasks(), "the tasks after the caller rolled back");

    // A failed enqueue leaves the caller's transaction usable.
    $database->query('BEGIN');
    $queue->enqueue(TestHandler::createTask('kept'));
    $notEnqueued = TestHandler::createTask('not enqueued');

    $failingEnqueue = function () use ($queue, $notEnqueued) : void {
        $queue->enqueue(TestHandler::createTask('b', dependsOn: new TaskCollection($notEnqueued)));
    };

    SmokeTest::expectException(\LogicException::class, $failingEnqueue);
    $database->query('COMMIT');
    SmokeTest::assertSame(1, TestDatabase::countTasks(), "the tasks after the caller committed");
};

$smoke->run("queue: tasks enqueued in the caller's transaction are only kept if it commits", $inCallersTransaction);

$groupsAndDelete = function () use ($getLabel) : void {
    $queue = queueTestCreateQueue(new FakeClock());
    $a = TestHandler::createTask('a', group: 'run-1');
    $keeps = TestHandler::createTask('keeps', group: 'run-1', dependsOn: new TaskCollection($a));

    $continues = TestHandler::createTask(
        'continues',
        group: 'run-1',
        dependsOn: new TaskCollection($a),
        onDependencyFailure: DependencyFailurePolicy::CONTINUE
    );

    $queue->enqueue($a, $keeps, $continues, TestHandler::createTask('elsewhere', group: 'run-2'));
    $inGroup = $queue->fetchGroup('run-1')->map($getLabel);
    SmokeTest::assertSame(['a', 'keeps', 'continues'], $inGroup, "run-1's tasks");
    SmokeTest::assertSame(0, $queue->fetchGroup('run-3')->count(), "an empty group's tasks");

    // Deleting a removes the task that needs it, and releases the one that doesn't.
    $removed = $queue->delete($queue->fetchGroup('run-1')->first());
    SmokeTest::assertSame(['keeps'], $removed->map($getLabel), "the tasks removed with it");
    $left = $queue->fetchGroup('run-1');
    SmokeTest::assertSame(['continues'], $left->map($getLabel), "the tasks left");
    SmokeTest::assertSame(TaskStatus::WAITING, $left->first()->getStatus(), "the released task's status");

    $deleteAgain = function () use ($queue, $removed) : void {
        $queue->delete($removed->first());
    };

    SmokeTest::expectException(ExceptionTaskNotFound::class, $deleteAgain);
};

$smoke->run("queue: a group's tasks can be fetched, and deleting one follows its dependents' policy", $groupsAndDelete);

$withData = function () use ($getLabel) : void {
    $queue = queueTestCreateQueue(new FakeClock());
    $chaser = ['label' => 'chaser', 'reference' => ['subscriptionId' => 12, 'templateId' => 3], 'tags' => ['a', 'b']];
    $other = ['label' => 'other', 'reference' => ['subscriptionId' => 13, 'templateId' => 3], 'tags' => ['b']];
    $queue->enqueue(Task::create('email', $chaser), Task::create('email', $other), Task::create('job', $chaser));
    $queue->enqueue(Task::create('no-data', []), Task::create('list', ['a', 'b']));

    $find = function (string $type, array $data) use ($queue, $getLabel) : array {
        return $queue->fetchWithData($type, $data)->map($getLabel);
    };

    SmokeTest::assertSame(['chaser'], $find('email', ['reference' => ['subscriptionId' => 12]]), "by a nested key");
    SmokeTest::assertSame(['chaser', 'other'], $find('email', ['tags' => ['b']]), "by a list element");
    SmokeTest::assertSame(['chaser', 'other'], $find('email', []), "every task of the type");
    SmokeTest::assertSame(['chaser'], $find('job', []), "another type");
    SmokeTest::assertSame([], $find('email', ['reference' => ['subscriptionId' => '12']]), "a value of another type");
    SmokeTest::assertSame([], $find('missing', []), "a type with no tasks");

    // Every task of a type, even one whose data is empty, or a list, which {} wouldn't contain.
    SmokeTest::assertSame(1, $queue->fetchWithData('no-data', [])->count(), "the tasks with no data");
    SmokeTest::assertSame(1, $queue->fetchWithData('list', [])->count(), "the tasks whose data is a list");
    SmokeTest::assertSame(1, $queue->fetchWithData('list', ['b'])->count(), "the lists containing an element");

    $badData = function () use ($queue) : void {
        $queue->fetchWithData('email', ['bad' => NAN]);
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $badData, "JSON");
};

$smoke->run("queue: tasks can be found by what their data contains", $withData);
