<?php

/*
 * What a handler is told about the attempt it's running, with a way to extend the task's lease, for a task that may
 * take longer than its lease (TaskOptions' leaseSeconds). A worker gives one to the handler of each task it runs.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\PgsqlTaskQueue\Internal\ClaimedTask;
use Programster\PgsqlTaskQueue\Internal\TaskStore;


final readonly class TaskContext
{
    private TaskStore $m_store;
    private ClaimedTask $m_claimed;


    /**
     * @internal - a Worker creates one for each task it runs.
     * @param TaskStore $store
     * @param ClaimedTask $claimed
     */
    public function __construct(TaskStore $store, ClaimedTask $claimed)
    {
        $this->m_store = $store;
        $this->m_claimed = $claimed;
    }


    /**
     * Extend the task's lease to the given number of seconds from now, so it isn't treated as interrupted while it's
     * still running. Call it every so often in a long task, e.g. after each item it works through.
     *
     * @param int $seconds - from 1 to 86400 (a day).
     *
     * @return void
     *
     * @throws \InvalidArgumentException - if the seconds are out of range.
     *
     * @throws Exceptions\ExceptionLeaseLost - if the lease had already run out, so the task may be running elsewhere,
     * and this attempt's result won't be kept. The handler should stop.
     */
    public function extendLease(int $seconds) : void
    {
        $this->m_store->extendLease($this->m_claimed, $seconds);
    }


    /**
     * Find out whether this is the task's last attempt, so a temporary failure won't be retried.
     * @return bool
     */
    public function isLastAttempt() : bool
    {
        return !$this->m_claimed->hasAttemptLeft();
    }


    # Accessors
    public function getTask() : QueuedTask { return $this->m_claimed->getTask(); }
    public function getAttempt() : int { return $this->m_claimed->getTask()->getAttempts(); }
    public function getWorker() : string { return $this->m_claimed->getWorker(); }
}
