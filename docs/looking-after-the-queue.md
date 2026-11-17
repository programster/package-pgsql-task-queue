# Looking after the queue

- [Creating the tables](#creating-the-tables)
- [Adding tasks in your own transaction](#adding-tasks-in-your-own-transaction)
- [Reading and cancelling tasks](#reading-and-cancelling-tasks)

## Creating the tables
The queue's tables go in a schema of their own (`task_queue`, unless you give another), so they never clash with
yours. The package has no migrations of its own. Either let it create them, if they don't exist, e.g. when the
application starts:

```php
use Programster\PgsqlLib\PgSqlConnection;

$connection = PgSqlConnection::create('localhost', 'app', 'app', 'secret');
TaskQueue::createTables($connection);
TaskQueue::createTables($connection); // Safe to run again.
```

Or put its SQL in your own migrations:

```php
$sql = TaskQueue::getCreateTablesSql('jobs');
assert(str_contains($sql, 'CREATE TABLE IF NOT EXISTS "jobs".task ('));
```

A queue in another schema is given it too: `new TaskQueue($connection, null, 'jobs')`. The tables only hold tasks
still to run: a task is removed once it's handled, or has failed, so they stay small, and there's nothing to clear out.

## Adding tasks in your own transaction
If the connection is already in a transaction, the tasks are added in a savepoint within it, so they're only kept if
it's committed, e.g. a welcome email is only sent for a member who was saved:

```php
$queue = new TaskQueue($connection);
$connection->query('BEGIN');
// ... save the member.
$queue->enqueue(Task::create('send-welcome-email', ['memberId' => 42], group: 'member-42'));
$connection->query('ROLLBACK');
assert($queue->fetchGroup('member-42')->count() === 0);
```

## Reading and cancelling tasks
`fetch()` returns a task by its ID, as it is now (throwing `ExceptionTaskNotFound` once it's gone), and `fetchGroup()`
the tasks still in a group. `delete()` cancels a task as if it had failed: the tasks depending on it follow their
`DependencyFailurePolicy`, and those removed with it are returned.

```php
$report = $queue->enqueue(Task::create('build-report', [], group: 'monthly'))->first();
assert($queue->fetch($report->getId())->getStatus() === TaskStatus::WAITING);
$removed = $queue->delete($report);
assert($removed->count() === 0 && $queue->fetchGroup('monthly')->count() === 0);
```
