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
A simple queue of tasks kept in PostgreSQL, which any number of workers, on any number of machines, take tasks from
and run. Its one job is scheduling tasks (which may depend on others), and letting workers take them and run them. It's
for background jobs (sending emails, syncing with an API), and for splitting up a large piece of work (e.g. a test
suite) to run in parallel.

- **Dependencies:** a task can depend on others, and only runs once they've all finished. When one fails, each task
  that depends on it is removed too, or runs anyway, as it chooses.
- **Each task runs once:** workers claim tasks with `FOR UPDATE SKIP LOCKED`, so no two ever get the same one, and
  nothing else (e.g. Redis) is needed.
- **Workers that stop partway through:** each task has a lease. If its worker dies, the task is removed (with its
  dependents) once the lease runs out, rather than run twice, and the worker throws, saying so.
- **Priorities, delays and groups:** higher priorities go first, a task can wait until a time, and tasks can share a
  group name (e.g. a UUID for a test run) to be fetched together.
- **Starts straight away:** an idle worker is woken by `NOTIFY` as soon as a task is added or finishes.

Everything else is left to the application: a handled task is simply removed, and when one fails, the worker throws
`ExceptionTaskFailed`, with the task and the tasks removed with it, for the application to log, retry (by enqueueing
them again) or ignore.

## Requirements
- PHP 8.2, 8.3, 8.4 or 8.5, with the `pgsql` extension. The `pcntl` extension is useful for a worker to stop cleanly
  on SIGTERM.
- PostgreSQL 14, 15, 16, 17 or 18.

## Installation
```bash
composer require programster/pgsql-task-queue
```

## Quick start
Create the tables, add some tasks, and run a worker:

```php
use Programster\PgsqlLib\PgSqlConnection;

// A handler runs one type of task, given its data.
final class SendWelcomeEmailHandler implements TaskHandlerInterface
{
    public function getType() : string
    {
        return 'send-welcome-email';
    }


    public function handle(Task $task) : void
    {
        $email = $task->getData()['email'];
        echo "Sending a welcome email to {$email}." . PHP_EOL;
    }
}

$connection = PgSqlConnection::create('localhost', 'app', 'app', 'secret');
TaskQueue::createTables($connection);
$queue = new TaskQueue($connection);

// A task's data is any JsonSerializable, or an array.
$welcome = Task::create('send-welcome-email', ['email' => 'jo@example.com'], queue: 'emails');
$reminder = Task::create('send-welcome-email', ['email' => 'sam@example.com'], queue: 'emails');
$queue->enqueue($welcome, $reminder);

// The application writes the worker's loop, so it can log, stop and handle failures however it likes.
$worker = new Worker($queue, ['emails'], new SendWelcomeEmailHandler());

while ($worker->runNext())
{
    // Run every task that's ready, then stop. A long-running worker waits for more (see docs/workers.md).
}
```

## Design principles
- **Small.** Thirteen public classes: one `Task`, the queue, a worker, a handler interface, two enums, three
  exceptions, an `ExpiredTask`, and collections of tasks, task IDs and expired tasks. Logging, retries, deduplication
  and keeping a history are left to the application.
- **Mistakes fail where they're made.** A task is checked when it's created: an empty type, data that can't be stored
  as JSON, or a lease of 0 throws on the line that made it, not in a worker hours later. A cycle of dependencies can't
  even be built, as a task can only depend on tasks that already exist.
- **Failures are thrown, never hidden.** A task that fails is removed, and the worker throws, with everything needed to
  put it back.
- **One source of truth.** Everything is in PostgreSQL, in its own schema. Tasks can be added in the same transaction
  as the change that needs them, so they only exist if that change is committed.
- **Your clock.** Every time comes from the PSR-20 clock it's given (or the real time), never the database's, so an
  application that can move its clock (e.g. for testing) moves the queue's too.

## Documentation
See [docs/README.md](docs/README.md) for everything else: tasks, workers, looking after the queue, testing and
upgrading.

## Licence
MIT. See [LICENSE](LICENSE).
