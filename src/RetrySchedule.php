<?php

/*
 * How often, and after how long, a task that fails is tried again, e.g. after 1 minute, then 5, then 30. A task gets
 * one attempt, plus one for each delay. Only a temporary failure is retried: a handler that throws
 * ExceptionTaskFailedPermanently fails its task straight away, however many attempts it has left.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class RetrySchedule
{
    /** @var list<int> */
    private array $m_delaysInSeconds;


    /**
     * @param list<int> $delaysInSeconds
     */
    private function __construct(array $delaysInSeconds)
    {
        $this->m_delaysInSeconds = $delaysInSeconds;
    }


    /**
     * Create a schedule that never retries, so a task gets one attempt.
     * @return RetrySchedule
     */
    public static function createNever() : RetrySchedule
    {
        return new RetrySchedule([]);
    }


    /**
     * Create a schedule that retries after each of the delays in turn, e.g. createUsingDelaysInSeconds(60, 300, 1800)
     * retries 1 minute after the first failure, 5 minutes after the second and 30 minutes after the third, so the task
     * gets 4 attempts.
     *
     * @param int ...$delaysInSeconds - how long to wait before each retry, each at least 1.
     *
     * @return RetrySchedule
     *
     * @throws \InvalidArgumentException - if none are given (use createNever()), or one is less than 1.
     */
    public static function createUsingDelaysInSeconds(int ...$delaysInSeconds) : RetrySchedule
    {
        if (count($delaysInSeconds) === 0)
        {
            throw new \InvalidArgumentException("Give at least one delay, or use RetrySchedule::createNever().");
        }

        foreach ($delaysInSeconds as $delay)
        {
            if ($delay < 1)
            {
                throw new \InvalidArgumentException("Each retry delay must be at least 1 second, not {$delay}.");
            }
        }

        return new RetrySchedule(array_values($delaysInSeconds));
    }


    /**
     * Get how many attempts a task gets in all: the first, and one for each retry.
     * @return int
     */
    public function getMaxAttempts() : int
    {
        return count($this->m_delaysInSeconds) + 1;
    }


    # Accessors
    /** @return list<int> */
    public function getDelaysInSeconds() : array { return $this->m_delaysInSeconds; }
}
