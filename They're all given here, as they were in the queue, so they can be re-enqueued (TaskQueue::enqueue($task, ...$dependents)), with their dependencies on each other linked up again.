<?php

/*
 * Thrown by Worker::runNext() when a task failed, as its handler threw (which is this exception's previous one). The
 * task has been removed from the queue, and so have the tasks that depended on it whose policy is to be removed with
 * it (DependencyFailurePolicy::REMOVE), directly or through others.
 *
 * They're all given here, as they were in the queue, so they can be re-enqueued (TaskQueue::enqueue($task,
 * ...$dependents)), with their dependencies on each other linked up again.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Exceptions;


use Programster\PgsqlTaskQueue\Task;
use Programster\PgsqlTaskQueue\TaskCollection;


final class ExceptionTaskFailed extends \RuntimeException
{
    private readonly Task $m_task;
    private readonly TaskCollection $m_dependents;


    /**
     * @param Task $task - the task that failed.
     * @param TaskCollection $dependents - the tasks removed with it.
     * @param \Throwable $cause - why it failed.
     */
    public function __construct(Task $task, TaskCollection $dependents, \Throwable $cause)
    {
        $removed = $dependents->count();
        $message = "Task {$task->getId()} ({$task->getType()}) failed, removing {$removed} that depended on it: "
            . $cause->getMessage();

        parent::__construct($message, 0, $cause);
        $this->m_task = $task;
        $this->m_dependents = $dependents;
    }


    # Accessors
    public function getTask() : Task { return $this->m_task; }
    public function getDependents() : TaskCollection { return $this->m_dependents; }
}
