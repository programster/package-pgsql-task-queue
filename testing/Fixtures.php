<?php

/*
 * Shortcuts for the tests: a queue with a fake clock on the test database, new test tasks, and a worker that runs
 * them in this process.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Testing;


use Programster\PgsqlTaskQueue\NewTask;
use Programster\PgsqlTaskQueue\QueuedTask;
use Programster\PgsqlTaskQueue\QueueName;
use Programster\PgsqlTaskQueue\QueueNameCollection;
use Programster\PgsqlTaskQueue\TaskHandlerCollection;
use Programster\PgsqlTaskQueue\TaskOptions;
use Programster\PgsqlTaskQueue\TaskQueue;
use Programster\PgsqlTaskQueue\Worker;
use Programster\PgsqlTaskQueue\WorkerStopConditions;


final readonly class Fixtures
{
    public const QUEUE = 'tests';


    /**
     * Create a queue on the emptied test database, with a fake clock.
     * @param FakeClock $clock
     * @return TaskQueue
     */
    public static function createQueue(FakeClock $clock) : TaskQueue
    {
        TestDatabase::reset();
        return new TaskQueue(TestDatabase::get(), $clock, TestDatabase::SCHEMA);
    }


    /**
     * Create a test task for the tests queue.
     * @param string $label
     * @param TestTaskBehaviour $behaviour
     * @param TaskOptions $options
     * @param NewTask|QueuedTask ...$dependencies
     * @return NewTask
     */
    public static function createTask(
        string $label,
        TestTaskBehaviour $behaviour = TestTaskBehaviour::SUCCEED,
        TaskOptions $options = new TaskOptions(),
        NewTask|QueuedTask ...$dependencies
    ) : NewTask
    {
        return new NewTask(new TestTask($label, $behaviour), new QueueName(self::QUEUE), $options, ...$dependencies);
    }


    /**
     * Create a worker in this process, which stops as soon as it has nothing to claim.
     * @param TaskQueue $queue
     * @param FakeClock $clock
     * @param HandledLog $log
     * @param RecordingLogger $logger
     * @param int $maxTasks - the most tasks it runs before it stops.
     * @return Worker
     */
    public static function createWorker(
        TaskQueue $queue,
        FakeClock $clock,
        HandledLog $log,
        RecordingLogger $logger = new RecordingLogger(),
        int $maxTasks = 1000
    ) : Worker
    {
        $handler = new TestTaskHandler($log, $clock, $queue, TestDatabase::get());

        return $queue->createWorker(
            new TaskHandlerCollection($handler),
            new QueueNameCollection(new QueueName(self::QUEUE)),
            $logger,
            new WorkerStopConditions(maxTasks: $maxTasks, stopWhenIdle: true),
            0.05
        );
    }
}
