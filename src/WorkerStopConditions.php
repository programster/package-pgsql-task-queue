<?php

/*
 * When a worker stops by itself, rather than running until it's told to stop (SIGTERM, SIGINT or Worker::stop()),
 * e.g. new WorkerStopConditions(maxSeconds: 3600, maxMemoryBytes: 128 * 1024 * 1024), so a process manager (e.g.
 * supervisord) starts a fresh one every so often, or new WorkerStopConditions(untilBatchFinished: $batch) for a test
 * run, or new WorkerStopConditions(stopWhenIdle: true) to run what's due (e.g. from cron) and exit. A worker only
 * checks them between tasks, so it always finishes the task it's on first.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class WorkerStopConditions
{
    private ?int $m_maxSeconds;
    private ?int $m_maxMemoryBytes;
    private ?int $m_maxTasks;
    private ?Batch $m_untilBatchFinished;
    private bool $m_stopWhenIdle;


    /**
     * @param int|null $maxSeconds - stop once it has run for this long (by the real time, not the queue's clock).
     * Defaults to null, for no limit.
     *
     * @param int|null $maxMemoryBytes - stop once PHP is using more than this much memory. Defaults to null, for no
     * limit.
     *
     * @param int|null $maxTasks - stop once it has run this many tasks. Defaults to null, for no limit.
     *
     * @param Batch|null $untilBatchFinished - stop once every task in this batch has finished. Defaults to null.
     *
     * @param bool $stopWhenIdle - stop as soon as there's nothing it can claim, rather than waiting for more. Defaults
     * to false.
     *
     * @throws \InvalidArgumentException - if a limit is less than 1.
     */
    public function __construct(
        ?int $maxSeconds = null,
        ?int $maxMemoryBytes = null,
        ?int $maxTasks = null,
        ?Batch $untilBatchFinished = null,
        bool $stopWhenIdle = false
    )
    {
        $limits = ['maxSeconds' => $maxSeconds, 'maxMemoryBytes' => $maxMemoryBytes, 'maxTasks' => $maxTasks];

        foreach ($limits as $name => $limit)
        {
            if ($limit !== null && $limit < 1)
            {
                throw new \InvalidArgumentException("A worker's {$name} must be null or at least 1, not {$limit}.");
            }
        }

        $this->m_maxSeconds = $maxSeconds;
        $this->m_maxMemoryBytes = $maxMemoryBytes;
        $this->m_maxTasks = $maxTasks;
        $this->m_untilBatchFinished = $untilBatchFinished;
        $this->m_stopWhenIdle = $stopWhenIdle;
    }


    # Accessors
    public function getMaxSeconds() : ?int { return $this->m_maxSeconds; }
    public function getMaxMemoryBytes() : ?int { return $this->m_maxMemoryBytes; }
    public function getMaxTasks() : ?int { return $this->m_maxTasks; }
    public function getUntilBatchFinished() : ?Batch { return $this->m_untilBatchFinished; }
    public function getStopWhenIdle() : bool { return $this->m_stopWhenIdle; }
}
