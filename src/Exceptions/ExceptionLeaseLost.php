<?php

/*
 * Thrown when a worker tries to extend the lease of a task it no longer holds, because the lease ran out and the task
 * was treated as interrupted (and may now be running elsewhere). The handler should stop, as its result won't be kept.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Exceptions;


final class ExceptionLeaseLost extends \RuntimeException
{
}
