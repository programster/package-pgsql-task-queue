<?php

/*
 * Tests of a worker running tasks in this process, with a fake clock: success, releasing and ordering, failures and
 * the dependency policies, re-enqueueing what failed, expired and extended leases, and what a worker rejects.
 */

use Programster\PgsqlTaskQueue\DependencyFailurePolicy;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskFailed;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskNotFound;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTasksExpired;
use Programster\PgsqlTaskQueue\Task;
use Programster\PgsqlTaskQueue\TaskCollection;
use Programster\PgsqlTaskQueue\TaskQueue;
use Programster\PgsqlTaskQueue\TaskStatus;
use Programster\PgsqlTaskQueue\Testing\FakeClock;
use Programster\PgsqlTaskQueue\Testing\SmokeTest;
use Programster\PgsqlTaskQueue\Testing\TestDatabase;
use Programster\PgsqlTaskQueue\Testing\TestHandler;
use Programster\PgsqlTaskQueue\Worker;

/**
 * Run a worker until there's nothing it can run, collecting the failures it throws.
 * @param Worker $worker
 * @return list<ExceptionTaskFailed>
 */
function workerTestRunAll(Worker $worker) : array
{
    $failures = [];
    $isIdle = false;

    while (!$isIdle)
    {
        try
        {
            $isIdle = !$worker->runNext();
        }
        catch (ExceptionTaskFailed $failure)
        {
            $failures[] = $failure;
        }
    }

    return $failures;
}


/** @var SmokeTest $smoke */

$getLabel = function (Task $task) : string {
    return TestHandler::getLabel($task);
};

$runsInOrder = function () : void {
    $clock = new FakeClock();
    TestDatabase::reset();
    $queue = new TaskQueue(TestDatabase::get(), $clock, TestDatabase::SCHEMA);
    $a = TestHandler::createTask('a');
    $b = TestHandler::createTask('b', priority: 10);
    $c = TestHandler::createTask('c', dependsOn: new TaskCollection($a, $b));
    $later = TestHandler::createTask('later', notBefore: $clock->now()->modify('+1 hour'));
    $elsewhere = TestHandler::createTask('elsewhere', queue: 'other');
    $otherType = Task::create('other-type', [], 'tests');
    $queue->enqueue($c, $a, $b, $later, $elsewhere, $otherType);
    $handler = new TestHandler(TestDatabase::get());
    $worker = new Worker($queue, ['tests'], $handler);

    SmokeTest::assertSame([], workerTestRunAll($worker), "the failures");
    SmokeTest::assertSame(['b', 'a', 'c'], $handler->getHandled(), "what ran, in order: priority, then oldest");
    SmokeTest::assertSame(3, TestDatabase::countTasks(), "the tasks left: later, elsewhere and the other type");

    // Once it's due.
    $clock->advance(3600);
    workerTestRunAll($worker);
    SmokeTest::assertSame(['b', 'a', 'c', 'later'], $handler->getHandled(), "what ran once later was due");
};

$smoke->run("worker: runs each task once its dependencies have finished, by priority, and removes it", $runsInOrder);

$failurePolicies = function () use ($getLabel) : void {
    TestDatabase::reset();
    $queue = new TaskQueue(TestDatabase::get(), new FakeClock(), TestDatabase::SCHEMA);
    $fails = TestHandler::createTask('fails', 'fail');
    $removed = TestHandler::createTask('removed', dependsOn: new TaskCollection($fails));
    $removedToo = TestHandler::createTask('removed too', dependsOn: new TaskCollection($removed));

    $continues = TestHandler::createTask(
        'continues',
        dependsOn: new TaskCollection($fails),
        onDependencyFailure: DependencyFailurePolicy::CONTINUE
    );

    $afterRemoved = TestHandler::createTask(
        'continues after removed',
        dependsOn: new TaskCollection($removed),
        onDependencyFailure: DependencyFailurePolicy::CONTINUE
    );

    $queue->enqueue($fails, $removed, $removedToo, $continues, $afterRemoved);
    $handler = new TestHandler(TestDatabase::get());
    $failures = workerTestRunAll(new Worker($queue, ['tests'], $handler));

    SmokeTest::assertSame(1, count($failures), "the failures");
    $failure = $failures[0];
    SmokeTest::assertSame('fails', $getLabel($failure->getTask()), "the task that failed");
    SmokeTest::assertSame("fails failed.", $failure->getPrevious()?->getMessage(), "the cause");
    SmokeTest::assertSame(['removed', 'removed too'], $failure->getDependents()->map($getLabel), "those removed");
    SmokeTest::assertSame(['fails', 'continues', 'continues after removed'], $handler->getHandled(), "what ran");
    SmokeTest::assertSame(0, TestDatabase::countTasks(), "the tasks left");
};

