<?php

/*
 * A queue of tasks in PostgreSQL: add tasks to it (with the tasks each depends on), see how they're doing, and create
 * the workers that run them. Install its tables first, with TaskQueueSchema::migrate().
 *
 * Every time it records comes from the clock it's given, never from the database, so an application with its own
 * idea of the time (e.g. a clock it can move, for testing) gets the same idea here.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlTaskQueue\Internal\TaskStore;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;


final readonly class TaskQueue
{
    private TaskStore $m_store;


    /**
     * @param PgSqlConnection $connection - the database the tables are in. A worker needs a connection of its own
     * (made with forceNew: true), as it listens for notifications on it, and holds transactions on it.
     *
     * @param ClockInterface $clock - where every time it records comes from, e.g. a SystemClock.
     *
     * @param string $schema - the schema the tables are in, as given to TaskQueueSchema::migrate(). Defaults to
     * "task_queue".
     *
     * @throws \InvalidArgumentException - if the schema isn't 1 to 63 lowercase letters, digits and underscores.
     */
    public function __construct(
        PgSqlConnection $connection,
        ClockInterface $clock,
        string $schema = TaskQueueSchema::DEFAULT_SCHEMA
    )
    {
        $this->m_store = new TaskStore($connection, $clock, $schema);
    }


    /**
     * Create a batch, to put a group of tasks in (e.g. one test run), so they can be summarised and waited for
     * together.
     *
     * @param string $name - e.g. "tests 2026-11-17 10:00". 1 to 1000 characters.
     *
     * @return Batch
     */
    public function createBatch(string $name) : Batch
    {
        return $this->m_store->insertBatch($name);
    }


    /**
     * Fetch a batch, e.g. in a worker process that's given its ID.
     * @param int $id
     * @return Batch
     * @throws Exceptions\ExceptionBatchNotFound
     */
    public function fetchBatch(int $id) : Batch
    {
        return $this->m_store->fetchBatch($id);
    }


    /**
     * Add a task to the queue.
     *
     * @param NewTask $task - the task. Any task it depends on must already be in the queue (a QueuedTask).
     *
     * @param Batch|null $batch - the batch to put it in. Defaults to null, for none.
     *
     * @return QueuedTask - the task, as added. If its deduplication key matched an unfinished task in its queue, it's
     * that task, and nothing was added.
     *
     * @throws Exceptions\ExceptionDependencyNotQueued - if it depends on a NewTask. Use enqueueAll() for both.
     * @throws Exceptions\ExceptionTaskNotFound - if it depends on a QueuedTask that no longer exists.
     */
    public function enqueue(NewTask $task, ?Batch $batch = null) : QueuedTask
    {
        return $this->m_store->insertTasks(new NewTaskCollection($task), $batch)->first();
    }


    /**
     * Add tasks to the queue, all or none of them, in one transaction. A task can depend on others among them, which
     * are added first, whatever order they're given in.
     *
     * @param NewTaskCollection $tasks - the tasks.
     *
     * @param Batch|null $batch - the batch to put them in. Defaults to null, for none.
     *
     * @return QueuedTaskCollection - the tasks, as added, in the order they were given. A task whose deduplication
     * key matched an unfinished task in its queue is that task.
     *
     * @throws Exceptions\ExceptionDependencyNotQueued - if one depends on a NewTask that isn't among them.
     * @throws Exceptions\ExceptionTaskNotFound - if one depends on a QueuedTask that no longer exists.
     */
    public function enqueueAll(NewTaskCollection $tasks, ?Batch $batch = null) : QueuedTaskCollection
    {
        return $this->m_store->insertTasks($tasks, $batch);
    }


    /**
     * Create a worker, to run tasks from some of the queues, e.g. in a long-running process of its own.
     *
     * @param TaskHandlerCollection $handlers - a handler for each class of task it runs. It only claims tasks of
     * these classes.
     *
     * @param QueueNameCollection $queues - the queues it takes tasks from.
     *
     * @param LoggerInterface $logger - where it logs each task's outcome. Defaults to none.
     *
     * @param WorkerStopConditions $stopConditions - when it stops by itself. Defaults to never.
     *
     * @param float $pollSeconds - the longest it waits for a notification before checking the queue again, from 0.01
     * to 3600. Defaults to 1.
     *
     * @return Worker
     *
     * @throws \InvalidArgumentException - if there are no handlers or queues, two handlers handle the same class of
     * task, or the poll time is out of range.
     */
    public function createWorker(
        TaskHandlerCollection $handlers,
        QueueNameCollection $queues,
        LoggerInterface $logger = new NullLogger(),
        WorkerStopConditions $stopConditions = new WorkerStopConditions(),
        float $pollSeconds = 1.0
    ) : Worker
    {
        return new Worker($this->m_store, $handlers, $queues, $logger, $stopConditions, $pollSeconds);
    }


    /**
     * Fetch a task, as it is now.
     * @param int $id
     * @return QueuedTask
     * @throws Exceptions\ExceptionTaskNotFound
     */
    public function fetchTask(int $id) : QueuedTask
    {
        return $this->m_store->fetchTask($id);
    }


    /**
     * Fetch a batch's tasks, and how they stand.
     * @param Batch $batch
     * @return BatchSummary
     */
    public function fetchBatchSummary(Batch $batch) : BatchSummary
    {
        return new BatchSummary($batch, $this->m_store->fetchTasksInBatch($batch));
    }


    /**
     * Fetch a page of the tasks in a queue with a status, newest first, e.g. for an admin page.
     * @param TaskStatus $status
     * @param QueueName $queue
     * @param int $limit - the most to fetch, at least 1.
     * @param int $offset - how many to skip, at least 0. Defaults to 0.
     * @return QueuedTaskCollection
     */
    public function fetchPage(TaskStatus $status, QueueName $queue, int $limit, int $offset = 0) : QueuedTaskCollection
    {
        return $this->m_store->fetchPage($status, $queue, $limit, $offset);
    }


    /**
     * Retry a dead task from the start, with all its attempts, e.g. from an admin page. The tasks that were skipped
     * because it died are un-skipped, so they run once it succeeds.
     *
     * @param int $id
     *
     * @throws Exceptions\ExceptionTaskNotFound
     * @throws Exceptions\ExceptionCantRetryTask - if it hasn't died.
     */
    public function retry(int $id) : void
    {
        $this->m_store->retry($id);
    }


    /**
     * Deal with every running task whose lease has run out, as its worker must have stopped partway through it,
     * retrying or failing each according to its InterruptedTaskPolicy. Workers do this before each claim, so it's
     * only needed when no worker is running.
     *
     * @return int - how many there were.
     */
    public function recoverExpiredLeases() : int
    {
        return $this->m_store->recoverExpiredLeases();
    }


    /**
     * Delete the tasks that finished (succeeded, died or were skipped) before a time, e.g. a month ago, and the
     * batches made before then that have no tasks left, so the tables don't grow forever.
     *
     * @param \DateTimeInterface $before
     *
     * @return int - how many tasks were deleted.
     */
    public function deleteFinishedBefore(\DateTimeInterface $before) : int
    {
        return $this->m_store->deleteFinishedBefore($before);
    }
}
