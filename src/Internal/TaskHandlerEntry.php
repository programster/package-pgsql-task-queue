<?php

/*
 * A worker's handler, under the type of task it handles, for TaskHandlerMap.
 *
 * @internal
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Internal;


use Programster\Collections\AbstractEntry;
use Programster\PgsqlTaskQueue\TaskHandlerInterface;


/**
 * @extends AbstractEntry<TaskHandlerInterface>
 */
final readonly class TaskHandlerEntry extends AbstractEntry
{
    public function __construct(string $type, TaskHandlerInterface $handler)
    {
        parent::__construct($type, $handler);
    }


    public static function createUsingHandler(TaskHandlerInterface $handler) : TaskHandlerEntry
    {
        return new TaskHandlerEntry($handler->getType(), $handler);
    }
}
