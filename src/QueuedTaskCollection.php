<?php

/*
 * A readonly collection of tasks in a queue, as they were when they were fetched.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<QueuedTask>
 */
final readonly class QueuedTaskCollection extends AbstractReadonlyCollection
{
    public function __construct(QueuedTask ...$tasks)
    {
        parent::__construct(...$tasks);
    }
}
