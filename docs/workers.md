# Workers

- [Running workers](#running-workers)
- [Stopping](#stopping)
- [Leases](#leases)
- [Interrupted tasks](#interrupted-tasks)

## Running workers
A worker claims a task from its queues, runs it with the handler for its class, records how it went, and repeats.
Run as many as you like, on as many machines as can reach the database: claiming uses `FOR UPDATE SKIP LOCKED`, so no
two workers ever get the same task, and none waits for another.

Each worker needs a database connection of its own (created with `forceNew: true`), as it listens for notifications
on it, and holds transactions on it. While there's nothing to claim, it waits for a `NOTIFY` saying tasks are ready,
so it starts on a new task straight away, and checks the queue again every `pollSeconds` (1 by default) in case one
was missed, or a delayed task has become due.

A typical worker script, run by a process manager such as supervisord, which restarts it when it stops:

```php
use Programster\PgsqlLib\PgSqlConnection;

final readonly class Ping implements TaskInterface
{
    public static function createFromArray(array $data) : static
    {
        return new Ping();
    }

    public function jsonSerialize() : array
    {
        return [];
    }
}

final readonly class PingHandler implements TaskHandlerInterface
{
    public function getTaskClass() : string
    {
        return Ping::class;
    }

    public function handle(TaskInterface $task, TaskContext $context) : TaskResult
    {
        return new TaskResult([], 'pong');
    }
}

$connection = PgSqlConnection::create('localhost', 'myapp', 'myapp', 'secret', forceNew: true);
TaskQueueSchema::migrate($connection);
$queue = new TaskQueue($connection, new SystemClock());
$queue->enqueue(new NewTask(new Ping(), new QueueName('default')));

$worker = $queue->createWorker(
    new TaskHandlerCollection(new PingHandler()),
    new QueueNameCollection(new QueueName('default')),
    logger: new Psr\Log\NullLogger(), // e.g. your application's logger
    stopConditions: new WorkerStopConditions(maxSeconds: 3600, maxMemoryBytes: 256 * 1024 * 1024, stopWhenIdle: true)
);

$reason = $worker->run();
assert($reason === WorkerStopReason::IDLE);
```

(This example stops when idle, so it finishes. A long-running worker leaves `stopWhenIdle` out.)

The logger gets a line for each task: info when it succeeds or is deferred, a notice when it fails and will be
retried, an error when it dies, and a warning if the worker lost its lease.

## Stopping
A worker only stops between tasks, so it always finishes the task it's on first. It stops when:

- it's sent SIGTERM or SIGINT (with the `pcntl` extension), e.g. when its container stops, or `stop()` is called;
- it has run for `maxSeconds`, used more than `maxMemoryBytes`, or run `maxTasks` tasks, so a process manager starts a
  fresh one every so often;
- every task in `untilBatchFinished` has finished, e.g. for a test run;
- there's nothing it can claim, with `stopWhenIdle`, e.g. to run what's due from cron and exit.

`run()` returns a `WorkerStopReason` saying which. Give a process manager long enough to let the current task finish
(e.g. supervisord's `stopwaitsecs`) before it kills the worker.

## Leases
When a worker claims a task, it gets a lease on it, for the task's `leaseSeconds` (300 by default). A task that may
take longer extends its lease as it goes, e.g. after each item it works through:

```php
final readonly class LongReportHandler implements TaskHandlerInterface
{
    public function getTaskClass() : string
    {
        return Ping::class;
    }

    public function handle(TaskInterface $task, TaskContext $context) : TaskResult
    {
        foreach (['january', 'february', 'march'] as $month)
        {
            // ... add the month to the report ...
            $context->extendLease(300);
        }

        return new TaskResult();
    }
}
```

If the lease had already run out, `extendLease()` throws `ExceptionLeaseLost`, as the task may now be running
elsewhere. The handler should stop, as the worker won't keep its result either way.

## Interrupted tasks
If a worker stops partway through a task without saying how it went (it was killed, or its machine lost power), the
task's lease runs out. The next worker to look (each does before every claim, or `TaskQueue::recoverExpiredLeases()`)
then deals with it according to its `InterruptedTaskPolicy`:

- `FAIL` (the default): it dies, as it may already have done what it does, e.g. sent an email or taken a payment, and
  doing that twice is worse than not at all. Its error says why, so someone can check, and retry it if need be.
- `RETRY`: it's run again, if it has an attempt left, for a task that's safe to run twice, e.g. one that only reads,
  or sets something to a value.
