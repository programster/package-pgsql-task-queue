# Workers

- [The worker loop](#the-worker-loop)
- [Failures](#failures)
- [Putting failed tasks back](#putting-failed-tasks-back)
- [Leases](#leases)

## The worker loop
A worker takes tasks from the queues it's given, of the types it has handlers for. Run as many as you like, on as
many machines as can reach the database: no two ever take the same task. Each needs a connection of its own (made
with `forceNew: true` if the process has others), as it listens for notifications on it.

The package doesn't loop, log or handle signals itself. `runNext()` runs one task (returning false if none was ready),
and `waitForTask()` waits until one may be ready (it's woken by `NOTIFY` when tasks are added or finish), so the loop
is yours, e.g.:

```php
use Programster\PgsqlLib\PgSqlConnection;

final class ResizeImageHandler implements TaskHandlerInterface
{
    public function getType() : string
    {
        return 'resize-image';
    }


    public function handle(Task $task) : void
    {
        if ($task->getData()['path'] === 'missing.jpg')
        {
            throw new RuntimeException("There's no missing.jpg.");
        }
    }
}

/**
 * Run tasks until told to stop, waiting when there are none.
 * @param Worker $worker
 * @param Closure(): bool $shouldStop
 */
function runWorker(Worker $worker, Closure $shouldStop) : void
{
    while (!$shouldStop())
    {
        try
        {
            if (!$worker->runNext())
            {
                $worker->waitForTask(5);
            }
        }
        catch (ExceptionTaskFailed $failure)
        {
            // Log it, re-enqueue it, or alert someone: the task has been removed either way.
            error_log($failure->getMessage());
        }
        catch (ExceptionTasksExpired $expiry)
        {
            // Their workers stopped partway through them (see Leases).
            error_log($expiry->getMessage());
        }
    }
}

$connection = PgSqlConnection::create('localhost', 'app', 'app', 'secret');
TaskQueue::createTables($connection);
$queue = new TaskQueue($connection);
$queue->enqueue(Task::create('resize-image', ['path' => 'cat.jpg'], queue: 'images', group: 'example'));
$worker = new Worker($queue, ['images'], new ResizeImageHandler());

// Stop on SIGTERM (e.g. from Docker or supervisord), after the task in hand.
$isTerminated = false;

$terminate = function () use (&$isTerminated) : void {
    $isTerminated = true;
};

pcntl_async_signals(true);
pcntl_signal(SIGTERM, $terminate);

// Here, also stop once the example's tasks are done, so it ends.
$shouldStop = function () use (&$isTerminated, $queue) : bool {
    return ($isTerminated || $queue->fetchGroup('example')->count() === 0);
};

runWorker($worker, $shouldStop);
```

## Failures
When a handler throws, the task is removed, and `runNext()` throws `ExceptionTaskFailed`:

- `getTask()` is the task that failed, as it was in the queue.
- `getDependents()` is a `TaskCollection` of the tasks removed with it: those depending on it (directly, or through
  others) whose `DependencyFailurePolicy` is `REMOVE`. Those set to `CONTINUE` are released instead, and run.
- `getPrevious()` is what the handler threw.

There are no retries, and nothing is logged: what to do is up to the application.

```php
$queue->enqueue(Task::create('resize-image', ['path' => 'missing.jpg'], queue: 'images'));

try
{
    $worker->runNext();
}
catch (ExceptionTaskFailed $failure)
{
    assert($failure->getPrevious()->getMessage() === "There's no missing.jpg.");
    assert($failure->getDependents()->count() === 0);
}
```

## Putting failed tasks back
The tasks in an `ExceptionTaskFailed` can be enqueued again, just as they are. They're added afresh, with new IDs, and
the dependencies among the tasks given together are linked to their new copies, so the whole set waits as before:

```php
// e.g. if it's worth another try.
$retried = $queue->enqueue($failure->getTask(), ...$failure->getDependents()->toArray());
assert($retried->first()->getId() !== $failure->getTask()->getId());
$queue->delete($retried->first());
```

A dependent given back without the task it waited for is rejected (`ExceptionTaskNotFound`), as that task is gone.

## Leases
When a worker takes a task, it has the task's `leaseSeconds` to finish it. If the worker stops partway through (it's
killed, or its machine dies), the next worker to call `runNext()` after the lease has run out removes the task, as it
may have been partly done, so it isn't run twice. It removes every such task, one by one, each with the tasks that
depend on it and can't continue without it (`DependencyFailurePolicy::REMOVE`). Those that can continue (`CONTINUE`)
are left in the queue, to run.

It then throws `ExceptionTasksExpired`, rather than running a task. Its `getExpiredTasks()` is an
`ExpiredTaskCollection`, of an `ExpiredTask` for each: `getTask()` is the task whose lease ran out, and
`getDependents()` the tasks removed because of it. Each can be put back with its dependents, if it's safe to run
again: `$queue->enqueue($expired->getTask(), ...$expired->getDependents()->toArray())`.

So give each task a lease comfortably longer than it takes, and have the handler of a task that can take a long time
extend its lease.

A handler extends its task's lease as it goes, to a number of seconds from now:

```php
final class LongExportHandler implements TaskHandlerInterface
{
    private TaskQueue $m_queue;


    public function __construct(TaskQueue $queue)
    {
        $this->m_queue = $queue;
    }


    public function getType() : string
    {
        return 'long-export';
    }


    public function handle(Task $task) : void
    {
        foreach ([1, 2, 3] as $page)
        {
            // ... export a page, then make sure there's time for the next.
            $this->m_queue->extendLease($task, 300);
        }
    }
}

$queue->enqueue(Task::create('long-export', [], queue: 'exports'));
assert((new Worker($queue, ['exports'], new LongExportHandler($queue)))->runNext());
```
