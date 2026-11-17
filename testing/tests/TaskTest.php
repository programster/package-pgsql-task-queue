<?php

/*
 * Tests of creating a task (Task::create()): what it keeps, and the mistakes it rejects where they're made.
 */

use Programster\PgsqlTaskQueue\DependencyFailurePolicy;
use Programster\PgsqlTaskQueue\Task;
use Programster\PgsqlTaskQueue\TaskIdCollection;
use Programster\PgsqlTaskQueue\Testing\SmokeTest;

/** @var SmokeTest $smoke */


$defaults = function () : void {
    $data = new class implements JsonSerializable {
        /** @return array<string, mixed> */
        public function jsonSerialize() : array
        {
            return ['to' => 'jo@example.com', 'when' => 1.5];
        }
    };

    $task = Task::create('send-email', $data);
    SmokeTest::assertSame('send-email', $task->getType(), "the type");
    $expected = ['to' => 'jo@example.com', 'when' => 1.5];
    SmokeTest::assertSame($expected, $task->getData(), "the data, from a JsonSerializable");
    SmokeTest::assertSame('default', $task->getQueue(), "the default queue");
    SmokeTest::assertSame(0, $task->getPriority(), "the default priority");
    SmokeTest::assertSame(null, $task->getGroup(), "the default group");
    SmokeTest::assertSame(null, $task->getAvailableAt(), "the default start");
    SmokeTest::assertSame(300, $task->getLeaseSeconds(), "the default lease");
    SmokeTest::assertSame(DependencyFailurePolicy::REMOVE, $task->getOnDependencyFailure(), "the default policy");
    assert(!$task->isQueued(), "A new task isn't queued.");

    foreach (['getId', 'getStatus', 'getCreatedAt'] as $method)
    {
        $call = function () use ($task, $method) : void {
            $task->$method();
        };

        SmokeTest::expectException(\LogicException::class, $call, "enqueued");
    }
};

$smoke->run("task: a new task keeps what it's given, with safe defaults, and has no ID yet", $defaults);

$rejected = function () : void {
    $singleValue = new class implements JsonSerializable {
        public function jsonSerialize() : string
        {
            return 'just a string';
        }
    };

    $bad = [
        'an empty type' => function () : void { Task::create('', []); },
        'a blank queue' => function () : void { Task::create('a', [], queue: ' '); },
        'an empty group' => function () : void { Task::create('a', [], group: ''); },
        'a long type' => function () : void { Task::create(str_repeat('a', 201), []); },
        'a big priority' => function () : void { Task::create('a', [], priority: 2147483648); },
        'no lease' => function () : void { Task::create('a', [], leaseSeconds: 0); },
        'a long lease' => function () : void { Task::create('a', [], leaseSeconds: 86401); },
        'data that isn\'t JSON' => function () : void { Task::create('a', ['bad' => NAN]); },
        'a single value' => function () use ($singleValue) : void { Task::create('a', $singleValue); },
        'an ID that isn\'t a UUID' => function () : void { new TaskIdCollection('task-1'); },
    ];

    foreach ($bad as $what => $create)
    {
        try
        {
            $create();
            throw new \Exception("A task with {$what} was accepted.");
        }
        catch (\InvalidArgumentException)
        {
            // Expected.
        }
    }
};

$smoke->run("task: empty or long names, numbers out of range, bad data and bad IDs are rejected", $rejected);
