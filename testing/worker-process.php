<?php

/*
 * Runs one worker, in a process of its own, for the tests that need several at once, or one to kill (see
 * WorkerProcessesTest.php). It uses the real clock, and takes TestTasks from the "tests" queue.
 *
 * Usage: php testing/worker-process.php <batch id> <poll seconds>
 * It stops once the batch has finished, and prints why it stopped.
 */

require_once(__DIR__ . '/../vendor/autoload.php');

use Programster\PgsqlTaskQueue\QueueName;
use Programster\PgsqlTaskQueue\QueueNameCollection;
use Programster\PgsqlTaskQueue\SystemClock;
use Programster\PgsqlTaskQueue\TaskHandlerCollection;
use Programster\PgsqlTaskQueue\TaskQueue;
use Programster\PgsqlTaskQueue\Testing\HandledLog;
use Programster\PgsqlTaskQueue\Testing\TestDatabase;
use Programster\PgsqlTaskQueue\Testing\TestTaskHandler;
use Programster\PgsqlTaskQueue\WorkerStopConditions;


$batchId = (int)($argv[1] ?? 0);
$pollSeconds = (float)($argv[2] ?? 1);
$connection = TestDatabase::connect();
$clock = new SystemClock();
$queue = new TaskQueue($connection, $clock, TestDatabase::SCHEMA);
$batch = $queue->fetchBatch($batchId);
$handler = new TestTaskHandler(new HandledLog(), $clock, $queue, $connection);

$worker = $queue->createWorker(
    new TaskHandlerCollection($handler),
    new QueueNameCollection(new QueueName('tests')),
    stopConditions: new WorkerStopConditions(untilBatchFinished: $batch),
    pollSeconds: $pollSeconds
);

echo $worker->run()->value . "\n";
