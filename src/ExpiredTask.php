<?php

/*
 * A task whose lease ran out (its worker must have stopped partway through it), which has been removed from the queue,
 * with the tasks that were removed because they depended on it (directly, or through others) and couldn't continue
 * without it (DependencyFailurePolicy::REMOVE). Those that could continue (CONTINUE) were left in the queue, to run.
 *
 * Given by ExceptionTasksExpired. The task and its dependents can be re-enqueued together, as they are
 * (TaskQueue::enqueue($expired->getTask(), ...$expired->getDependents())), with their links kept, if it's safe to run
 * the task again, as it may have been partly done.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class ExpiredTask
{
    private Task $m_task;
    private TaskCollection $m_dependents;


    /**
     * @param Task $task - the task whose lease ran out, as it was in the queue.
     * @param TaskCollection $dependents - the tasks removed because they depended on it.
     */
    public function __construct(Task $task, TaskCollection $dependents)
    {
        $this->m_task = $task;
        $this->m_dependents = $dependents;
    }


    # Accessors
    public function getTask() : Task { return $this->m_task; }
    public function getDependents() : TaskCollection { return $this->m_dependents; }
}
