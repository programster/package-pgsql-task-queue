<?php

/*
 * Thrown when a NewTask depends on another NewTask that isn't being enqueued in the same enqueueAll() call, so it has
 * no ID to depend on. Enqueue them together, or depend on the QueuedTask that enqueuing the other one returned.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Exceptions;


final class ExceptionDependencyNotQueued extends \LogicException
{
}
