<?php

/*
 * Why a worker stopped, as returned by Worker::run().
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


enum WorkerStopReason : string
{
    // It was sent SIGTERM or SIGINT (e.g. its container was stopped), or stop() was called.
    case STOPPED = 'stopped';

    // It had run for as long as its WorkerStopConditions allowed.
    case TIME_LIMIT = 'time-limit';

    // It was using more memory than its WorkerStopConditions allowed.
    case MEMORY_LIMIT = 'memory-limit';

    // It had run as many tasks as its WorkerStopConditions allowed.
    case TASK_LIMIT = 'task-limit';

    // The batch its WorkerStopConditions waited for had finished.
    case BATCH_FINISHED = 'batch-finished';

    // There was nothing it could claim, and its WorkerStopConditions said to stop when idle.
    case IDLE = 'idle';
}
