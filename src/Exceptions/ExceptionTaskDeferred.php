<?php

/*
 * Thrown by a task's handler when the task can't run yet, but will be able to later (e.g. a rate limit has been
 * reached), so it goes back in the queue until then, without using up an attempt.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Exceptions;


final class ExceptionTaskDeferred extends \RuntimeException
{
    private readonly \DateTimeImmutable $m_until;


    /**
     * @param \DateTimeImmutable $until - the earliest it may be tried again.
     * @param string $reason - why it can't run yet, which is stored with the task.
     */
    public function __construct(\DateTimeImmutable $until, string $reason)
    {
        parent::__construct($reason);
        $this->m_until = $until;
    }


    # Accessors
    public function getUntil() : \DateTimeImmutable { return $this->m_until; }
}
