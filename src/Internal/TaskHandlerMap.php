<?php

/*
 * A worker's handlers, each by the type of task it handles.
 *
 * @internal
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Internal;


use Programster\Collections\AbstractReadonlyMap;
use Programster\PgsqlTaskQueue\TaskHandlerInterface;


/**
 * @extends AbstractReadonlyMap<TaskHandlerInterface>
 */
final readonly class TaskHandlerMap extends AbstractReadonlyMap
{
    public function __construct(TaskHandlerInterface ...$handlers)
    {
        parent::__construct(...$handlers);
    }
}
