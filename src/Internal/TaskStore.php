<?php

/*
 * Every query the queue and its workers run against the task tables, in one place. Each change of a task's status,
 * and what follows from it (releasing or skipping the tasks that depend on it), happens in one transaction, so
 * another worker never sees it half done.
 *
 * Times always come from the clock it's given, never from the database's now(), so an application with its own idea
 * of the time (e.g. a clock an admin can move, for testing) gets the same idea here.
 *
 * @internal - use TaskQueue, and the Worker it creates.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Internal;


use PgSql\Result;
use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlTaskQueue\Batch;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionBatchNotFound;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionCantRetryTask;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionDependencyNotQueued;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionLeaseLost;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskNotFound;
use Programster\PgsqlTaskQueue\NewTask;
use Programster\PgsqlTaskQueue\NewTaskCollection;
use Programster\PgsqlTaskQueue\QueuedTask;
use Programster\PgsqlTaskQueue\QueuedTaskCollection;
use Programster\PgsqlTaskQueue\QueueName;
use Programster\PgsqlTaskQueue\QueueNameCollection;
use Programster\PgsqlTaskQueue\TaskResult;
use Programster\PgsqlTaskQueue\TaskStatus;
use Psr\Clock\ClockInterface;


final readonly class TaskStore
{
    // The columns QueuedTask::createFromRow() reads.
    public const TASK_COLUMNS = 'id, batch_id, queue, type, payload, name, status, priority, attempts, max_attempts,'
        . ' created_at, available_at, started_at, finished_at, duration_ms, worker, result, output, error,'
        . ' skipped_because_id';

    // An unfinished task, which is the only kind a deduplication key is unique among.
    private const UNFINISHED = "('blocked', 'waiting', 'running')";

    private const SCHEMA_PATTERN = '#^[a-z_][a-z0-9_]{0,62}$#';
    private const MAX_BATCH_NAME_LENGTH = 1000;
    private const MAX_LEASE_SECONDS = 86400;

    private PgSqlConnection $m_connection;
    private ClockInterface $m_clock;
    private string $m_taskTable;
    private string $m_dependencyTable;
    private string $m_batchTable;
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
        $this->m_batchTable = "{$escapedSchema}.\"batch\"";
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


    public function insertBatch(string $name) : Batch
    {
        if ($name === '' || mb_strlen($name) > self::MAX_BATCH_NAME_LENGTH || str_contains($name, "\0"))
        {
            $max = self::MAX_BATCH_NAME_LENGTH;
            throw new \InvalidArgumentException("A batch's name must be 1 to {$max} characters with no NUL bytes.");
        }

        $query = "INSERT INTO {$this->m_batchTable} (name, created_at) VALUES ($1, $2) RETURNING id, name, created_at";
        $result = $this->m_connection->queryWithParams($query, [$name, $this->fetchNowAsText()]);
        return Batch::createFromRow(self::fetchOneRow($result));
    }


    public function fetchBatch(int $id) : Batch
    {
        $query = "SELECT id, name, created_at FROM {$this->m_batchTable} WHERE id = $1";
        $row = pg_fetch_assoc($this->m_connection->queryWithParams($query, [$id]));

        if ($row === false)
        {
            throw new ExceptionBatchNotFound("There's no batch {$id}.");
        }

        return Batch::createFromRow($row);
    }


    /**
     * Add the tasks in one transaction, each after the tasks it depends on.
     *
     * @param NewTaskCollection $tasks
     *
     * @param Batch|null $batch
     *
     * @return QueuedTaskCollection - the tasks, in the order they were given. A task whose deduplication key matched
     * an unfinished task is that task.
     *
     * @throws ExceptionDependencyNotQueued - if a task depends on a NewTask that isn't among them.
     * @throws ExceptionTaskNotFound - if a task depends on a QueuedTask that no longer exists.
     */
    public function insertTasks(NewTaskCollection $tasks, ?Batch $batch) : QueuedTaskCollection
    {
        $ordered = self::orderDependenciesFirst($tasks);

        $insertAll = function () use ($ordered, $batch) : array {
            $queuedByObject = [];
            $isAnyWaiting = false;

            foreach ($ordered as $task)
            {
                $dependencyIds = [];

                foreach ($task->getQueuedDependencies() as $queuedDependency)
                {
                    $dependencyIds[] = $queuedDependency->getId();
                }

                foreach ($task->getNewDependencies() as $newDependency)
                {
                    $dependencyIds[] = $queuedByObject[spl_object_id($newDependency)]->getId();
                }

                $queued = $this->insertTask($task, $batch, array_values(array_unique($dependencyIds)));
                $queuedByObject[spl_object_id($task)] = $queued;
                $isAnyWaiting = ($isAnyWaiting || $queued->getStatus() === TaskStatus::WAITING);
            }

            if ($isAnyWaiting)
            {
                $this->notify();
            }

            return $queuedByObject;
        };

        $queuedByObject = $this->inTransaction($insertAll);
        $inGivenOrder = [];

        foreach ($tasks as $task)
        {
            $inGivenOrder[] = $queuedByObject[spl_object_id($task)];
        }

        return new QueuedTaskCollection(...$inGivenOrder);
    }


    /**
     * Claim the next task that's due, from the queues, of a class there's a handler for, and start its lease.
     *
     * Returns null when there's nothing to claim, which is the usual case for an idle worker, rather than an error,
     * so it isn't thrown.
     *
     * @param QueueNameCollection $queues
     *
     * @param list<string> $taskClasses
     *
     * @param string $worker - who's claiming it.
     *
     * @return ClaimedTask|null
     */
    public function claimNext(QueueNameCollection $queues, array $taskClasses, string $worker) : ?ClaimedTask
    {
        $queueNames = [];

        foreach ($queues as $queue)
        {
            $queueNames[] = $queue->getName();
        }

        $columns = self::TASK_COLUMNS;

        $query = <<<SQL
            UPDATE {$this->m_taskTable}
            SET status = 'running', attempts = attempts + 1, worker = $1, started_at = $2,
                lease_expires_at = $2::timestamptz + make_interval(secs => lease_seconds)
            WHERE id = (
                SELECT id FROM {$this->m_taskTable}
                WHERE status = 'waiting' AND queue = ANY($3::text[]) AND type = ANY($4::text[]) AND available_at <= $2
                ORDER BY priority DESC, expected_duration_ms DESC NULLS LAST, id
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            )
            RETURNING {$columns}, retry_delays_seconds
            SQL;

        $params = [
            $worker,
            $this->fetchNowAsText(),
            SqlValues::formatTextArray($queueNames),
            SqlValues::formatTextArray($taskClasses),
        ];

        $result = $this->m_connection->queryWithParams($query, $params);
        $row = pg_fetch_assoc($result);

        if ($row === false)
        {
            $claimed = null;
        }
        else
        {
            $delays = SqlValues::parseIntArray((string)$row['retry_delays_seconds']);
            $claimed = new ClaimedTask(QueuedTask::createFromRow($row), $worker, $delays);
        }

        return $claimed;
    }


    /**
     * Extend a claimed task's lease, to the given number of seconds from now.
     * @param ClaimedTask $claimed
     * @param int $seconds
     * @throws \InvalidArgumentException - if the seconds are out of range.
     * @throws ExceptionLeaseLost - if the worker no longer holds the task.
     */
    public function extendLease(ClaimedTask $claimed, int $seconds) : void
    {
        if ($seconds < 1 || $seconds > self::MAX_LEASE_SECONDS)
        {
            $max = self::MAX_LEASE_SECONDS;
            throw new \InvalidArgumentException("A lease can be extended by 1 to {$max} seconds, not {$seconds}.");
        }

        $query = <<<SQL
            UPDATE {$this->m_taskTable} SET lease_expires_at = $4::timestamptz + make_interval(secs => $5)
            WHERE id = $1 AND status = 'running' AND worker = $2 AND attempts = $3
            SQL;

        $params = [...self::getOwnerParams($claimed), $this->fetchNowAsText(), $seconds];
        $this->assertStillHeld($claimed, $this->m_connection->queryWithParams($query, $params));
    }


    /**
     * Record that a claimed task succeeded, and release the tasks waiting for it.
     * @param ClaimedTask $claimed
     * @param TaskResult $result
     * @param int $durationMs
     * @throws ExceptionLeaseLost - if the worker no longer holds the task, so its result isn't kept.
     */
    public function markSucceeded(ClaimedTask $claimed, TaskResult $result, int $durationMs) : void
    {
        $markAndRelease = function () use ($claimed, $result, $durationMs) : void {
            $query = <<<SQL
                UPDATE {$this->m_taskTable}
                SET status = 'succeeded', finished_at = $4, duration_ms = $5, result = $6::jsonb, output = $7,
                    error = NULL, lease_expires_at = NULL
                WHERE id = $1 AND status = 'running' AND worker = $2 AND attempts = $3
                SQL;

            $params = [
                ...self::getOwnerParams($claimed),
                $this->fetchNowAsText(),
                $durationMs,
                json_encode($result->getData(), JSON_THROW_ON_ERROR),
                $result->getOutput(),
            ];

            $this->assertStillHeld($claimed, $this->m_connection->queryWithParams($query, $params));
            $this->releaseDependents($claimed->getTask()->getId());
            $this->notify();
        };

        $this->inTransaction($markAndRelease);
    }


    /**
     * Record that a claimed task failed. A temporary failure with an attempt left goes back in the queue, after its
     * retry delay. Anything else dies, and skips the tasks that depend on it.
     *
     * @param ClaimedTask $claimed
     *
     * @param string $error - what went wrong.
     *
     * @param bool $isPermanent - whether trying again can't help.
     *
     * @param int $durationMs
     *
     * @return TaskStatus - WAITING if it'll be retried, or DEAD if it died.
     *
     * @throws ExceptionLeaseLost - if the worker no longer holds the task.
     */
    public function markFailed(ClaimedTask $claimed, string $error, bool $isPermanent, int $durationMs) : TaskStatus
    {
        $markFailed = function () use ($claimed, $error, $isPermanent, $durationMs) : TaskStatus {
            $now = $this->m_clock->now();
            $willRetry = (!$isPermanent && $claimed->hasAttemptLeft());

            if ($willRetry)
            {
                $retryAt = $now->modify("+{$claimed->getRetryDelayInSeconds()} seconds");

                $query = <<<SQL
                    UPDATE {$this->m_taskTable}
                    SET status = 'waiting', available_at = $4, duration_ms = $5, error = $6, lease_expires_at = NULL
                    WHERE id = $1 AND status = 'running' AND worker = $2 AND attempts = $3
                    SQL;

                $time = SqlValues::formatTimestamp($retryAt);
                $status = TaskStatus::WAITING;
            }
            else
            {
                $query = <<<SQL
                    UPDATE {$this->m_taskTable}
                    SET status = 'dead', finished_at = $4, duration_ms = $5, error = $6, lease_expires_at = NULL
                    WHERE id = $1 AND status = 'running' AND worker = $2 AND attempts = $3
                    SQL;

                $time = SqlValues::formatTimestamp($now);
                $status = TaskStatus::DEAD;
            }

            $params = [...self::getOwnerParams($claimed), $time, $durationMs, $error];
            $this->assertStillHeld($claimed, $this->m_connection->queryWithParams($query, $params));

            if (!$willRetry)
            {
                $this->skipDependents($claimed->getTask());
            }

            $this->notify();
            return $status;
        };

        return $this->inTransaction($markFailed);
    }


    /**
     * Put a claimed task back in the queue until a later time, without using up an attempt.
     * @param ClaimedTask $claimed
     * @param \DateTimeImmutable $until
     * @param string $reason
     * @throws ExceptionLeaseLost - if the worker no longer holds the task.
     */
    public function markDeferred(ClaimedTask $claimed, \DateTimeImmutable $until, string $reason) : void
    {
        $query = <<<SQL
            UPDATE {$this->m_taskTable}
            SET status = 'waiting', attempts = attempts - 1, available_at = $4, error = $5, lease_expires_at = NULL
            WHERE id = $1 AND status = 'running' AND worker = $2 AND attempts = $3
            SQL;

        $params = [...self::getOwnerParams($claimed), SqlValues::formatTimestamp($until), $reason];
        $this->assertStillHeld($claimed, $this->m_connection->queryWithParams($query, $params));
    }


    /**
     * Deal with every running task whose lease has run out, as its worker must have stopped partway through it:
     * retry it (according to its InterruptedTaskPolicy, if it has an attempt left), or fail it.
     *
     * @return int - how many were found.
     */
    public function recoverExpiredLeases() : int
    {
        $recover = function () : int {
            $query = <<<SQL
                SELECT id, name, attempts, max_attempts, on_interruption, worker FROM {$this->m_taskTable}
                WHERE status = 'running' AND lease_expires_at < $1
                ORDER BY id
                FOR UPDATE SKIP LOCKED
                SQL;

            $now = $this->fetchNowAsText();
            $result = $this->m_connection->queryWithParams($query, [$now]);
            $numRecovered = 0;

            while (($row = pg_fetch_assoc($result)) !== false)
            {
                $this->recoverExpiredLease($row, $now);
                $numRecovered++;
            }

            if ($numRecovered > 0)
            {
                $this->notify();
            }

            return $numRecovered;
        };

        return $this->inTransaction($recover);
    }


    /**
     * Retry a dead task from the start, with all its attempts, and un-skip the tasks that were skipped because it
     * died, so they run once it succeeds.
     *
     * @param int $id
     *
     * @throws ExceptionTaskNotFound
     * @throws ExceptionCantRetryTask - if it hasn't died.
     */
    public function retry(int $id) : void
    {
        $retry = function () use ($id) : void {
            $selectQuery = "SELECT status FROM {$this->m_taskTable} WHERE id = $1 FOR UPDATE";
            $row = pg_fetch_assoc($this->m_connection->queryWithParams($selectQuery, [$id]));

            if ($row === false)
            {
                throw new ExceptionTaskNotFound("There's no task {$id}.");
            }

            if ($row['status'] !== TaskStatus::DEAD->value)
            {
                $message = "Only a dead task can be retried, but task {$id} is {$row['status']}.";
                throw new ExceptionCantRetryTask($message);
            }

            $retryQuery = <<<SQL
                UPDATE {$this->m_taskTable}
                SET status = 'waiting', attempts = 0, available_at = $2, finished_at = NULL, lease_expires_at = NULL
                WHERE id = $1
                SQL;

            $this->m_connection->queryWithParams($retryQuery, [$id, $this->fetchNowAsText()]);

            $unskipQuery = <<<SQL
                UPDATE {$this->m_taskTable} AS t
                SET status = 'blocked', skipped_because_id = NULL, finished_at = NULL, error = NULL,
                    num_unmet_dependencies = (
                        SELECT count(*) FROM {$this->m_dependencyTable} AS d
                        JOIN {$this->m_taskTable} AS x ON x.id = d.depends_on_id
                        WHERE d.task_id = t.id AND x.status <> 'succeeded'
                    )
                WHERE t.skipped_because_id = $1 AND t.status = 'skipped'
                SQL;

            $this->m_connection->queryWithParams($unskipQuery, [$id]);
            $this->notify();
        };

        $this->inTransaction($retry);
    }


    public function fetchTask(int $id) : QueuedTask
    {
        $columns = self::TASK_COLUMNS;
        $query = "SELECT {$columns} FROM {$this->m_taskTable} WHERE id = $1";
        $row = pg_fetch_assoc($this->m_connection->queryWithParams($query, [$id]));

        if ($row === false)
        {
            throw new ExceptionTaskNotFound("There's no task {$id}.");
        }

        return QueuedTask::createFromRow($row);
    }


    public function fetchTasksInBatch(Batch $batch) : QueuedTaskCollection
    {
        $columns = self::TASK_COLUMNS;
        $query = "SELECT {$columns} FROM {$this->m_taskTable} WHERE batch_id = $1 ORDER BY id";
        return self::createTasks($this->m_connection->queryWithParams($query, [$batch->getId()]));
    }


    public function fetchPage(TaskStatus $status, QueueName $queue, int $limit, int $offset) : QueuedTaskCollection
    {
        if ($limit < 1 || $offset < 0)
        {
            throw new \InvalidArgumentException("The limit must be at least 1, and the offset at least 0.");
        }

        $columns = self::TASK_COLUMNS;

        $query = <<<SQL
            SELECT {$columns} FROM {$this->m_taskTable}
            WHERE status = $1 AND queue = $2
            ORDER BY id DESC
            LIMIT $3 OFFSET $4
            SQL;

        $params = [$status, $queue->getName(), $limit, $offset];
        return self::createTasks($this->m_connection->queryWithParams($query, $params));
    }


    public function isBatchFinished(Batch $batch) : bool
    {
        $unfinished = self::UNFINISHED;
        $query = "SELECT 1 FROM {$this->m_taskTable} WHERE batch_id = $1 AND status IN {$unfinished} LIMIT 1";
        return (pg_num_rows($this->m_connection->queryWithParams($query, [$batch->getId()])) === 0);
    }


    /**
     * Delete the tasks that finished before a time, and the batches made before it that have no tasks left.
     * @param \DateTimeInterface $before
     * @return int - how many tasks were deleted.
     */
    public function deleteFinishedBefore(\DateTimeInterface $before) : int
    {
        $deleteOld = function () use ($before) : int {
            $time = SqlValues::formatTimestamp($before);

            $taskQuery = <<<SQL
                DELETE FROM {$this->m_taskTable}
                WHERE status IN ('succeeded', 'dead', 'skipped') AND finished_at < $1
                SQL;

            $numDeleted = pg_affected_rows($this->m_connection->queryWithParams($taskQuery, [$time]));

            $batchQuery = <<<SQL
                DELETE FROM {$this->m_batchTable} AS b
                WHERE b.created_at < $1 AND NOT EXISTS (SELECT 1 FROM {$this->m_taskTable} AS t WHERE t.batch_id = b.id)
                SQL;

            $this->m_connection->queryWithParams($batchQuery, [$time]);
            return $numDeleted;
        };

        return $this->inTransaction($deleteOld);
    }


    /**
     * Start listening for the notifications sent when tasks become ready or finish, for waitForNotification().
     */
    public function listen() : void
    {
        $this->m_connection->query("LISTEN {$this->m_channel}");
    }


    public function unlisten() : void
    {
        $this->m_connection->query("UNLISTEN {$this->m_channel}");
    }


    /**
     * Wait until a notification arrives (after listen()), or the time runs out, whichever is first.
     * @param float $seconds - the longest to wait.
     */
    public function waitForNotification(float $seconds) : void
    {
        $resource = $this->m_connection->getResource();

        $socket = pg_socket($resource);

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

            // An interrupted select() (e.g. by SIGTERM) returns false, which is fine: the worker checks why next.
            @stream_select($read, $write, $except, $wholeSeconds, $microseconds);
            $this->drainNotifications();
        }
    }


    /**
     * Read and discard the notifications that have arrived, as which task caused one doesn't matter: the worker just
     * checks the queue again.
     *
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
     * Put the tasks in an order in which each comes after the tasks it depends on, as their IDs are needed to insert
     * it. The order they were given is kept wherever it can be.
     *
     * @param NewTaskCollection $tasks
     *
     * @return list<NewTask>
     *
     * @throws ExceptionDependencyNotQueued - if a task depends on a NewTask that isn't among them.
     */
    private static function orderDependenciesFirst(NewTaskCollection $tasks) : array
    {
        $isGiven = [];

        foreach ($tasks as $task)
        {
            $isGiven[spl_object_id($task)] = true;
        }

        $ordered = [];
        $isOrdered = [];

        // Adds a task to the order, after the tasks it depends on.
        $addInOrder = function (NewTask $task) use (&$addInOrder, &$ordered, &$isOrdered, $isGiven) : void {
            if (!isset($isOrdered[spl_object_id($task)]))
            {
                foreach ($task->getNewDependencies() as $dependency)
                {
                    if (!isset($isGiven[spl_object_id($dependency)]))
                    {
                        throw new ExceptionDependencyNotQueued(
                            "A task depends on a NewTask that isn't being enqueued with it. Enqueue them together in "
                            . "one enqueueAll() call, or depend on the QueuedTask that enqueuing it returned."
                        );
                    }

                    $addInOrder($dependency);
                }

                $isOrdered[spl_object_id($task)] = true;
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
     * Insert one task, with its dependencies, which must already exist.
     * @param NewTask $task
     * @param Batch|null $batch
     * @param list<int> $dependencyIds
     * @return QueuedTask - the task, or the unfinished task with the same deduplication key, if there is one.
     * @throws ExceptionTaskNotFound - if a dependency no longer exists.
     */
    private function insertTask(NewTask $task, ?Batch $batch, array $dependencyIds) : QueuedTask
    {
        $now = $this->m_clock->now();
        $options = $task->getOptions();
        $numUnmet = 0;
        $deadDependency = null;

        if (count($dependencyIds) > 0)
        {
            // FOR SHARE stops a dependency finishing until this transaction commits, so it can't finish between being
            // checked here and its dependency row being added, which would leave this task blocked forever.
            $query = <<<SQL
                SELECT id, name, status, skipped_because_id FROM {$this->m_taskTable}
                WHERE id = ANY($1::bigint[])
                FOR SHARE
                SQL;

            $result = $this->m_connection->queryWithParams($query, [SqlValues::formatIntArray($dependencyIds)]);

            if (pg_num_rows($result) !== count($dependencyIds))
            {
                throw new ExceptionTaskNotFound("A task depends on a task that no longer exists.");
            }

            while (($row = pg_fetch_assoc($result)) !== false)
            {
                $numUnmet += ($row['status'] === TaskStatus::SUCCEEDED->value) ? 0 : 1;
                $isDead = in_array($row['status'], [TaskStatus::DEAD->value, TaskStatus::SKIPPED->value], true);

                if ($isDead && $deadDependency === null)
                {
                    $deadDependency = $row;
                }
            }
        }

        if ($deadDependency !== null)
        {
            $status = TaskStatus::SKIPPED;
            $finishedAt = SqlValues::formatTimestamp($now);
            $error = self::describeSkip((int)$deadDependency['id'], $deadDependency['name']);

            // Retrying the task that died un-skips everything skipped because of it, so it's the one recorded.
            $skippedBecauseId = (int)($deadDependency['skipped_because_id'] ?? $deadDependency['id']);
        }
        else
        {
            $status = ($numUnmet > 0) ? TaskStatus::BLOCKED : TaskStatus::WAITING;
            $finishedAt = null;
            $error = null;
            $skippedBecauseId = null;
        }

        $columns = self::TASK_COLUMNS;
        $unfinished = self::UNFINISHED;

        $insertQuery = <<<SQL
            INSERT INTO {$this->m_taskTable} (
                batch_id, queue, type, payload, name, priority, status, num_unmet_dependencies, max_attempts,
                retry_delays_seconds, on_interruption, lease_seconds, deduplication_key, available_at, created_at,
                finished_at, error, skipped_because_id, expected_duration_ms
            )
            VALUES (
                $1, $2, $3, $4::jsonb, $5, $6, $7, $8, $9, $10::int[], $11, $12, $13, $14, $15, $16, $17, $18,
                (
                    SELECT p.duration_ms FROM {$this->m_taskTable} AS p
                    WHERE p.queue = $2 AND p.name = $5 AND p.status = 'succeeded'
                    ORDER BY p.finished_at DESC
                    LIMIT 1
                )
            )
            ON CONFLICT (queue, deduplication_key) WHERE deduplication_key IS NOT NULL AND status IN {$unfinished}
            DO NOTHING
            RETURNING {$columns}
            SQL;

        $retrySchedule = $options->getRetrySchedule();

        $params = [
            $batch?->getId(),
            $task->getQueue()->getName(),
            get_class($task->getTask()),
            json_encode($task->getTask(), JSON_THROW_ON_ERROR),
            $options->getName(),
            $options->getPriority(),
            $status,
            $numUnmet,
            $retrySchedule->getMaxAttempts(),
            SqlValues::formatIntArray($retrySchedule->getDelaysInSeconds()),
            $options->getOnInterruption(),
            $options->getLeaseSeconds(),
            $options->getDeduplicationKey(),
            SqlValues::formatTimestamp($options->getNotBefore() ?? $now),
            SqlValues::formatTimestamp($now),
            $finishedAt,
            $error,
            $skippedBecauseId,
        ];

        $row = pg_fetch_assoc($this->m_connection->queryWithParams($insertQuery, $params));

        if ($row === false)
        {
            $queued = $this->fetchUnfinishedWithKey($task);
        }
        else
        {
            $queued = QueuedTask::createFromRow($row);

            if (count($dependencyIds) > 0)
            {
                $dependencyQuery = <<<SQL
                    INSERT INTO {$this->m_dependencyTable} (task_id, depends_on_id)
                    SELECT $1, unnest($2::bigint[])
                    SQL;

                $dependencyParams = [$queued->getId(), SqlValues::formatIntArray($dependencyIds)];
                $this->m_connection->queryWithParams($dependencyQuery, $dependencyParams);
            }
        }

        return $queued;
    }


    /**
     * Fetch the unfinished task that stopped a task being inserted, as it has the same deduplication key.
     * @param NewTask $task
     * @return QueuedTask
     * @throws \RuntimeException - if it finished in the meantime, which is very unlikely, and safe to try again.
     */
    private function fetchUnfinishedWithKey(NewTask $task) : QueuedTask
    {
        $columns = self::TASK_COLUMNS;
        $unfinished = self::UNFINISHED;

        $query = <<<SQL
            SELECT {$columns} FROM {$this->m_taskTable}
            WHERE queue = $1 AND deduplication_key = $2 AND status IN {$unfinished}
            SQL;

        $params = [$task->getQueue()->getName(), $task->getOptions()->getDeduplicationKey()];
        $row = pg_fetch_assoc($this->m_connection->queryWithParams($query, $params));

        if ($row === false)
        {
            throw new \RuntimeException("A task with the same deduplication key finished as this one was added.");
        }

        return QueuedTask::createFromRow($row);
    }


    /**
     * Count down the unmet dependencies of the tasks waiting for one that succeeded, and make those with none left
     * ready to claim.
     *
     * @param int $succeededId
     */
    private function releaseDependents(int $succeededId) : void
    {
        $query = <<<SQL
            UPDATE {$this->m_taskTable}
            SET num_unmet_dependencies = num_unmet_dependencies - 1,
                status = CASE WHEN num_unmet_dependencies = 1 THEN 'waiting' ELSE 'blocked' END
            WHERE status = 'blocked'
                AND id IN (SELECT task_id FROM {$this->m_dependencyTable} WHERE depends_on_id = $1)
            SQL;

        $this->m_connection->queryWithParams($query, [$succeededId]);
    }


    /**
     * Skip every task that depends on one that died, directly or through others, as none of them can run now.
     * @param QueuedTask $dead
     */
    private function skipDependents(QueuedTask $dead) : void
    {
        $query = <<<SQL
            WITH RECURSIVE dependent (id) AS (
                SELECT task_id FROM {$this->m_dependencyTable} WHERE depends_on_id = $1
                UNION
                SELECT d.task_id FROM {$this->m_dependencyTable} AS d JOIN dependent ON d.depends_on_id = dependent.id
            )
            UPDATE {$this->m_taskTable}
            SET status = 'skipped', finished_at = $2, skipped_because_id = $1, error = $3
            WHERE status = 'blocked' AND id IN (SELECT id FROM dependent)
            SQL;

        $params = [$dead->getId(), $this->fetchNowAsText(), self::describeSkip($dead->getId(), $dead->getName())];
        $this->m_connection->queryWithParams($query, $params);
    }


    /**
     * Retry or fail one task whose lease ran out.
     * @param array<array-key, string|null> $row - its id, name, attempts, max_attempts, on_interruption and worker.
     * @param string $now
     */
    private function recoverExpiredLease(array $row, string $now) : void
    {
        $attempts = (int)$row['attempts'];
        $canRetry = ($row['on_interruption'] === 'retry' && $attempts < (int)$row['max_attempts']);
        $what = "Interrupted: worker {$row['worker']} stopped partway through attempt {$attempts}";

        if ($canRetry)
        {
            $query = <<<SQL
                UPDATE {$this->m_taskTable}
                SET status = 'waiting', available_at = $2, error = $3, lease_expires_at = NULL
                WHERE id = $1
                SQL;

            $this->m_connection->queryWithParams($query, [$row['id'], $now, "{$what}, so it's being retried."]);
        }
        else
        {
            $query = <<<SQL
                UPDATE {$this->m_taskTable}
                SET status = 'dead', finished_at = $2, error = $3, lease_expires_at = NULL
                WHERE id = $1
                SQL;

            $reason = ($row['on_interruption'] === 'retry')
                ? "{$what}, and it had no attempts left."
                : "{$what}, so it was failed, as it may already have done what it does.";

            $this->m_connection->queryWithParams($query, [$row['id'], $now, $reason]);
            $this->skipDependents($this->fetchTask((int)$row['id']));
        }
    }


    /**
     * @param int $deadId
     * @param string|null $deadName
     * @return string - why a task was skipped.
     */
    private static function describeSkip(int $deadId, ?string $deadName) : string
    {
        $named = ($deadName === null) ? "" : " ({$deadName})";
        return "Skipped, as task {$deadId}{$named}, which it depends on, died.";
    }


    /**
     * The params that make an update only change a task that the worker still holds.
     * @param ClaimedTask $claimed
     * @return list<int|string>
     */
    private static function getOwnerParams(ClaimedTask $claimed) : array
    {
        return [$claimed->getTask()->getId(), $claimed->getWorker(), $claimed->getTask()->getAttempts()];
    }


    /**
     * @param ClaimedTask $claimed
     * @param Result $result - an update that only changes the task if the worker still holds it.
     * @throws ExceptionLeaseLost - if it changed nothing.
     */
    private function assertStillHeld(ClaimedTask $claimed, Result $result) : void
    {
        if (pg_affected_rows($result) !== 1)
        {
            $id = $claimed->getTask()->getId();

            throw new ExceptionLeaseLost(
                "Worker {$claimed->getWorker()} no longer holds task {$id}, as its lease ran out, so this was ignored."
            );
        }
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
     * savepoint within it instead, so the application's transaction is neither committed early nor lost, and the task
     * is only added if the application commits.
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


    /**
     * @param Result $result
     * @return array<array-key, string|null>
     */
    private static function fetchOneRow(Result $result) : array
    {
        $row = pg_fetch_assoc($result);

        if ($row === false)
        {
            throw new \UnexpectedValueException("A query that should have returned a row didn't.");
        }

        return $row;
    }


    private static function createTasks(Result $result) : QueuedTaskCollection
    {
        $tasks = [];

        while (($row = pg_fetch_assoc($result)) !== false)
        {
            $tasks[] = QueuedTask::createFromRow($row);
        }

        return new QueuedTaskCollection(...$tasks);
    }
}
