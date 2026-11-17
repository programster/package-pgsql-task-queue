<?php

/*
 * What a TestTask's handler does with it.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Testing;


enum TestTaskBehaviour : string
{
    // Return a result.
    case SUCCEED = 'succeed';

    // Throw an ordinary exception, for a temporary failure.
    case FAIL_TEMPORARILY = 'fail-temporarily';

    // Throw ExceptionTaskFailedPermanently.
    case FAIL_PERMANENTLY = 'fail-permanently';

    // Throw ExceptionTaskDeferred, until an hour from now, the first time, then succeed.
    case DEFER_ONCE = 'defer-once';

    // Move the fake clock past the lease, and recover expired leases, as another worker would, then return a result
    // (which shouldn't be kept, as the worker no longer holds the task).
    case LOSE_LEASE = 'lose-lease';

    // Stop the worker, then succeed.
    case STOP_WORKER = 'stop-worker';

    // Record in public.test_run when it started and finished (by the database's clock), after sleeping its sleepMs,
    // for the tests run with worker processes.
    case RECORD = 'record';

    // Record that it started, then sleep for a minute, for a worker process to be killed partway through.
    case HANG = 'hang';
}