$smoke->run("worker: a failure removes REMOVE dependents (in turn), and releases CONTINUE ones", $failurePolicies);

$reEnqueue = function () use ($getLabel) : void {
    TestDatabase::reset();
    $queue = new TaskQueue(TestDatabase::get(), new FakeClock(), TestDatabase::SCHEMA);
    $flaky = TestHandler::createTask('flaky', 'fail');
    $dependent = TestHandler::createTask('dependent', dependsOn: new TaskCollection($flaky));
    $dependentToo = TestHandler::createTask('dependent too', dependsOn: new TaskCollection($dependent));
    $queue->enqueue($flaky, $dependent, $dependentToo);
    $worker = new Worker($queue, ['tests'], new TestHandler(TestDatabase::get()));
    $failure = workerTestRunAll($worker)[0];

    // Put back as they were, given in any order, with the links among them kept, to their new IDs.
    $again = $queue->enqueue(...[...$failure->getDependents()->toArray(), $failure->getTask()]);
    [$againDependent, $againDependentToo, $againFlaky] = $again->toArray();
    assert($againFlaky->getId() !== $failure->getTask()->getId(), "It's added afresh, with a new ID.");
    $link = $againDependent->getDependsOnIds()->toArray();
    SmokeTest::assertSame([$againFlaky->getId()], $link, "the dependent's link");
    $nextLink = $againDependentToo->getDependsOnIds()->toArray();
    SmokeTest::assertSame([$againDependent->getId()], $nextLink, "the next one's link");
    SmokeTest::assertSame(TaskStatus::BLOCKED, $againDependentToo->getStatus(), "its status");

    // It fails again, taking them with it again.
    $failures = workerTestRunAll($worker);
    SmokeTest::assertSame(1, count($failures), "the failures the second time");
    $removed = $failures[0]->getDependents()->map($getLabel);
    SmokeTest::assertSame(['dependent', 'dependent too'], $removed, "those removed the second time");

    // Re-enqueueing a dependent without the task it waited for, which is gone, is rejected.
    $enqueueAlone = function () use ($queue, $failures) : void {
        $queue->enqueue($failures[0]->getDependents()->first());
    };

    SmokeTest::expectException(ExceptionTaskNotFound::class, $enqueueAlone);
};

$smoke->run("worker: what failed can be re-enqueued as it is, with its dependents' links kept", $reEnqueue);

