<?php

/*
 * How a task is run: its priority, how it's retried, what happens if its worker stops partway through it, how long
 * its lease is, and its name, deduplication key and earliest start. Every option has a safe default, so a task only
 * needs the ones it changes, e.g. new TaskOptions(priority: 10, name: "renewal notices").
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class TaskOptions
{
    // PostgreSQL's INT range, which the priority is stored in.
    private const MIN_PRIORITY = -2147483648;
    private const MAX_PRIORITY = 2147483647;

    // A day, as a task that needs longer should extend its lease as it goes (TaskContext::extendLease()).
    private const MAX_LEASE_SECONDS = 86400;

    private const MAX_TEXT_LENGTH = 1000;

    private int $m_priority;
    private RetrySchedule $m_retrySchedule;
    private InterruptedTaskPolicy $m_onInterruption;
    private int $m_leaseSeconds;
    private ?string $m_name;
    private ?string $m_deduplicationKey;
    private ?\DateTimeImmutable $m_notBefore;


    /**
     * @param int $priority - tasks with a higher priority are claimed first. Defaults to 0.
     *
     * @param RetrySchedule|null $retrySchedule - how a temporary failure is retried. Defaults to null, which never
     * retries.
     *
     * @param InterruptedTaskPolicy $onInterruption - what happens if its worker stops partway through it. Defaults to
     * FAIL, the safe choice for a task that mustn't happen twice. Use RETRY for one that's safe to run again.
     *
     * @param int $leaseSeconds - how long a worker has to finish it (or extend its lease) before it's treated as
     * interrupted. Defaults to 300 (5 minutes). From 1 to 86400 (a day).
     *
     * @param string|null $name - a name for it, shown in summaries, e.g. a test's name. Tasks in the same queue with
     * the same name are expected to take as long as the last one that succeeded, and the longest are claimed first
     * among those with the same priority, so a batch finishes sooner. Defaults to null.
     *
     * @param string|null $deduplicationKey - while a task with this key is unfinished in the same queue, enqueuing
     * another with it adds nothing, and returns the one already there, e.g. "renewal-notice:{subscription id}".
     * Defaults to null, for no deduplication.
     *
     * @param \DateTimeImmutable|null $notBefore - the earliest it may start. Defaults to null, for straight away.
     *
     * @throws \InvalidArgumentException - if the priority, lease, name or key is out of range.
     */
    public function __construct(
        int $priority = 0,
        ?RetrySchedule $retrySchedule = null,
        InterruptedTaskPolicy $onInterruption = InterruptedTaskPolicy::FAIL,
        int $leaseSeconds = 300,
        ?string $name = null,
        ?string $deduplicationKey = null,
        ?\DateTimeImmutable $notBefore = null
    )
    {
        if ($priority < self::MIN_PRIORITY || $priority > self::MAX_PRIORITY)
        {
            throw new \InvalidArgumentException("The priority must fit in a 32 bit integer, not {$priority}.");
        }

        if ($leaseSeconds < 1 || $leaseSeconds > self::MAX_LEASE_SECONDS)
        {
            $max = self::MAX_LEASE_SECONDS;
            throw new \InvalidArgumentException("The lease must be from 1 to {$max} seconds, not {$leaseSeconds}.");
        }

        self::assertValidText($name, "name");
        self::assertValidText($deduplicationKey, "deduplication key");
        $this->m_priority = $priority;
        $this->m_retrySchedule = $retrySchedule ?? RetrySchedule::createNever();
        $this->m_onInterruption = $onInterruption;
        $this->m_leaseSeconds = $leaseSeconds;
        $this->m_name = $name;
        $this->m_deduplicationKey = $deduplicationKey;
        $this->m_notBefore = $notBefore;
    }


    /**
     * Check an optional piece of text isn't empty or too long, as an empty name or key is almost certainly a mistake
     * (e.g. an unset variable), and would deduplicate unrelated tasks.
     *
     * @param string|null $text
     *
     * @param string $what - what it is, for the message.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertValidText(?string $text, string $what) : void
    {
        if ($text !== null && ($text === '' || mb_strlen($text) > self::MAX_TEXT_LENGTH || str_contains($text, "\0")))
        {
            $max = self::MAX_TEXT_LENGTH;

            throw new \InvalidArgumentException(
                "A task's {$what} must be null, or 1 to {$max} characters with no NUL bytes, not \"{$text}\"."
            );
        }
    }


    # Accessors
    public function getPriority() : int { return $this->m_priority; }
    public function getRetrySchedule() : RetrySchedule { return $this->m_retrySchedule; }
    public function getOnInterruption() : InterruptedTaskPolicy { return $this->m_onInterruption; }
    public function getLeaseSeconds() : int { return $this->m_leaseSeconds; }
    public function getName() : ?string { return $this->m_name; }
    public function getDeduplicationKey() : ?string { return $this->m_deduplicationKey; }
    public function getNotBefore() : ?\DateTimeImmutable { return $this->m_notBefore; }
}
