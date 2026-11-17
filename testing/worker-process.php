<?php

/*
 * Runs one worker, in a process of its own, for the tests that need several at once, or one to kill (see
 * WorkerProcessesTest.php). It uses the real clock, and takes the tests' tasks from the "tests" queue, until there are
 * none left in a group, or it's sent SIGTERM. Failures are ignored, as the tests check what ran.
 *
 * Usage: php testing/worker-process.php <group> <seconds to wait for a task each time>
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskFailed;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTasksExpired;
use Programster\PgsqlTaskQueue\TaskQueue;
use Programster\PgsqlTaskQueue\Testing\TestDatabase;
use Programster\PgsqlTaskQueue\Testing\TestHandler;
use Programster\PgsqlTaskQueue\Worker;

$group = (string)($argv[1] ?? '');
$waitSeconds = (float)($argv[2] ?? 1);
$connection = TestDatabase::connect();
$queue = new TaskQueue($connection, null, TestDatabase::SCHEMA);
$worker = new Worker($queue, ['tests'], new TestHandler($connection, (string)getmypid()));
$shouldStop = false;

$stop = function () use (&$shouldStop) : void {
    $shouldStop = true;
};

pcntl_async_signals(true);
pcntl_signal(SIGTERM, $stop);

while (!$shouldStop && $queue->fetchGroup($group)->count() > 0)
{
    try
    {
        if (!$worker->runNext())
        {
            $worker->waitForTask($waitSeconds);
        }
    }
    catch (ExceptionTaskFailed | ExceptionTasksExpired)
    {
        // The tests check what ran, and what's left.
    }
}
