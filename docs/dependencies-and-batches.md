# Dependencies and batches

- [Dependencies](#dependencies)
- [When a dependency dies](#when-a-dependency-dies)
- [Batches](#batches)

## Dependencies
A task can depend on other tasks, and is `BLOCKED` until they've all succeeded, when it becomes `WAITING`, ready to
claim. A dependency is either a task already in the queue (the `QueuedTask` enqueuing it returned), or another
`NewTask` added in the same `enqueueAll()` call, which adds each task after the ones it depends on, whatever order
they're given in, all in one transaction.

As a `NewTask` can only be given dependencies that already exist when it's made, a cycle (A needs B, which needs A)
can't be built.

```php
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskFailedPermanently;

final readonly class Step implements TaskInterface
{
    private string $m_name;

    public function __construct(string $name)
    {
        $this->m_name = $name;
    }

    public static function createFromArray(array $data) : static
    {
        return new Step($data['name']);
    }

    public function jsonSerialize() : array
    {
        return ['name' => $this->m_name];
    }

    public function getName() : string { return $this->m_name; }
}

final readonly class StepHandler implements TaskHandlerInterface
{
    public function getTaskClass() : string
    {
        return Step::class;
    }

    public function handle(TaskInterface $task, TaskContext $context) : TaskResult
    {
        assert($task instanceof Step);

        if ($task->getName() === 'broken')
        {
            throw new ExceptionTaskFailedPermanently("The broken step always fails.");
        }

        return new TaskResult();
    }
}

$connection = PgSqlConnection::create('localhost', 'myapp', 'myapp', 'secret', forceNew: true);
TaskQueueSchema::migrate($connection);
$queue = new TaskQueue($connection, new SystemClock());
$steps = new QueueName('steps');

$download = new NewTask(new Step('download'), $steps);
$unpack = new NewTask(new Step('unpack'), $steps, new TaskOptions(), $download);
$index = new NewTask(new Step('index'), $steps, new TaskOptions(), $unpack);
$thumbnails = new NewTask(new Step('thumbnails'), $steps, new TaskOptions(), $unpack);
$publish = new NewTask(new Step('publish'), $steps, new TaskOptions(), $index, $thumbnails);

$queued = $queue->enqueueAll(new NewTaskCollection($publish, $thumbnails, $index, $unpack, $download));
assert($queued->get(4)->getStatus() === TaskStatus::WAITING); // download
assert($queued->get(0)->getStatus() === TaskStatus::BLOCKED); // publish
```

Once `unpack` succeeds, `index` and `thumbnails` can run at the same time, on different workers, and `publish` runs
once both have succeeded.

A task enqueued later can depend on one already in the queue, using the `QueuedTask`:

```php
$notify = $queue->enqueue(new NewTask(new Step('notify'), $steps, new TaskOptions(), $queued->get(0)));
assert($notify->getStatus() === TaskStatus::BLOCKED);
```

Depending on a `NewTask` that isn't being enqueued with it throws `ExceptionDependencyNotQueued`, as it has no ID to
depend on yet.

## When a dependency dies
If a task dies (it failed permanently, or ran out of attempts), every task that depends on it, directly or through
others, is `SKIPPED`, with an error naming the task that died (`getError()`, and `getSkippedBecauseId()`). A task
added later that depends on a dead or skipped one is skipped straight away. Retrying the task that died (see
[Retries and failures](retries-and-failures.md#retrying-a-dead-task)) un-skips them.

## Batches
A batch is a group of tasks, such as one test run, that can be summarised together, and that workers can wait for:

```php
$batch = $queue->createBatch('nightly import');
$tasks = new NewTaskCollection(new NewTask(new Step('a'), $steps), new NewTask(new Step('broken'), $steps));
$queue->enqueueAll($tasks, $batch);

$worker = $queue->createWorker(
    new TaskHandlerCollection(new StepHandler()),
    new QueueNameCollection($steps),
    stopConditions: new WorkerStopConditions(untilBatchFinished: $batch)
);

$worker->run();
$summary = $queue->fetchBatchSummary($batch);
assert($summary->isFinished() && !$summary->isAllSucceeded());
assert($summary->countWithStatus(TaskStatus::DEAD) === 1);

foreach ($summary->getWithStatus(TaskStatus::DEAD, TaskStatus::SKIPPED) as $failed)
{
    echo "{$failed->getName()}: {$failed->getError()}\n";
}

$slowest = $summary->getSlowest(5);
```

A worker process given only the batch's ID can fetch it with `$queue->fetchBatch($id)`.
