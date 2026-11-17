<?php

/*
 * How a batch's tasks stand, e.g. for printing a test run's results: how many have each status, whether it has
 * finished, and its tasks by status or by how long they took.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class BatchSummary
{
    private Batch $m_batch;
    private QueuedTaskCollection $m_tasks;


    /**
     * @param Batch $batch - the batch.
     * @param QueuedTaskCollection $tasks - every task in it.
     */
    public function __construct(Batch $batch, QueuedTaskCollection $tasks)
    {
        $this->m_batch = $batch;
        $this->m_tasks = $tasks;
    }


    /**
     * Count the tasks with a status.
     * @param TaskStatus $status
     * @return int
     */
    public function countWithStatus(TaskStatus $status) : int
    {
        return $this->getWithStatus($status)->count();
    }


    /**
     * Get the tasks with any of the statuses, in the order they were added.
     * @param TaskStatus ...$statuses
     * @return QueuedTaskCollection
     */
    public function getWithStatus(TaskStatus ...$statuses) : QueuedTaskCollection
    {
        $hasStatus = function (QueuedTask $task) use ($statuses) : bool {
            return in_array($task->getStatus(), $statuses, true);
        };

        return $this->m_tasks->filter($hasStatus);
    }


    /**
     * Find out whether every task in the batch has finished (succeeded, died or been skipped). A batch with no tasks
     * has finished.
     *
     * @return bool
     */
    public function isFinished() : bool
    {
        return ($this->getWithStatus(TaskStatus::BLOCKED, TaskStatus::WAITING, TaskStatus::RUNNING)->count() === 0);
    }


    /**
     * Find out whether every task in the batch succeeded, which is true of a batch with no tasks.
     * @return bool
     */
    public function isAllSucceeded() : bool
    {
        return ($this->countWithStatus(TaskStatus::SUCCEEDED) === $this->m_tasks->count());
    }


    /**
     * Get the tasks that took longest to run, longest first (not counting ones that never ran).
     * @param int $limit - how many to get, at most.
     * @return QueuedTaskCollection
     */
    public function getSlowest(int $limit) : QueuedTaskCollection
    {
        $hasRun = function (QueuedTask $task) : bool {
            return ($task->getDurationMs() !== null);
        };

        $bySlowestFirst = function (QueuedTask $a, QueuedTask $b) : int {
            return $b->getDurationMs() <=> $a->getDurationMs();
        };

        $slowest = $this->m_tasks->filter($hasRun)->toArray();
        usort($slowest, $bySlowestFirst);
        return new QueuedTaskCollection(...array_slice($slowest, 0, max(0, $limit)));
    }


    # Accessors
    public function getBatch() : Batch { return $this->m_batch; }
    public function getTasks() : QueuedTaskCollection { return $this->m_tasks; }
}
