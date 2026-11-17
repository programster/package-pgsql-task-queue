<?php

/*
 * Where a task is in its life: blocked until the tasks it depends on have succeeded, then waiting until a worker
 * claims it, then running, and finally succeeded, dead (it failed, and won't be tried again) or skipped (a task it
 * depends on died, so it never ran).
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


enum TaskStatus : string
{
    case BLOCKED = 'blocked';
    case WAITING = 'waiting';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case DEAD = 'dead';
    case SKIPPED = 'skipped';


    /**
     * Find out whether a task with this status is finished, so nothing will happen to it again, unless an admin
     * retries it.
     *
     * @return bool
     */
    public function isFinished() : bool
    {
        return match ($this) {
            TaskStatus::BLOCKED, TaskStatus::WAITING, TaskStatus::RUNNING => false,
            TaskStatus::SUCCEEDED, TaskStatus::DEAD, TaskStatus::SKIPPED => true,
        };
    }
}
