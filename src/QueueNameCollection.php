<?php

/*
 * A readonly collection of queue names, e.g. the queues a worker takes tasks from.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\Collections\AbstractReadonlyCollection;


/**
 * @extends AbstractReadonlyCollection<QueueName>
 */
final readonly class QueueNameCollection extends AbstractReadonlyCollection
{
    public function __construct(QueueName ...$queueNames)
    {
        parent::__construct(...$queueNames);
    }
}
