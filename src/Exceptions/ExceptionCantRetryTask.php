<?php

/*
 * Thrown by TaskQueue::retry() for a task that hasn't died, as only a dead task can be retried. A skipped task is
 * retried by retrying the task it depends on that died, which un-skips it.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Exceptions;


final class ExceptionCantRetryTask extends \LogicException
{
}