$expiredLeases = function () use ($getLabel) : void {
    $clock = new FakeClock();
    TestDatabase::reset();
    $queue = new TaskQueue(TestDatabase::get(), $clock, TestDatabase::SCHEMA);
    $slow = TestHandler::createTask('slow', leaseSeconds: 60);
    $dependent = TestHandler::createTask('dependent', dependsOn: new TaskCollection($slow));
    $dependentToo = TestHandler::createTask('dependent too', dependsOn: new TaskCollection($dependent));

    $continues = TestHandler::createTask(
        'continues',
        dependsOn: new TaskCollection($slow),
        onDependencyFailure: DependencyFailurePolicy::CONTINUE
    );

    $alsoSlow = TestHandler::createTask('also slow', leaseSeconds: 60);
    $added = $queue->enqueue($slow, $dependent, $dependentToo, $continues, $alsoSlow);

    // As if two workers claimed them and stopped, without saying how they went.
    $store = $queue->getStore();
    $claimed = $store->claimNext(['tests'], [TestHandler::TYPE], 'gone');
    SmokeTest::assertSame(1, $claimed->count(), "the tasks claimed");
    $slowTask = $claimed->first();
    SmokeTest::assertSame($added->first()->getId(), $slowTask->getId(), "the task claimed");
    $alsoSlowTask = $store->claimNext(['tests'], [TestHandler::TYPE], 'gone too')->first();
    SmokeTest::assertSame('also slow', $getLabel($alsoSlowTask), "the second task claimed");
    SmokeTest::assertSame(TaskStatus::RUNNING, $queue->fetch($slowTask->getId())->getStatus(), "its status");

    // Extending a lease keeps it going.
    $clock->advance(50);
    $queue->extendLease($slowTask, 600);
    $queue->extendLease($alsoSlowTask, 600);
    $clock->advance(500);
    $handler = new TestHandler(TestDatabase::get());
    $worker = new Worker($queue, ['tests'], $handler);
    SmokeTest::assertSame(false, $worker->runNext(), "whether there was anything to run, within the leases");

    // Once they've run out, both are removed with their dependents, and thrown together.
    $clock->advance(101);

    try
    {
        $worker->runNext();
        throw new \Exception("The expired tasks should have been thrown.");
    }
    catch (ExceptionTasksExpired $expiry)
    {
        $expired = $expiry->getExpiredTasks();
        SmokeTest::assertSame(2, $expired->count(), "the tasks expired");
        SmokeTest::assertSame('slow', $getLabel($expired->get(0)->getTask()), "the first expired task");
        $removed = $expired->get(0)->getDependents()->map($getLabel);
        SmokeTest::assertSame(['dependent', 'dependent too'], $removed, "those removed with it");
        SmokeTest::assertSame('also slow', $getLabel($expired->get(1)->getTask()), "the second expired task");
        SmokeTest::assertSame(0, $expired->get(1)->getDependents()->count(), "those removed with it");
    }

    // Nothing ran then. The one that continues anyway was left in the queue, and runs next.
    SmokeTest::assertSame([], $handler->getHandled(), "what ran while they were removed");
    SmokeTest::assertSame(true, $worker->runNext(), "whether the released task ran");
    SmokeTest::assertSame(['continues'], $handler->getHandled(), "what ran");
    SmokeTest::assertSame(0, TestDatabase::countTasks(), "the tasks left");

    // They can be put back as they were, with their links kept.
    $first = $expiry->getExpiredTasks()->get(0);
    $again = $queue->enqueue($first->getTask(), ...$first->getDependents()->toArray());
    $link = $again->get(1)->getDependsOnIds()->toArray();
    SmokeTest::assertSame([$again->get(0)->getId()], $link, "the dependent's link");

    $extendGone = function () use ($queue, $slowTask) : void {
        $queue->extendLease($slowTask, 60);
    };

    SmokeTest::expectException(ExceptionTaskNotFound::class, $extendGone);
};

$smoke->run("worker: tasks whose leases ran out are removed with their dependents, and thrown", $expiredLeases);

$badWorkers = function () : void {
    TestDatabase::reset();
    $queue = new TaskQueue(TestDatabase::get(), new FakeClock(), TestDatabase::SCHEMA);
    $handler = new TestHandler(TestDatabase::get());

    $badQueues = [[], [''], [' '], [1]];

    foreach ($badQueues as $queues)
    {
        $create = function () use ($queue, $queues, $handler) : void {
            new Worker($queue, $queues, $handler);
        };

        SmokeTest::expectException(\InvalidArgumentException::class, $create);
    }

    $noHandlers = function () use ($queue) : void {
        new Worker($queue, ['tests']);
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $noHandlers);

    $twoForAType = function () use ($queue, $handler) : void {
        new Worker($queue, ['tests'], $handler, new TestHandler(TestDatabase::get()));
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $twoForAType, "Two handlers");
};

$smoke->run("worker: one needs queues and handlers, and one handler for each type", $badWorkers);
