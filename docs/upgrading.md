# Upgrading

## 1.0.0-alpha4
- Fixes `fetchWithData($type, [])`, which didn't find the tasks of the type whose data is empty, or a list (as `{}`,
  which it looked for, isn't contained in a list). An empty array now matches every task of the type, as documented.
  Nothing to change when upgrading.

## 1.0.0-alpha3
- Adds `TaskQueue::fetchWithData($type, $data)`, to find tasks by what their data contains (JSONB containment).
- Adds a GIN index on the tasks' data, which `TaskQueue::createTables()` adds to existing tables (it creates only what
  doesn't exist). If you use `getCreateTablesSql()` in your own migrations, add a migration that runs it again, or just
  the new `CREATE INDEX IF NOT EXISTS task_data_idx ...` statement.

## 1.0.0-alpha2
A rewrite, cutting the package down to its one job: scheduling tasks, which may depend on others, for workers to take
and run. It has 9 public classes instead of 26. There's no upgrade path for alpha1's tables: drop the `task_queue`
schema (or your own), and create the new tables with `TaskQueue::createTables()`.

- **One `Task` class** replaces `TaskInterface`, `NewTask`, `QueuedTask` and `TaskOptions`. Create one with
  `Task::create($type, $data, ...)`, where the data is any `JsonSerializable` or array, given back to the handler as an
  array. Its dependencies are a `TaskCollection`, or a `TaskIdCollection` of their IDs (`dependsOn:`).
- **IDs are UUID v7s** (strings), not integers.
- **Groups replace batches:** a task's `group` is any name, e.g. a UUID, and `fetchGroup()` returns those left.
- **A handled task is removed,** rather than kept with its result. Keeping a history is up to the application.
- **No retries, deferral, deduplication or logging.** When a handler throws, the task is removed, with its dependents
  (unless they're set to `DependencyFailurePolicy::CONTINUE`), and `Worker::runNext()` throws `ExceptionTaskFailed`
  with them all, so they can be enqueued again as they are.
- **A task whose lease runs out is removed,** with its dependents, the next time a worker calls `runNext()`, which
  then throws `ExceptionTasksExpired`, with an `ExpiredTask` for each. This replaces `InterruptedTaskPolicy`.
- **The worker has no loop:** `Worker::run()`, its stop conditions, signal handling and logger are gone. Call
  `runNext()` and `waitForTask()` in a loop of your own (see [Workers](workers.md)).
- **No migrations:** `TaskQueueSchema::migrate()` is replaced by `TaskQueue::createTables()`, or
  `TaskQueue::getCreateTablesSql()` for your own migrations. The package no longer needs
  `programster/pgsql-migrations` or `psr/log`, and now needs `ramsey/uuid`.
- **Enqueueing:** `enqueue(Task ...)` or `enqueueAll(TaskCollection)`, both atomic.

## 1.0.0-alpha1
The first release, as an alpha, so anything may still change before 1.0.0.
