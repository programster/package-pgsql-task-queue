<?php

/*
 * Thrown when a task isn't in the queue: there's no task with the ID asked for (e.g. it has been handled, which
 * removes it), or a task to enqueue depends on one that isn't there.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Exceptions;


final class ExceptionTaskNotFound extends \RuntimeException
{
}
