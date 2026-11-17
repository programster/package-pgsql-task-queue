<?php

/*
 * Thrown by Worker::runNext() when it found tasks whose lease had run out, as their workers must have stopped partway
 * through them (e.g. they were killed). Each has been removed from the queue, with the tasks that depended on it and
 * couldn't continue without it. Those that could continue were left in the queue, to run.
 *
 * They're all given here (getExpiredTasks()), so the application can log them, check how far they got, or re-enqueue
 * them. Nothing else was claimed or run, so call runNext() again to carry on.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Exceptions;


use Programster\PgsqlTaskQueue\ExpiredTask;
use Programster\PgsqlTaskQueue\ExpiredTaskCollection;


final class ExceptionTasksExpired extends \RuntimeException
{
    private readonly ExpiredTaskCollection $m_expiredTasks;


    /**
     * @param ExpiredTaskCollection $expiredTasks - the tasks whose lease ran out, each with its dependents removed.
     */
    public function __construct(ExpiredTaskCollection $expiredTasks)
    {
        $describe = function (ExpiredTask $expired) : string {
            $task = $expired->getTask();
            $removed = $expired->getDependents()->count();
            return "{$task->getId()} ({$task->getType()}, removing {$removed} that depended on it)";
        };

        $message = "The leases of {$expiredTasks->count()} tasks ran out, as their workers must have stopped partway"
            . " through them, so they've been removed: " . implode(', ', $expiredTasks->map($describe)) . ".";

        parent::__construct($message);
        $this->m_expiredTasks = $expiredTasks;
    }


    # Accessors
    public function getExpiredTasks() : ExpiredTaskCollection { return $this->m_expiredTasks; }
}
