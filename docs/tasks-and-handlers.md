# Tasks and handlers

- [Tasks](#tasks)
- [Handlers](#handlers)
- [Task options](#task-options)
- [Queues](#queues)

## Tasks
A task holds the data for one piece of work, and nothing else. It's stored as JSON (from `jsonSerialize()`), under its
class's name, and recreated by the worker that claims it (with `createFromArray()`). Keep it small: an ID to load
something by, rather than the thing itself, as the thing may have changed by the time the task runs.

```php
use Programster\PgsqlLib\PgSqlConnection;

final readonly class ResizeImage implements TaskInterface
{
    private string $m_path;
    private int $m_width;

    public function __construct(string $path, int $width)
    {
        $this->m_path = $path;
        $this->m_width = $width;
    }

    public static function createFromArray(array $data) : static
    {
        return new ResizeImage($data['path'], $data['width']);
    }

    public function jsonSerialize() : array
    {
        return ['path' => $this->m_path, 'width' => $this->m_width];
    }

    public function getPath() : string { return $this->m_path; }
    public function getWidth() : int { return $this->m_width; }
}
```

A `NewTask` checks that its task survives being stored and recreated, so a task that can't be (e.g. it holds `NAN`,
or `createFromArray()` expects a key `jsonSerialize()` doesn't give) fails where it's made, not in a worker later:

```php
// Throws InvalidArgumentException
final readonly class Broken implements TaskInterface
{
    public static function createFromArray(array $data) : static
    {
        return new Broken();
    }

    public function jsonSerialize() : array
    {
        return ['size' => NAN];
    }
}

new NewTask(new Broken(), new QueueName('images'));
```

The JSON is stored as `jsonb`, which doesn't keep the order of an object's keys, so `createFromArray()` should read
them by name.

## Handlers
A handler does the work for one class of task. It returns a `TaskResult`, with data for code to read, and output for
people to read, both of which are stored with the task:

```php
final readonly class ResizeImageHandler implements TaskHandlerInterface
{
    public function getTaskClass() : string
    {
        return ResizeImage::class;
    }

    public function handle(TaskInterface $task, TaskContext $context) : TaskResult
    {
        assert($task instanceof ResizeImage);
        // ... resize the image ...
        return new TaskResult(['width' => $task->getWidth()], "Resized {$task->getPath()}");
    }
}
```

The `TaskContext` says which attempt this is (`getAttempt()`, `isLastAttempt()`), gives the task as it was claimed
(`getTask()`), and lets a long task extend its lease (see [Workers](workers.md#leases)). To fail, a handler throws
(see [Retries and failures](retries-and-failures.md)).

## Task options
Every option has a safe default, so a task only needs the ones it changes:

```php
$options = new TaskOptions(
    priority: 10,                                                      // higher goes first (default 0)
    retrySchedule: RetrySchedule::createUsingDelaysInSeconds(60, 300), // default: never retried
    onInterruption: InterruptedTaskPolicy::RETRY,                      // default: FAIL
    leaseSeconds: 600,                                                 // default: 300
    name: 'resize avatar',                                             // shown in summaries
    deduplicationKey: 'resize:avatars/1.png',                          // see below
    notBefore: new DateTimeImmutable('+1 hour')                        // default: straight away
);
```

- **Deduplication:** while a task with a key is unfinished (blocked, waiting or running) in its queue, enqueuing
  another with the same key adds nothing, and returns the one already there. Once it has finished, the key can be
  used again.
- **Names:** tasks in the same queue with the same name are expected to take as long as the last one that succeeded.
  Among tasks of the same priority, the one expected to take longest is claimed first, so a batch finishes sooner.

```php
$connection = PgSqlConnection::create('localhost', 'myapp', 'myapp', 'secret', forceNew: true);
TaskQueueSchema::migrate($connection);
$queue = new TaskQueue($connection, new SystemClock());
$images = new QueueName('images');
$key = new TaskOptions(deduplicationKey: 'resize:avatars/1.png');

$first = $queue->enqueue(new NewTask(new ResizeImage('avatars/1.png', 100), $images, $key));
$again = $queue->enqueue(new NewTask(new ResizeImage('avatars/1.png', 100), $images, $key));
assert($again->getId() === $first->getId());
```

## Queues
Each task goes in one queue, named by a `QueueName`, and each worker takes tasks from the queues it's given, so
separate kinds of work can have workers of their own (e.g. a few for `emails`, and one for `reports`). A worker only
claims tasks of the classes it has handlers for, so workers with different handlers can also share a queue.
