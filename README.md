# PostgreSQL Task Queue

[![Tests](https://github.com/programster/package-pgsql-task-queue/actions/workflows/tests.yml/badge.svg)](https://github.com/programster/package-pgsql-task-queue/actions/workflows/tests.yml)

- [What it's for](#what-its-for)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Design principles](#design-principles)
- [Documentation](#documentation)
- [Licence](#licence)

## What it's for
A queue of tasks kept in PostgreSQL, which any number of workers, on any number of machines, take tasks from and
run. It's for background jobs (sending emails, syncing with an API, nightly reports), and for splitting up a large
piece of work (e.g. a test suite) to run in parallel.

- **Dependencies:** a task can depend on others, and only runs once they've all succeeded. If one dies, everything
  that depends on it is skipped, saying why.
- **Each task runs once at a time:** workers claim tasks with `FOR UPDATE SKIP LOCKED`, so no two ever get the same
  one, and nothing else (e.g. Redis) is needed.
- **Workers that stop partway through:** each task has a lease. If its worker dies, the task is retried or failed
  once the lease runs out, depending on whether it's safe to run twice.
- **Retries, deferral and priorities:** temporary failures are retried on a schedule, a task that can't run yet (e.g.
  at a rate limit) can be put back until later, and higher priorities go first.
- **Batches:** a group of tasks (e.g. one test run) can be summarised and waited for together, and the tasks that
  took longest last time go first, so the batch finishes sooner.
- **Everything is recorded:** each task's result, output, errors, attempts and timings stay in the table until
  deleted, for an admin page or a summary.
- **Starts straight away:** an idle worker is woken by `NOTIFY` as soon as a task is ready.

## Requirements
- PHP 8.2, 8.3, 8.4 or 8.5, with the `pgsql` extension. The `pcntl` extension is recommended, so a worker stops
  cleanly on SIGTERM and SIGINT.
- PostgreSQL 14, 15, 16, 17 or 18.

## Installation
```bash
composer require programster/pgsql-task-queue
```

## Quick start
A task holds the data for one piece of work, and a handler does the work:

```php
<?php

require_once(__DIR__ . '/vendor/autoload.php');

use Programster\PgsqlLib\PgSqlConnection;

final readonly class SendWelcomeEmail implements TaskInterface
{
    private string $m_memberId;

    public function __construct(string $memberId)
    {
        $this->m_memberId = $memberId;
    }

    public static function createFromArray(array $data) : static
    {
        return new SendWelcomeEmail($data['memberId']);
    }

    public function jsonSerialize() : array
    {
        return ['memberId' => $this->m_memberId];
    }

    public function getMemberId() : string { return $this->m_memberId; }
}

final readonly class SendWelcomeEmailHandler implements TaskHandlerInterface
{
    public function getTaskClass() : string
    {
        return SendWelcomeEmail::class;
    }

    public function handle(TaskInterface $task, TaskContext $context) : TaskResult
    {
        // ... send the email to $task->getMemberId() ...
        return new TaskResult(['sent' => true]);
    }
}
```

Install the tables once (e.g. alongside your own migrations), then add tasks:

```php
$connection = PgSqlConnection::create('localhost', 'myapp', 'myapp', 'secret', forceNew: true);
TaskQueueSchema::migrate($connection);
$queue = new TaskQueue($connection, new SystemClock());
$emails = new QueueName('emails');

$options = new TaskOptions(retrySchedule: RetrySchedule::createUsingDelaysInSeconds(60, 300, 1800));
$queued = $queue->enqueue(new NewTask(new SendWelcomeEmail('member-1'), $emails, $options));
```

And run a worker, in a long-running process of its own (e.g. under supervisord), with a connection of its own:

```php
$workerConnection = PgSqlConnection::create('localhost', 'myapp', 'myapp', 'secret', forceNew: true);
$workerQueue = new TaskQueue($workerConnection, new SystemClock());

$worker = $workerQueue->createWorker(
    new TaskHandlerCollection(new SendWelcomeEmailHandler()),
    new QueueNameCollection($emails),
    stopConditions: new WorkerStopConditions(stopWhenIdle: true) // just for this example: normally, leave it running
);

$worker->run();
assert($queue->fetchTask($queued->getId())->getStatus() === TaskStatus::SUCCEEDED);
```

## Design principles
- **Mistakes fail where they're made.** Values are checked when they're created: a misspelt queue name, a task that
  can't be stored as JSON, or a lease of 0 throws on the line that made it, not in a worker hours later. A cycle of
  dependencies can't even be built, as a task can only depend on tasks that already exist.
- **Safe defaults.** A task that's interrupted is failed, not run again, unless it says it's safe to retry
  (`InterruptedTaskPolicy::RETRY`), so an email or payment never happens twice by default. Nothing is retried unless
  it has a `RetrySchedule`.
- **One source of truth.** Everything is in PostgreSQL, in its own schema, so it's backed up, inspected and queried
  like the rest of your data. A task can be added in the same transaction as the change that needs it, so it only
  exists if that change is committed.
- **Your clock.** Every time comes from the PSR-20 clock it's given, never the database's, so an application that can
  move its clock (e.g. for testing) moves the queue's too.

## Documentation
See [docs/README.md](docs/README.md) for everything else: tasks and handlers, dependencies and batches, workers,
retries and failures, looking after the queue, testing and upgrading.

## Licence
MIT. See [LICENSE](LICENSE).
