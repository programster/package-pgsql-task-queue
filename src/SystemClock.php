<?php

/*
 * The real time, in UTC, for a TaskQueue in an application that doesn't have a PSR-20 clock of its own.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Psr\Clock\ClockInterface;


final readonly class SystemClock implements ClockInterface
{
    public function now() : \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
