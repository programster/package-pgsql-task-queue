<?php

/*
 * The tasks whose lease ran out, given by ExceptionTasksExpired.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<ExpiredTask>
 */
final readonly class ExpiredTaskCollection extends AbstractReadonlyCollection
{
    public function __construct(ExpiredTask ...$expiredTasks)
    {
        parent::__construct(...$expiredTasks);
    }
}
