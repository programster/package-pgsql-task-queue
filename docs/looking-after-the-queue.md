# Looking after the queue

- [Installing and upgrading the tables](#installing-and-upgrading-the-tables)
- [Adding tasks in your own transactions](#adding-tasks-in-your-own-transactions)
- [Admin pages](#admin-pages)
- [Deleting old tasks](#deleting-old-tasks)
- [The clock](#the-clock)

## Installing and upgrading the tables
`TaskQueueSchema::migrate()` creates the queue's tables, or brings them up to date after an upgrade, so run it
wherever you run your own migrations (e.g. when your container starts). They go in a schema of their own
(`task_queue` by default), with their own migration version (in `task_queue.migrations`, using
`programster/pgsql-migrations`), so they never clash with your tables or migrations.

```php
use Programster\PgsqlLib\PgSqlConnection;

$connection = PgSqlConnection::create('localhost', 'myapp', 'myapp', 'secret', forceNew: true);
TaskQueueSchema::migrate($connection);
```

To use another schema (e.g. two separate queues in one database), give it to both `migrate()` and `TaskQueue`, as
`migrate($connection, 'reports_queue')` and `new TaskQueue($connection, $clock, 'reports_queue')`. Run `migrate()`
once per process, as its migrations are loaded from their files the first time.

## Adding tasks in your own transactions
If the connection is already in a transaction, adding tasks happens within it (in a savepoint), so the tasks only
exist if your transaction commits. That way, a task is never left behind for a change that was rolled back, nor a
change made without its task:

```php
final readonly class SendReceipt implements TaskInterface
{
    public static function createFromArray(array $data) : static
    {
        return new SendReceipt();
    }

    public function jsonSerialize() : array
    {
        return [];
    }
}

$queue = new TaskQueue($connection, new SystemClock());
$connection->query('BEGIN');
// ... record the payment ...
$queue->enqueue(new NewTask(new SendReceipt(), new QueueName('emails')));
$connection->query('COMMIT');
```

## Admin pages
The queue keeps each task's status, attempts, timings, result, output and error, for an admin page to show:

```php
$recentlyDead = $queue->fetchPage(TaskStatus::DEAD, new QueueName('emails'), limit: 50);

foreach ($recentlyDead as $task)
{
    echo "{$task->getId()}: {$task->getError()}, after {$task->getAttempts()} attempts\n";
}

$receipt = $queue->fetchTask(1);
assert($receipt->getStatus() === TaskStatus::WAITING);
```

`retry()` runs a dead task again (see [Retries and failures](retries-and-failures.md#retrying-a-dead-task)).

## Deleting old tasks
Finished tasks stay until they're deleted, so delete them every so often (e.g. daily, from a task of their own),
keeping as long as is useful. `deleteFinishedBefore()` deletes the tasks that succeeded, died or were skipped before a
time, and the batches made before then that have no tasks left. Unfinished tasks are never deleted.

```php
$numDeleted = $queue->deleteFinishedBefore(new DateTimeImmutable('-30 days'));
```

## The clock
Every time the queue records (when a task was added, became due, started, finished, or its lease runs out) comes from
the PSR-20 clock it's given, never the database's `now()`. `SystemClock` gives the real time, in UTC. An application
with a clock of its own (e.g. one an admin can move forward, to test what happens next month) gives the queue that
clock, so delays, retries and leases follow it too.
