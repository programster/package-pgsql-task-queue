<?php

/*
 * Every query the queue and its workers run against the task tables, in one place. Each change that has knock-on
 * effects (a task finishing releases or removes the tasks that depend on it) happens in one transaction, so another
 * worker never sees it half done.
 *
 * Times always come from the clock it's given, never from the database's now(), so an application with its own idea
 * of the time (e.g. a clock it can move, for testing) gets the same idea here.
 *
 * @internal - use TaskQueue, and Worker.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Internal;


use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlTaskQueue\DependencyFailurePolicy;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskNotFound;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTasksExpired;
use Programster\PgsqlTaskQueue\ExpiredTask;
use Programster\PgsqlTaskQueue\ExpiredTaskCollection;
use Programster\PgsqlTaskQueue\Task;
use Programster\PgsqlTaskQueue\TaskCollection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;


final readonly class TaskStore
{
    // The columns Task::createFromRow() reads, with the status worked out from them.
    private const TASK_COLUMNS = "id, queue, type, data, group_name, priority, available_at, lease_seconds,"
        . " on_dependency_failure, created_at,"
        . " CASE WHEN num_unmet_dependencies > 0 THEN 'blocked' WHEN lease_expires_at IS NOT NULL THEN 'running'"
        . " ELSE 'waiting' END AS status";

    private const SCHEMA_PATTERN = '#^[a-z_][a-z0-9_]{0,62}$#';
    private const MAX_LEASE_SECONDS = 86400;

    private PgSqlConnection $m_connection;
    private ClockInterface $m_clock;
    private string $m_taskTable;
    private string $m_dependencyTable;
    private string $m_channel;


    /**
     * @param PgSqlConnection $connection
     * @param ClockInterface $clock
     * @param string $schema - the schema the tables are in.
     * @throws \InvalidArgumentException - if the schema isn't a lowercase identifier.
     */
    public function __construct(PgSqlConnection $connection, ClockInterface $clock, string $schema)
    {
        self::assertValidSchema($schema);
        $escapedSchema = $connection->escapeIdentifier($schema);
        $this->m_connection = $connection;
        $this->m_clock = $clock;
        $this->m_taskTable = "{$escapedSchema}.\"task\"";
        $this->m_dependencyTable = "{$escapedSchema}.\"task_dependency\"";
        $this->m_channel = $connection->escapeIdentifier("{$schema}_tasks");
    }


    /**
     * Check a schema name is a plain lowercase identifier, which also makes a valid notification channel name.
     * @param string $schema
     * @throws \InvalidArgumentException
     */
    public static function assertValidSchema(string $schema) : void
    {
        if (preg_match(self::SCHEMA_PATTERN, $schema) !== 1)
        {
            throw new \InvalidArgumentException(
                "The schema must be 1 to 63 lowercase letters, digits and underscores, not starting with a digit, not "
                . "\"{$schema}\"."
            );
        }
    }


    /**
     * Add tasks, in one transaction, each after the tasks it depends on.
     *
     * A task that was in the queue before (e.g. one removed when another failed) is added afresh, and its
     * dependencies on others in the same call (by their old IDs) are linked to their new copies.
     *
     * @param TaskCollection $tasks
     *
     * @return TaskCollection - the tasks, as added, in the order they were given.
     *
     * @throws \LogicException - if a task depends on one that isn't queued, and isn't among them.
     * @throws ExceptionTaskNotFound - if a task depends on a queued task that's no longer in the queue.
     */
    public function insertTasks(TaskCollection $tasks) : TaskCollection
    {
        $ordered = self::orderDependenciesFirst($tasks);

        $insertAll = function () use ($ordered) : array {
            $addedByObject = [];
            $newIdsByOldId = [];

            foreach ($ordered as $task)
            {
                $dependencyIds = [];

                foreach ($task->getDependsOn() as $dependency)
                {
                    $dependencyIds[] = $addedByObject[spl_object_id($dependency)]->getId();
                }

                foreach ($task->getDependsOnIds() as $dependencyId)
                {
                    $dependencyIds[] = $newIdsByOldId[$dependencyId] ?? $dependencyId;
                }

                $added = $this->insertTask($task, array_values(array_unique($dependencyIds)));
                $addedByObject[spl_object_id($task)] = $added;

                if ($task->isQueued())
                {
                    $newIdsByOldId[$task->getId()] = $added->getId();
                }
            }

            $this->notify();
            return $addedByObject;
        };

        $addedByObject = $this->inTransaction($insertAll);
        $inGivenOrder = [];

        foreach ($tasks as $task)
        {
            $inGivenOrder[] = $addedByObject[spl_object_id($task)];
        }

        return new TaskCollection(...$inGivenOrder);
    }


    /**
     * Claim the next task that's ready, from the queues, of a type there's a handler for, and start its lease.
     *
     * @param list<string> $queues
     *
     * @param list<string> $types
     *
     * @param string $worker - who's claiming it.
     *
     * @return TaskCollection - the task claimed, or none when nothing was ready, which is the usual case for an idle
     * worker, rather than an error.
     */
    public function claimNext(array $queues, array $types, string $worker) : TaskCollection
    {
        $columns = self::TASK_COLUMNS;

        $query = <<<SQL
            UPDATE {$this->m_taskTable}
            SET worker = $1, lease_expires_at = $2::timestamptz + make_interval(secs => lease_seconds)
            WHERE id = (
                SELECT id FROM {$this->m_taskTable}
                WHERE num_unmet_dependencies = 0 AND lease_expires_at IS NULL
                    AND queue = ANY($3::text[]) AND type = ANY($4::text[]) AND available_at <= $2
                ORDER BY priority DESC, seq
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            )
            RETURNING {$columns}
            SQL;

        $params = [
            $worker,
            $this->fetchNowAsText(),
            SqlValues::formatTextArray($queues),
            SqlValues::formatTextArray($types),
        ];

        $result = $this->m_connection->query($query, $params);
        $claimed = [];

        while (($row = pg_fetch_assoc($result)) !== false)
        {
            $claimed[] = Task::createFromRow($row, $this->fetchDependsOnIds((string)$row['id']));
        }

        return new TaskCollection(...$claimed);
    }


    /**
     * Remove a task that its worker has finished, and release the tasks waiting for it. If its worker no longer holds
     * it (its lease ran out, so it was already removed as failed), there's nothing to do.
     * @param Task $task
     * @param string $worker
     */
    public function complete(Task $task, string $worker) : void
    {
        $completeAndRelease = function () use ($task, $worker) : void {
            $query = "SELECT id FROM {$this->m_taskTable} WHERE id = $1 AND worker = $2 FOR UPDATE";

            if (pg_num_rows($this->m_connection->query($query, [$task->getId(), $worker])) === 1)
            {
                $this->releaseDependents($task->getId(), null);
                $this->m_connection->query("DELETE FROM {$this->m_taskTable} WHERE id = $1", [$task->getId()]);
                $this->notify();
            }
        };

        $this->inTransaction($completeAndRelease);
    }


    /**
     * Remove a task that failed (or is cancelled), and deal with the tasks that depend on it, directly or through
     * others, as each one's DependencyFailurePolicy says: remove it too, or release it as if this one had finished.
     *
     * @param Task $task
     *
     * @return TaskCollection - the tasks removed with it, as they were in the queue.
     *
     * @throws ExceptionTaskNotFound - if it's no longer in the queue.
     */
    public function removeFailed(Task $task) : TaskCollection
    {
        $remove = function () use ($task) : TaskCollection {
            $query = "SELECT id FROM {$this->m_taskTable} WHERE id = $1 FOR UPDATE";

            if (pg_num_rows($this->m_connection->query($query, [$task->getId()])) !== 1)
            {
                throw new ExceptionTaskNotFound("There's no task {$task->getId()} in the queue.");
            }

            $removed = $this->removeWithDependents($task->getId());
            $this->notify();
            return $removed;
        };

        return $this->inTransaction($remove);
    }


    /**
     * Remove every running task whose lease has run out (its worker must have stopped partway through it), one by one,
     * each with the tasks that depend on it, as removeFailed() does. Tasks another worker is removing at the same time
     * are skipped, as that worker removes (and reports) them.
     *
     * @return void
     *
     * @throws ExceptionTasksExpired - if there were any, once they've all been removed, with them all.
     */
    public function removeAllExpired() : void
    {
        $expiredTasks = [];

        $removeExpired = function () use (&$expiredTasks) : void {
            $columns = self::TASK_COLUMNS;

            $query = <<<SQL
                SELECT {$columns} FROM {$this->m_taskTable}
                WHERE lease_expires_at < $1
                ORDER BY seq
                FOR UPDATE SKIP LOCKED
                SQL;

            $result = $this->m_connection->query($query, [$this->fetchNowAsText()]);

            while (($row = pg_fetch_assoc($result)) !== false)
            {
                $task = Task::createFromRow($row, $this->fetchDependsOnIds((string)$row['id']));
                $expiredTasks[] = new ExpiredTask($task, $this->removeWithDependents($task->getId()));
            }

            if (count($expiredTasks) > 0)
            {
                $this->notify();
            }
        };

        // Thrown once they've been committed, as throwing within the transaction would roll their removal back.
        $this->inTransaction($removeExpired);

        if (count($expiredTasks) > 0)
        {
            throw new ExceptionTasksExpired(new ExpiredTaskCollection(...$expiredTasks));
        }
    }


    /**
     * Extend a running task's lease, to the given number of seconds from now.
     * @param Task $task
     * @param int $seconds
     * @throws \InvalidArgumentException - if the seconds are out of range.
     * @throws ExceptionTaskNotFound - if it isn't running (e.g. its lease already ran out, so it was removed).
     */
    public function extendLease(Task $task, int $seconds) : void
    {
        if ($seconds < 1 || $seconds > self::MAX_LEASE_SECONDS)
        {
            $max = self::MAX_LEASE_SECONDS;
            throw new \InvalidArgumentException("A lease can be extended by 1 to {$max} seconds, not {$seconds}.");
        }

        $query = <<<SQL
            UPDATE {$this->m_taskTable} SET lease_expires_at = $2::timestamptz + make_interval(secs => $3)
            WHERE id = $1 AND lease_expires_at IS NOT NULL
            SQL;

        $result = $this->m_connection->query($query, [$task->getId(), $this->fetchNowAsText(), $seconds]);

        if (pg_affected_rows($result) !== 1)
        {
            throw new ExceptionTaskNotFound("Task {$task->getId()} isn't running, so its lease can't be extended.");
        }
    }


    public function fetch(string $id) : Task
    {
        if (!Uuid::isValid($id))
        {
            throw new ExceptionTaskNotFound("There's no task \"{$id}\" in the queue, as that isn't a task's ID.");
        }

        $columns = self::TASK_COLUMNS;
        $query = "SELECT {$columns} FROM {$this->m_taskTable} WHERE id = $1";
        $row = pg_fetch_assoc($this->m_connection->query($query, [$id]));

        if ($row === false)
        {
            throw new ExceptionTaskNotFound("There's no task {$id} in the queue.");
        }

        return Task::createFromRow($row, $this->fetchDependsOnIds($id));
    }


    public function fetchGroup(string $group) : TaskCollection
    {
        $columns = self::TASK_COLUMNS;
        $query = "SELECT {$columns} FROM {$this->m_taskTable} WHERE group_name = $1 ORDER BY seq";
        $result = $this->m_connection->query($query, [$group]);
        $tasks = [];

        while (($row = pg_fetch_assoc($result)) !== false)
        {
            $tasks[] = Task::createFromRow($row, $this->fetchDependsOnIds((string)$row['id']));
        }

        return new TaskCollection(...$tasks);
    }


    /**
     * Start listening for the notifications sent when tasks are added or finish, for waitForNotification().
     */
    public function listen() : void
    {
        $this->m_connection->query("LISTEN {$this->m_channel}");
    }


    /**
     * Wait until a notification arrives (after listen()), or the time runs out, whichever is first.
     * @param float $seconds - the longest to wait.
     */
    public function waitForNotification(float $seconds) : void
    {
        $socket = pg_socket($this->m_connection->getResource());

        if ($socket === false)
        {
            usleep((int)($seconds * 1000000));
        }
        // Something may already have arrived while the worker was busy, which select() wouldn't see.
        elseif (!$this->drainNotifications())
        {
            $wholeSeconds = (int)floor($seconds);
            $microseconds = (int)(($seconds - $wholeSeconds) * 1000000);
            $read = [$socket];
            $write = null;
            $except = null;

            // An interrupted select() (e.g. by SIGTERM) returns false, which is fine: the caller checks why next.
            @stream_select($read, $write, $except, $wholeSeconds, $microseconds);
            $this->drainNotifications();
        }
    }


    /**
     * Read and discard the notifications that have arrived, as which task caused one doesn't matter: the worker just
     * checks the queue again.
     * @return bool - whether there were any.
     */
    private function drainNotifications() : bool
    {
        $resource = $this->m_connection->getResource();
        pg_consume_input($resource);
        $hadAny = false;

        while (pg_get_notify($resource) !== false)
        {
            $hadAny = true;
        }

        return $hadAny;
    }


    /**
     * Put the tasks in an order in which each comes after the tasks among them that it depends on, as their IDs are
     * needed to insert it, keeping the order they were given in wherever it can.
     *
     * @param TaskCollection $tasks
     *
     * @return list<Task>
     *
     * @throws \LogicException - if a task depends on one that isn't queued, and isn't among them.
     */
    private static function orderDependenciesFirst(TaskCollection $tasks) : array
    {
        $isGiven = [];
        $byOldId = [];

        foreach ($tasks as $task)
        {
            $isGiven[spl_object_id($task)] = true;

            if ($task->isQueued())
            {
                $byOldId[$task->getId()] = $task;
            }
        }

        $ordered = [];
        $isOrdered = [];

        // Adds a task to the order, after the tasks among them that it depends on.
        $addInOrder = function (Task $task) use (&$addInOrder, &$ordered, &$isOrdered, $isGiven, $byOldId) : void {
            if (!isset($isOrdered[spl_object_id($task)]))
            {
                $isOrdered[spl_object_id($task)] = true;

                foreach ($task->getDependsOn() as $dependency)
                {
                    if (!isset($isGiven[spl_object_id($dependency)]))
                    {
                        throw new \LogicException(
                            "A {$task->getType()} task depends on a {$dependency->getType()} task that isn't in the "
                            . "queue, and isn't being enqueued with it. Enqueue them together."
                        );
                    }

                    $addInOrder($dependency);
                }

                foreach ($task->getDependsOnIds() as $dependencyId)
                {
                    if (isset($byOldId[$dependencyId]))
                    {
                        $addInOrder($byOldId[$dependencyId]);
                    }
                }

                $ordered[] = $task;
            }
        };

        foreach ($tasks as $task)
        {
            $addInOrder($task);
        }

        return $ordered;
    }


    /**
     * Insert one task, with its dependencies, which must already be in the queue.
     * @param Task $task
     * @param list<string> $dependencyIds
     * @return Task - as added.
     * @throws ExceptionTaskNotFound - if a dependency isn't in the queue.
     */
    private function insertTask(Task $task, array $dependencyIds) : Task
    {
        if (count($dependencyIds) > 0)
        {
            // FOR SHARE stops a dependency finishing until this transaction commits, so it can't finish between being
            // checked here and its dependency row being added, which would leave this task blocked forever.
            $query = "SELECT id FROM {$this->m_taskTable} WHERE id = ANY($1::uuid[]) FOR SHARE";
            $result = $this->m_connection->query($query, [SqlValues::formatTextArray($dependencyIds)]);

            if (pg_num_rows($result) !== count($dependencyIds))
            {
                $type = $task->getType();
                throw new ExceptionTaskNotFound("A {$type} task depends on a task that's no longer in the queue.");
            }
        }

        $now = $this->m_clock->now();
        $columns = self::TASK_COLUMNS;

        $insertQuery = <<<SQL
            INSERT INTO {$this->m_taskTable} (
                id, queue, type, data, group_name, priority, num_unmet_dependencies, available_at, lease_seconds,
                on_dependency_failure, created_at
            )
            VALUES ($11, $1, $2, $3::jsonb, $4, $5, $6, $7, $8, $9, $10)
            RETURNING {$columns}
            SQL;

        $params = [
            $task->getQueue(),
            $task->getType(),
            json_encode($task->getData(), JSON_THROW_ON_ERROR),
            $task->getGroup(),
            $task->getPriority(),
            count($dependencyIds),
            SqlValues::formatTimestamp($task->getAvailableAt() ?? $now),
            $task->getLeaseSeconds(),
            $task->getOnDependencyFailure(),
            SqlValues::formatTimestamp($now),
            Uuid::uuid7()->toString(),
        ];

        $row = pg_fetch_assoc($this->m_connection->query($insertQuery, $params));

        if ($row === false)
        {
            throw new \UnexpectedValueException("Adding a {$task->getType()} task didn't return it.");
        }

        if (count($dependencyIds) > 0)
        {
            $dependencyQuery = <<<SQL
                INSERT INTO {$this->m_dependencyTable} (task_id, depends_on_id)
                SELECT $1::uuid, unnest($2::uuid[])
                SQL;

            $dependencyParams = [(string)$row['id'], SqlValues::formatTextArray($dependencyIds)];
            $this->m_connection->query($dependencyQuery, $dependencyParams);
        }

        return Task::createFromRow($row, $dependencyIds);
    }


    /**
     * Count down the unmet dependencies of the tasks that depend on one that's finished (or failed, for those that
     * continue anyway).
     *
     * @param string $finishedId
     *
     * @param DependencyFailurePolicy|null $onlyWithPolicy - only release those with this policy, or null for all.
     */
    private function releaseDependents(string $finishedId, ?DependencyFailurePolicy $onlyWithPolicy) : void
    {
        $query = <<<SQL
            UPDATE {$this->m_taskTable}
            SET num_unmet_dependencies = num_unmet_dependencies - 1
            WHERE id IN (SELECT task_id FROM {$this->m_dependencyTable} WHERE depends_on_id = $1)
                AND ($2::text IS NULL OR on_dependency_failure = $2::text)
            SQL;

        $this->m_connection->query($query, [$finishedId, $onlyWithPolicy]);
    }


    /**
     * Delete a task that failed, release its dependents that continue anyway, and delete those that don't, with
     * their own dependents in turn, as their policies say.
     *
     * @param string $failedId
     *
     * @return TaskCollection - the dependents deleted, as they were in the queue.
     */
    private function removeWithDependents(string $failedId) : TaskCollection
    {
        $columns = self::TASK_COLUMNS;

        $query = <<<SQL
            SELECT {$columns} FROM {$this->m_taskTable}
            WHERE on_dependency_failure = 'remove'
                AND id IN (SELECT task_id FROM {$this->m_dependencyTable} WHERE depends_on_id = $1)
            ORDER BY seq
            FOR UPDATE
            SQL;

        $result = $this->m_connection->query($query, [$failedId]);
        $directDependents = [];

        while (($row = pg_fetch_assoc($result)) !== false)
        {
            $directDependents[] = Task::createFromRow($row, $this->fetchDependsOnIds((string)$row['id']));
        }

        $this->releaseDependents($failedId, DependencyFailurePolicy::CONTINUE);
        $this->m_connection->query("DELETE FROM {$this->m_taskTable} WHERE id = $1", [$failedId]);
        $removed = $directDependents;

        foreach ($directDependents as $dependent)
        {
            $removed = [...$removed, ...$this->removeWithDependents($dependent->getId())->toArray()];
        }

        return new TaskCollection(...$removed);
    }


    /**
     * @param string $id
     * @return list<string> - the IDs of the tasks it depends on that are still in the queue, oldest first.
     */
    private function fetchDependsOnIds(string $id) : array
    {
        $query = <<<SQL
            SELECT d.depends_on_id FROM {$this->m_dependencyTable} AS d
            JOIN {$this->m_taskTable} AS t ON t.id = d.depends_on_id
            WHERE d.task_id = $1
            ORDER BY t.seq
            SQL;

        $ids = [];

        foreach (pg_fetch_all_columns($this->m_connection->query($query, [$id])) as $dependsOnId)
        {
            $ids[] = (string)$dependsOnId;
        }

        return $ids;
    }


    private function notify() : void
    {
        $this->m_connection->query("NOTIFY {$this->m_channel}");
    }


    private function fetchNowAsText() : string
    {
        return SqlValues::formatTimestamp($this->m_clock->now());
    }


    /**
     * Run some work in a transaction, rolling it back if the work throws. If the connection is already in a
     * transaction (e.g. the application is adding a task along with the change that needs it), the work goes in a
     * savepoint within it instead, so the application's transaction is neither committed early nor lost.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    private function inTransaction(\Closure $work) : mixed
    {
        $isNested = (pg_transaction_status($this->m_connection->getResource()) !== PGSQL_TRANSACTION_IDLE);
        $this->m_connection->query($isNested ? 'SAVEPOINT task_queue' : 'BEGIN');

        try
        {
            $result = $work();
            $this->m_connection->query($isNested ? 'RELEASE SAVEPOINT task_queue' : 'COMMIT');
        }
        catch (\Throwable $exception)
        {
            $this->m_connection->query($isNested ? 'ROLLBACK TO SAVEPOINT task_queue' : 'ROLLBACK');
            throw $exception;
        }

        return $result;
    }
}
