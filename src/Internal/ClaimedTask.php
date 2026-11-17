<?php

/*
 * A task a worker has claimed, with what the worker needs to finish it: who holds it (so a result from a worker whose
 * lease ran out isn't kept), and how it's retried.
 *
 * @internal
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Internal;


use Programster\PgsqlTaskQueue\QueuedTask;


final readonly class ClaimedTask
{
    private QueuedTask $m_task;
    private string $m_worker;

    /** @var list<int> */
    private array $m_retryDelaysInSeconds;


    /**
     * @param QueuedTask $task - the task, as it was claimed.
     * @param string $worker - the worker that claimed it.
     * @param list<int> $retryDelaysInSeconds - its RetrySchedule's delays.
     */
    public function __construct(QueuedTask $task, string $worker, array $retryDelaysInSeconds)
    {
        $this->m_task = $task;
        $this->m_worker = $worker;
        $this->m_retryDelaysInSeconds = $retryDelaysInSeconds;
    }


    /**
     * Find out whether a temporary failure on this attempt would be retried.
     * @return bool
     */
    public function hasAttemptLeft() : bool
    {
        return ($this->m_task->getAttempts() < $this->m_task->getMaxAttempts());
    }


    /**
     * Get how long to wait before retrying after this attempt, if it has one left (hasAttemptLeft()).
     * @return int
     * @throws \LogicException - if it hasn't.
     */
    public function getRetryDelayInSeconds() : int
    {
        $attempt = $this->m_task->getAttempts();

        if (!isset($this->m_retryDelaysInSeconds[$attempt - 1]))
        {
            throw new \LogicException("Task {$this->m_task->getId()} has no retry after attempt {$attempt}.");
        }

        return $this->m_retryDelaysInSeconds[$attempt - 1];
    }


    # Accessors
    public function getTask() : QueuedTask { return $this->m_task; }
    public function getWorker() : string { return $this->m_worker; }
}
