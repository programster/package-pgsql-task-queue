# Tasks

- [Creating a task](#creating-a-task)
- [Dependencies](#dependencies)
- [When a dependency fails](#when-a-dependency-fails)
- [Groups](#groups)
- [Handlers](#handlers)

## Creating a task
A task is a type (which decides which handler runs it), its data, and where and when it runs. Its data is any
`JsonSerializable`, or an array. It's stored as JSONB, and given back to the handler as an array (so an object's keys
may come back in another order). Everything is checked when the task is created, so a mistake throws on that line:

```php
$task = Task::create(
    'send-email',
    ['to' => 'jo@example.com', 'template' => 'welcome'],
    queue: 'emails',                                    // Workers take tasks from the queues they're given.
    priority: 10,                                       // Higher goes first, then the oldest. Defaults to 0.
    notBefore: new DateTimeImmutable('+10 minutes'),    // The earliest it may run. Defaults to straight away.
    leaseSeconds: 60                                    // How long a worker has to finish it. Defaults to 300.
);
```

```php
// Throws InvalidArgumentException
Task::create('', []);
```

A task made with `Task::create()` isn't queued yet. `TaskQueue::enqueue()` returns the tasks as they were added, with
an ID (a UUID v7), a status (`TaskStatus::BLOCKED`, `WAITING` or `RUNNING`) and a created time.

## Dependencies
A task can depend on others, given as a `TaskCollection` (or their IDs, as a `TaskIdCollection`, below). It only
runs once they've all finished. When you're creating a task and the tasks it depends on together, enqueue them all in
one call, `enqueue()` with the tasks, or `enqueueAll()` with a `TaskCollection`. That's one atomic operation: each
task is added after the ones it depends on, whatever order they're given in, and if any can't be added, none are.

```php
use Programster\PgsqlLib\PgSqlConnection;

$connection = PgSqlConnection::create('localhost', 'app', 'app', 'secret');
TaskQueue::createTables($connection);
$queue = new TaskQueue($connection);

$export = Task::create('export-members', ['format' => 'csv']);
$upload = Task::create('upload-file', ['to' => 's3'], dependsOn: new TaskCollection($export));
$added = $queue->enqueueAll(new TaskCollection($upload, $export));
assert($added->get(0)->getStatus() === TaskStatus::BLOCKED);
```

A task can also depend on tasks already in the queue, enqueued in an earlier call, by giving the tasks, or just their
IDs, in a `TaskIdCollection` (which rejects anything that isn't a UUID where it's created):

```php
$exportId = $added->get(1)->getId();
$email = Task::create('email-export', [], dependsOn: new TaskIdCollection($exportId));
$queue->enqueue($email);
```

Each one's existence is checked (and it's held so it can't finish in the meantime), and if one's gone (e.g. it has
already finished, and been removed), nothing is added:

```php
// Throws ExceptionTaskNotFound
$queue->delete($added->get(1));
$queue->enqueue(Task::create('notify-admin', [], dependsOn: new TaskCollection($added->get(1))));
```

A cycle can't be built, as a task can only be given dependencies that already exist when it's created.

## When a dependency fails
Each task chooses what happens to it when a task it depends on fails, with its `onDependencyFailure`:

- **`DependencyFailurePolicy::REMOVE`** (the default): it's removed too, and the tasks depending on it follow their
  own policies in turn. It's included in the worker's `ExceptionTaskFailed`, to put back if wanted.
- **`DependencyFailurePolicy::CONTINUE`:** the failure counts as that dependency having finished, for a task that only
  needed the other to run first, not to succeed (e.g. a clean-up).

```php
$build = Task::create('build-report', []);

$cleanUp = Task::create(
    'delete-temporary-files',
    [],
    onDependencyFailure: DependencyFailurePolicy::CONTINUE,
    dependsOn: new TaskCollection($build)
);

$queue->enqueue($build, $cleanUp);
```

## Groups
A group is any name shared by several tasks, e.g. a UUID for one test run. `fetchGroup()` returns those still in the
queue, so an empty group means they've all been handled (or removed).

```php
$run = 'run-0190a1b2';
$queue->enqueue(Task::create('run-test', ['file' => 'LoginTest.php'], group: $run));
assert($queue->fetchGroup($run)->count() === 1);
```

## Handlers
A handler runs one type of task. It returns when it's done, or throws if it failed. A task that's handled is removed
from the queue, so keeping a record of what was done (or making a task safe to run twice) is up to the handler.

```php
final class ExportMembersHandler implements TaskHandlerInterface
{
    public function getType() : string
    {
        return 'export-members';
    }


    public function handle(Task $task) : void
    {
        $format = $task->getData()['format'];
        // ... export them.
    }
}
```
