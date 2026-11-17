<?php

/*
 * A readonly collection of tasks to add to a queue, e.g. for TaskQueue::enqueueAll().
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<NewTask>
 */
final readonly class NewTaskCollection extends AbstractReadonlyCollection
{
    public function __construct(NewTask ...$tasks)
    {
        parent::__construct(...$tasks);
    }
}
