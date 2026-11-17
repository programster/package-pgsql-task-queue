<?php

/*
 * What happens to a task whose worker stopped partway through it, without saying how it went (e.g. it was killed, or
 * its server lost power), which is found once the task's lease runs out. A task that's safe to run again (e.g. one
 * that only reads, or sets something to a value) is retried. A task that mustn't happen twice (e.g. sending an email,
 * or taking a payment) is failed instead, as it may already have happened.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


enum InterruptedTaskPolicy : string
{
    // Run it again, if it has an attempt left, like any other failure.
    case RETRY = 'retry';

    // Mark it as dead, without running it again, as it may already have done what it does.
    case FAIL = 'fail';
}
