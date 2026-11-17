<?php

/*
 * Tests of the value objects that check what they're given, so a mistake fails where it's made: queue names, retry
 * schedules, task options, new tasks, task results and worker stop conditions.
 */

use Programster\PgsqlTaskQueue\InterruptedTaskPolicy;
use Programster\PgsqlTaskQueue\NewTask;
use Programster\PgsqlTaskQueue\QueueName;
use Programster\PgsqlTaskQueue\RetrySchedule;
use Programster\PgsqlTaskQueue\TaskInterface;
use Programster\PgsqlTaskQueue\TaskOptions;
use Programster\PgsqlTaskQueue\TaskResult;
use Programster\PgsqlTaskQueue\TaskStatus;
use Programster\PgsqlTaskQueue\Testing\SmokeTest;
use Programster\PgsqlTaskQueue\Testing\TestTask;
use Programster\PgsqlTaskQueue\WorkerStopConditions;

/** @var SmokeTest $smoke */


$queueNames = function () : void {
    SmokeTest::assertSame('emails', (new QueueName('emails'))->getName(), "a queue's name");
    new QueueName('a.b-c_1');
    new QueueName(str_repeat('a', 100));

    foreach (['', 'Emails', '-emails', 'e mails', str_repeat('a', 101), "a\0"] as $badName)
    {
        $create = function () use ($badName) : void {
            new QueueName($badName);
        };

        SmokeTest::expectException(\InvalidArgumentException::class, $create, "queue name");
    }
};

$smoke->run("values: a queue name must be lowercase letters, digits, underscores, dots and dashes", $queueNames);

$retrySchedules = function () : void {
    SmokeTest::assertSame(1, RetrySchedule::createNever()->getMaxAttempts(), "a never-retrying schedule's attempts");
    $schedule = RetrySchedule::createUsingDelaysInSeconds(60, 300);
    SmokeTest::assertSame(3, $schedule->getMaxAttempts(), "attempts with two retries");
    SmokeTest::assertSame([60, 300], $schedule->getDelaysInSeconds(), "the delays");

    $noDelays = function () : void {
        RetrySchedule::createUsingDelaysInSeconds();
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $noDelays, "createNever");

    $zeroDelay = function () : void {
        RetrySchedule::createUsingDelaysInSeconds(60, 0);
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $zeroDelay, "at least 1 second");
};

$smoke->run("values: a retry schedule needs at least one delay, each of at least a second", $retrySchedules);

$taskOptions = function () : void {
    $defaults = new TaskOptions();
    SmokeTest::assertSame(0, $defaults->getPriority(), "the default priority");
    SmokeTest::assertSame(1, $defaults->getRetrySchedule()->getMaxAttempts(), "the default attempts");
    assert($defaults->getOnInterruption() === InterruptedTaskPolicy::FAIL, "Interrupted tasks fail by default.");
    SmokeTest::assertSame(300, $defaults->getLeaseSeconds(), "the default lease");

    $badOptions = [
        'lease' => function () : void { new TaskOptions(leaseSeconds: 0); },
        'long lease' => function () : void { new TaskOptions(leaseSeconds: 86401); },
        'empty name' => function () : void { new TaskOptions(name: ''); },
        'empty key' => function () : void { new TaskOptions(deduplicationKey: ''); },
        'long name' => function () : void { new TaskOptions(name: str_repeat('a', 1001)); },
        'priority' => function () : void { new TaskOptions(priority: 2147483648); },
    ];

    foreach ($badOptions as $what => $create)
    {
        try
        {
            $create();
            throw new \Exception("A bad {$what} was accepted.");
        }
        catch (\InvalidArgumentException)
        {
            // Expected.
        }
    }
};

$smoke->run("values: task options default safely, and reject bad leases, names, keys and priorities", $taskOptions);

$newTaskRoundTrips = function () : void {
    $task = new NewTask(new TestTask('a'), new QueueName('tests'));
    SmokeTest::assertSame(0, $task->getNewDependencies()->count(), "dependencies");

    $unencodable = new class implements TaskInterface {
        public static function createFromArray(array $data) : static
        {
            return new static();
        }

        public function jsonSerialize() : array
        {
            return ['bad' => NAN];
        }
    };

    $createUnencodable = function () use ($unencodable) : void {
        new NewTask($unencodable, new QueueName('tests'));
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $createUnencodable, "encoded as JSON");

    $unrecreatable = new class implements TaskInterface {
        public static function createFromArray(array $data) : static
        {
            throw new \RuntimeException("missing field");
        }

        public function jsonSerialize() : array
        {
            return [];
        }
    };

    $createUnrecreatable = function () use ($unrecreatable) : void {
        new NewTask($unrecreatable, new QueueName('tests'));
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $createUnrecreatable, "missing field");
};

$smoke->run("values: a new task must survive being stored as JSON and recreated, or it's rejected", $newTaskRoundTrips);

$duplicateDependencies = function () : void {
    $a = new NewTask(new TestTask('a'), new QueueName('tests'));
    $b = new NewTask(new TestTask('b'), new QueueName('tests'), new TaskOptions(), $a, $a);
    SmokeTest::assertSame(1, $b->getNewDependencies()->count(), "a dependency given twice");
};

$smoke->run("values: a dependency given twice counts once", $duplicateDependencies);

$taskResults = function () : void {
    $result = new TaskResult(['a' => 1], 'output');
    SmokeTest::assertSame(['a' => 1], $result->getData(), "the result data");

    $createBad = function () : void {
        new TaskResult(['bad' => INF]);
    };

    SmokeTest::expectException(\InvalidArgumentException::class, $createBad, "JSON");
};

$smoke->run("values: a task result's data must be encodable as JSON", $taskResults);

$stopConditions = function () : void {
    new WorkerStopConditions();
    new WorkerStopConditions(maxSeconds: 1, maxMemoryBytes: 1, maxTasks: 1);

    foreach (['maxSeconds', 'maxMemoryBytes', 'maxTasks'] as $name)
    {
        $create = function () use ($name) : void {
            new WorkerStopConditions(...[$name => 0]);
        };

        SmokeTest::expectException(\InvalidArgumentException::class, $create, $name);
    }
};

$smoke->run("values: a worker's limits must be at least 1", $stopConditions);

$statuses = function () : void {
    $finished = [TaskStatus::SUCCEEDED, TaskStatus::DEAD, TaskStatus::SKIPPED];

    foreach (TaskStatus::cases() as $status)
    {
        SmokeTest::assertSame(in_array($status, $finished, true), $status->isFinished(), "{$status->value} finished");
    }
};

$smoke->run("values: succeeded, dead and skipped are the finished statuses", $statuses);
