<?php

/*
 * Where a task in the queue is: blocked until the tasks it depends on have finished, then waiting until a worker
 * claims it, then running. A task that's been handled (or has failed) is no longer in the queue at all.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


enum TaskStatus : string
{
    case BLOCKED = 'blocked';
    case WAITING = 'waiting';
    case RUNNING = 'running';
}
