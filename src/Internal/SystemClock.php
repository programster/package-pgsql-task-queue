<?php

/*
 * The real time, in UTC, for a TaskQueue that isn't given a clock.
 *
 * @internal
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Internal;


use Psr\Clock\ClockInterface;


final readonly class SystemClock implements ClockInterface
{
    public function now() : \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
