<?php

/*
 * Thrown by a task's handler when the task failed, and trying again can't help (e.g. the email address doesn't
 * exist), so it dies straight away, however many attempts it has left, and the tasks that depend on it are skipped.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Exceptions;


final class ExceptionTaskFailedPermanently extends \RuntimeException
{
}
