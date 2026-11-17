<?php

/*
 * Thrown when there's no batch with the ID asked for, e.g. it has been deleted (TaskQueue::deleteFinishedBefore()).
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Exceptions;


final class ExceptionBatchNotFound extends \RuntimeException
{
}
