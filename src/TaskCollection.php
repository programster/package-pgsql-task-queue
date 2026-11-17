<?php

/*
 * A readonly collection of tasks, e.g. those enqueued together, or those removed when a task they depended on failed.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<Task>
 */
final readonly class TaskCollection extends AbstractReadonlyCollection
{
    public function __construct(Task ...$tasks)
    {
        parent::__construct(...$tasks);
    }
}
