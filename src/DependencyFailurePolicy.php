<?php

/*
 * What happens to a task when a task it depends on fails (its handler threw, or its worker stopped partway through).
 * It's set on the task that depends, and covers all of its dependencies.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


enum DependencyFailurePolicy : string
{
    // It's removed from the queue too, as it can't run without the other having succeeded. It's given back in the
    // ExceptionTaskFailed, with the task that failed, so it can be re-enqueued. Its own dependents then follow their
    // own policies.
    case REMOVE = 'remove';

    // The failure counts as that dependency having finished, so it runs anyway, once its other dependencies have
    // finished. This is for a task that only needed the other to run first, not to succeed.
    case CONTINUE = 'continue';
}
