# Retries and failures

- [Failing a task](#failing-a-task)
- [Retry schedules](#retry-schedules)
- [Deferring a task](#deferring-a-task)
- [Retrying a dead task](#retrying-a-dead-task)

## Failing a task
A handler fails its task by throwing:

| It throws | What happens |
|---|---|
| `ExceptionTaskFailedPermanently` | It dies straight away, however many attempts it has left, as trying again can't help (e.g. the email address doesn't exist). |
| `ExceptionTaskDeferred` | It goes back in the queue until the time given, without using up an attempt (see [below](#deferring-a-task)). |
| Anything else | A temporary failure: it's retried after its `RetrySchedule`'s next delay, and dies once it has no attempts left. |

The error (the exception's class, message, file and line) is kept with the task (`getError()`), and when a task dies,
every task that depends on it is skipped (see [Dependencies and batches](dependencies-and-batches.md)).

## Retry schedules
A task gets one attempt, plus one for each delay in its `RetrySchedule`. Without one, it's never retried:

```php
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskDeferred;

$never = RetrySchedule::createNever();
assert($never->getMaxAttempts() === 1);

// Retried 1 minute after the first failure, 5 minutes after the second, and 30 after the third.
$schedule = RetrySchedule::createUsingDelaysInSeconds(60, 300, 1800);
assert($schedule->getMaxAttempts() === 4);
```

## Deferring a task
When a task can't run yet, but will be able to later (e.g. a rate limit has been reached), its handler throws
`ExceptionTaskDeferred` with the earliest time to try again. It goes back to `WAITING` until then, without using up an
attempt, and the reason is kept as its error:

```php
final readonly class SendNewsletter implements TaskInterface
{
    public static function createFromArray(array $data) : static
    {
        return new SendNewsletter();
    }

    public function jsonSerialize() : array
    {
        return [];
    }
}

final readonly class SendNewsletterHandler implements TaskHandlerInterface
{
    public function getTaskClass() : string
    {
        return SendNewsletter::class;
    }

    public function handle(TaskInterface $task, TaskContext $context) : TaskResult
    {
        $isAtDailyLimit = true; // e.g. from counting today's emails

        if ($isAtDailyLimit)
        {
            $tomorrow = new DateTimeImmutable('tomorrow');
            throw new ExceptionTaskDeferred($tomorrow, "The daily email limit has been reached.");
        }

        return new TaskResult();
    }
}

$connection = PgSqlConnection::create('localhost', 'myapp', 'myapp', 'secret', forceNew: true);
TaskQueueSchema::migrate($connection);
$queue = new TaskQueue($connection, new SystemClock());
$emails = new QueueName('emails');
$newsletter = $queue->enqueue(new NewTask(new SendNewsletter(), $emails));

$worker = $queue->createWorker(
    new TaskHandlerCollection(new SendNewsletterHandler()),
    new QueueNameCollection($emails),
    stopConditions: new WorkerStopConditions(stopWhenIdle: true)
);

$worker->run();
$deferred = $queue->fetchTask($newsletter->getId());
assert($deferred->getStatus() === TaskStatus::WAITING && $deferred->getAttempts() === 0);
```

## Retrying a dead task
`TaskQueue::retry()` runs a dead task again from the start, with all its attempts, e.g. from an admin page once the
problem is fixed. The tasks that were skipped because it died go back to `BLOCKED`, so they run once it succeeds.
Only a dead task can be retried: anything else throws `ExceptionCantRetryTask`, and a skipped task is retried by
retrying the task that died.

```php
// Throws ExceptionCantRetryTask
$queue->retry($newsletter->getId());
```
