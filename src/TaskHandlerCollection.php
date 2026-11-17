<?php

/*
 * A readonly collection of task handlers, e.g. the ones a worker runs tasks with.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<TaskHandlerInterface>
 */
final readonly class TaskHandlerCollection extends AbstractReadonlyCollection
{
    public function __construct(TaskHandlerInterface ...$handlers)
    {
        parent::__construct(...$handlers);
    }
}
