<?php

/*
 * A queue of tasks in PostgreSQL: add tasks to it (with the tasks each depends on), read them, and cancel them. A
 * Worker takes them and runs them. Create its tables first, with TaskQueue::createTables() (or the SQL from
 * getCreateTablesSql(), in the application's own migrations).
 *
 * A task is removed from the queue once it's been handled, or has failed, so the queue only ever holds the tasks that
 * are still to run. Keeping a record of what was done is up to the application.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlTaskQueue\Internal\SystemClock;
use Programster\PgsqlTaskQueue\Internal\TaskStore;
use Psr\Clock\ClockInterface;


final readonly class TaskQueue
{
    public const DEFAULT_SCHEMA = 'task_queue';

    private TaskStore $m_store;


    /**
     * @param PgSqlConnection $connection - the database the tables are in. A worker needs a connection of its own
     * (made with forceNew: true), as it listens for notifications on it.
     *
     * @param ClockInterface|null $clock - where every time it records comes from, e.g. a clock the application can
     * move, for testing. Defaults to null, for the real time.
     *
     * @param string $schema - the schema the tables are in, as given to createTables(). Defaults to "task_queue".
     *
     * @throws \InvalidArgumentException - if the schema isn't 1 to 63 lowercase letters, digits and underscores.
     */
    public function __construct(
        PgSqlConnection $connection,
        ?ClockInterface $clock = null,
        string $schema = self::DEFAULT_SCHEMA
    )
    {
        $this->m_store = new TaskStore($connection, $clock ?? new SystemClock(), $schema);
    }


    /**
     * Get the SQL that creates the schema and the queue's tables (and their indexes), for an application to run in its
     * own migrations. Everything is created only if it doesn't exist, so it's safe to run again.
     *
     * @param string $schema - the schema to put the tables in. Defaults to "task_queue".
     *
     * @return string - several statements, separated by semicolons.
     *
     * @throws \InvalidArgumentException - if the schema isn't 1 to 63 lowercase letters, digits and underscores.
     */
    public static function getCreateTablesSql(string $schema = self::DEFAULT_SCHEMA) : string
    {
        // The schema is checked to be a plain lowercase identifier, so it's safe to quote like this.
        TaskStore::assertValidSchema($schema);
        $s = '"' . $schema . '"';

        return <<<SQL
            CREATE SCHEMA IF NOT EXISTS {$s};

            CREATE TABLE IF NOT EXISTS {$s}.task (
                id UUID PRIMARY KEY,
                -- The order tasks were added in, as UUID v7s only sort by the millisecond they were made in.
                seq BIGINT GENERATED ALWAYS AS IDENTITY UNIQUE,
                queue TEXT NOT NULL,
                type TEXT NOT NULL,
                data JSONB NOT NULL,
                group_name TEXT NULL,
                priority INT NOT NULL,
                num_unmet_dependencies INT NOT NULL CHECK (num_unmet_dependencies >= 0),
                available_at TIMESTAMPTZ NOT NULL,
                lease_seconds INT NOT NULL CHECK (lease_seconds >= 1),
                lease_expires_at TIMESTAMPTZ NULL,
                worker TEXT NULL,
                on_dependency_failure TEXT NOT NULL CHECK (on_dependency_failure IN ('remove', 'continue')),
                created_at TIMESTAMPTZ NOT NULL
            );

            CREATE TABLE IF NOT EXISTS {$s}.task_dependency (
                task_id UUID NOT NULL REFERENCES {$s}.task (id) ON DELETE CASCADE,
                depends_on_id UUID NOT NULL REFERENCES {$s}.task (id) ON DELETE CASCADE,
                PRIMARY KEY (task_id, depends_on_id)
            );

            -- Finding the tasks that wait for one that has just finished.
            CREATE INDEX IF NOT EXISTS task_dependency_depends_on_idx ON {$s}.task_dependency (depends_on_id);

            -- Claiming the next task, which is the queue's busiest query.
            CREATE INDEX IF NOT EXISTS task_claim_idx ON {$s}.task (queue, priority DESC, seq)
                WHERE num_unmet_dependencies = 0 AND lease_expires_at IS NULL;

            -- Finding running tasks whose lease has run out.
            CREATE INDEX IF NOT EXISTS task_lease_idx ON {$s}.task (lease_expires_at)
                WHERE lease_expires_at IS NOT NULL;

            CREATE INDEX IF NOT EXISTS task_group_idx ON {$s}.task (group_name) WHERE group_name IS NOT NULL;

            -- Finding tasks by their data (fetchWithData()).
            CREATE INDEX IF NOT EXISTS task_data_idx ON {$s}.task USING GIN (data jsonb_path_ops);
            SQL;
    }


    /**
     * Create the schema and the queue's tables (getCreateTablesSql()), if they don't exist, e.g. when the application
     * starts.
     *
     * @param PgSqlConnection $connection - the database.
     *
     * @param string $schema - the schema to put the tables in. Defaults to "task_queue".
     *
     * @return void
     *
     * @throws \InvalidArgumentException - if the schema isn't 1 to 63 lowercase letters, digits and underscores.
     * @throws \Programster\PgsqlLib\Exceptions\ExceptionQueryError - if they can't be created.
     */
    public static function createTables(PgSqlConnection $connection, string $schema = self::DEFAULT_SCHEMA) : void
    {
        $connection->query(self::getCreateTablesSql($schema));
    }


    /**
     * Add tasks to the queue, all or none of them, in one transaction (or, if the connection is already in one, in a
     * savepoint within it, so they're only added if it's committed). Each is added after the ones among them it
     * depends on, whatever order they're given in.
     *
     * A task that was in the queue before (e.g. from an ExceptionTaskFailed) can be given back as it is: it's added
     * afresh, with a new ID, and its dependencies on the others given with it are linked to their new copies.
     *
     * @param Task ...$tasks
     *
     * @return TaskCollection - the tasks, as added (with their IDs), in the order they were given.
     *
     * @throws \LogicException - if a task depends on one that hasn't been queued, and isn't given with it.
     * @throws Exceptions\ExceptionTaskNotFound - if a task depends on one that's no longer in the queue, in which case
     * none of them are added.
     */
    public function enqueue(Task ...$tasks) : TaskCollection
    {
        return $this->enqueueAll(new TaskCollection(...$tasks));
    }


    /**
     * Add a collection of tasks to the queue, as one atomic operation, exactly as enqueue() does: all of them are
     * added, or (if any can't be) none are. Build the whole set of tasks, with their dependencies on each other, and
     * add it in one go, so a task is never linked to one that has already finished and been removed.
     *
     * @param TaskCollection $tasks
     *
     * @return TaskCollection - the tasks, as added (with their IDs), in the order they were given.
     *
     * @throws \LogicException - if a task depends on one that hasn't been queued, and isn't in the collection.
     * @throws Exceptions\ExceptionTaskNotFound - if a task depends on one that's no longer in the queue.
     */
    public function enqueueAll(TaskCollection $tasks) : TaskCollection
    {
        return $this->m_store->insertTasks($tasks);
    }


    /**
     * Fetch a task, as it is now.
     *
     * @param string $id - its ID, a UUID.
     *
     * @return Task
     *
     * @throws Exceptions\ExceptionTaskNotFound - if it isn't in the queue (e.g. it's been handled), or $id isn't a
     * UUID.
     */
    public function fetch(string $id) : Task
    {
        return $this->m_store->fetch($id);
    }


    /**
     * Fetch the tasks still in the queue in a group, oldest first, e.g. to see whether a test run has finished (when
     * there are none left).
     * @param string $group
     * @return TaskCollection
     */
    public function fetchGroup(string $group) : TaskCollection
    {
        return $this->m_store->fetchGroup($group);
    }


    /**
     * Fetch the tasks still in the queue of a type whose data contains the data given, oldest first, using JSONB
     * containment (as PostgreSQL's @> does): every key given must be in the task's data with the same value, and a
     * nested object or list given must be contained in the task's. E.g. to see whether an email to a subscription is
     * already waiting, before queueing another.
     *
     * @param string $type - the type of task, e.g. "send-email".
     *
     * @param array<mixed> $data - what the data must contain, e.g. ['subscriptionId' => 12]. An empty array matches
     * every task of the type.
     *
     * @return TaskCollection
     *
     * @throws \InvalidArgumentException - if $data can't be encoded as JSON.
     */
    public function fetchWithData(string $type, array $data) : TaskCollection
    {
        try
        {
            $tasks = $this->m_store->fetchWithData($type, $data);
        }
        catch (\JsonException $exception)
        {
            throw new \InvalidArgumentException("The data to look for must be encodable as JSON.", 0, $exception);
        }

        return $tasks;
    }


    /**
     * Remove a task that hasn't run yet (or is running) from the queue, as if it had failed: the tasks that depend on
     * it follow their DependencyFailurePolicy.
     *
     * @param Task $task
     *
     * @return TaskCollection - the tasks removed with it, as they were in the queue.
     *
     * @throws Exceptions\ExceptionTaskNotFound - if it isn't in the queue.
     */
    public function delete(Task $task) : TaskCollection
    {
        return $this->m_store->removeFailed($task);
    }


    /**
     * Extend a running task's lease, to the given number of seconds from now, so it isn't treated as having failed
     * while it's still running. A handler of a long task calls it every so often.
     *
     * @param Task $task - the task being run.
     *
     * @param int $seconds - from 1 to 86400 (a day).
     *
     * @return void
     *
     * @throws \InvalidArgumentException - if the seconds are out of range.
     * @throws Exceptions\ExceptionTaskNotFound - if it isn't running, e.g. its lease had already run out.
     */
    public function extendLease(Task $task, int $seconds) : void
    {
        $this->m_store->extendLease($task, $seconds);
    }


    /**
     * Get the store the queue's workers use.
     * @internal - for Worker.
     * @return TaskStore
     */
    public function getStore() : TaskStore
    {
        return $this->m_store;
    }
}
