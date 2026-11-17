<?php

/*
 * A task: a piece of work of a type (which decides which handler runs it), with its data, in a queue. It's the same
 * class before it's queued (made with create()) and once it's in the queue (returned by TaskQueue::enqueue() or
 * fetched), when it also has an ID (a UUID v7), a status and a created time.
 *
 * A task can depend on others, which must all have finished before it runs. When one fails, the task follows its
 * DependencyFailurePolicy. As a task can only be given dependencies that already exist when it's made, a cycle of
 * dependencies can't be built.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class Task
{
    // PostgreSQL's INT range, which the priority is stored in.
    private const MIN_PRIORITY = -2147483648;
    private const MAX_PRIORITY = 2147483647;

    // A day. A task that takes longer should extend its lease as it goes (TaskQueue::extendLease()).
    private const MAX_LEASE_SECONDS = 86400;

    private const MAX_NAME_LENGTH = 200;

    private ?string $m_id;
    private string $m_type;

    /** @var array<mixed> */
    private array $m_data;

    private string $m_queue;
    private int $m_priority;
    private ?string $m_group;
    private ?\DateTimeImmutable $m_availableAt;
    private int $m_leaseSeconds;
    private DependencyFailurePolicy $m_onDependencyFailure;
    private TaskCollection $m_dependsOn;

    private TaskIdCollection $m_dependsOnIds;

    private ?TaskStatus $m_status;
    private ?\DateTimeImmutable $m_createdAt;


    /**
     * @param string|null $id
     * @param string $type
     * @param array<mixed> $data
     * @param string $queue
     * @param int $priority
     * @param string|null $group
     * @param \DateTimeImmutable|null $availableAt
     * @param int $leaseSeconds
     * @param DependencyFailurePolicy $onDependencyFailure
     * @param TaskCollection $dependsOn - the tasks it depends on that weren't queued when it was made.
     * @param TaskIdCollection $dependsOnIds - the IDs of the queued tasks it depends on.
     * @param TaskStatus|null $status
     * @param \DateTimeImmutable|null $createdAt
     */
    private function __construct(
        ?string $id,
        string $type,
        array $data,
        string $queue,
        int $priority,
        ?string $group,
        ?\DateTimeImmutable $availableAt,
        int $leaseSeconds,
        DependencyFailurePolicy $onDependencyFailure,
        TaskCollection $dependsOn,
        TaskIdCollection $dependsOnIds,
        ?TaskStatus $status,
        ?\DateTimeImmutable $createdAt
    )
    {
        $this->m_id = $id;
        $this->m_type = $type;
        $this->m_data = $data;
        $this->m_queue = $queue;
        $this->m_priority = $priority;
        $this->m_group = $group;
        $this->m_availableAt = $availableAt;
        $this->m_leaseSeconds = $leaseSeconds;
        $this->m_onDependencyFailure = $onDependencyFailure;
        $this->m_dependsOn = $dependsOn;
        $this->m_dependsOnIds = $dependsOnIds;
        $this->m_status = $status;
        $this->m_createdAt = $createdAt;
    }


    /**
     * Create a task, to add to the queue with TaskQueue::enqueue().
     *
     * @param string $type - what kind of task it is, which decides which handler runs it, e.g. "send-email".
     *
     * @param \JsonSerializable|array<mixed> $data - what its handler needs, stored as JSON (JSONB), and given back to
     * the handler as an array (so a JSON object's keys may come back in another order).
     *
     * @param string $queue - the queue to put it in, as each worker takes tasks from the queues it's given. Defaults
     * to "default".
     *
     * @param int $priority - tasks with a higher priority are claimed first, then the oldest. Defaults to 0.
     *
     * @param string|null $group - a name for a group of tasks (e.g. a UUID for a test run), to fetch them together
     * (TaskQueue::fetchGroup()). Defaults to null, for none.
     *
     * @param \DateTimeImmutable|null $notBefore - the earliest it may run. Defaults to null, for straight away.
     *
     * @param int $leaseSeconds - how long a worker has to finish it (or extend its lease) before it's treated as
     * failed, as its worker must have stopped. From 1 to 86400 (a day). Defaults to 300.
     *
     * @param DependencyFailurePolicy $onDependencyFailure - what happens to it if a task it depends on fails.
     * Defaults to REMOVE: it's removed too.
     *
     * @param TaskCollection|TaskIdCollection $dependsOn - the tasks that must finish before it runs, which must still
     * be in the queue when it's enqueued: the tasks themselves (already in the queue, or enqueued in the same call), or
     * the IDs of tasks already in the queue. One given more than once counts once. Defaults to none. (It's a
     * collection, rather than a variadic, so the other arguments can be given by name.)
     *
     * @return Task
     *
     * @throws \InvalidArgumentException - if the type, queue or group is empty or too long, the priority doesn't fit
     * in 32 bits, the lease is out of range, or the data can't be encoded as a JSON object or list.
     */
    public static function create(
        string $type,
        \JsonSerializable|array $data,
        string $queue = 'default',
        int $priority = 0,
        ?string $group = null,
        ?\DateTimeImmutable $notBefore = null,
        int $leaseSeconds = 300,
        DependencyFailurePolicy $onDependencyFailure = DependencyFailurePolicy::REMOVE,
        TaskCollection|TaskIdCollection $dependsOn = new TaskCollection()
    ) : Task
    {
        self::assertValidName($type, "type");
        self::assertValidName($queue, "queue");

        if ($group !== null)
        {
            self::assertValidName($group, "group");
        }

        if ($priority < self::MIN_PRIORITY || $priority > self::MAX_PRIORITY)
        {
            throw new \InvalidArgumentException("A task's priority must fit in 32 bits, not {$priority}.");
        }

        if ($leaseSeconds < 1 || $leaseSeconds > self::MAX_LEASE_SECONDS)
        {
            $max = self::MAX_LEASE_SECONDS;
            $message = "A task's lease must be from 1 to {$max} seconds, not {$leaseSeconds}.";
            throw new \InvalidArgumentException($message);
        }

        $newDependencies = [];
        $queuedDependencyIds = [];

        if ($dependsOn instanceof TaskIdCollection)
        {
            foreach ($dependsOn as $dependencyId)
            {
                $queuedDependencyIds[$dependencyId] = $dependencyId;
            }
        }
        else
        {
            foreach ($dependsOn as $dependency)
            {
                if ($dependency->isQueued())
                {
                    $queuedDependencyIds[$dependency->getId()] = $dependency->getId();
                }
                else
                {
                    $newDependencies[spl_object_id($dependency)] = $dependency;
                }
            }
        }

        return new Task(
            null,
            $type,
            self::normaliseData($data),
            $queue,
            $priority,
            $group,
            $notBefore,
            $leaseSeconds,
            $onDependencyFailure,
            new TaskCollection(...array_values($newDependencies)),
            new TaskIdCollection(...array_values($queuedDependencyIds)),
            null,
            null
        );
    }


    /**
     * Create one from its row in the task table.
     *
     * @internal - only the package's own code reads the table, as its columns may change between releases.
     *
     * @param array<array-key, string|null> $row - the row, with its status worked out, as TaskStore selects it.
     *
     * @param list<string> $dependsOnIds - the IDs of the tasks it depends on that are still in the queue.
     *
     * @return Task
     *
     * @throws \UnexpectedValueException - if its data isn't a JSON object or list.
     */
    public static function createFromRow(array $row, array $dependsOnIds) : Task
    {
        $data = json_decode((string)$row['data'], true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data))
        {
            throw new \UnexpectedValueException("Task {$row['id']}'s data isn't a JSON object or list.");
        }

        return new Task(
            (string)$row['id'],
            (string)$row['type'],
            $data,
            (string)$row['queue'],
            (int)$row['priority'],
            $row['group_name'],
            new \DateTimeImmutable((string)$row['available_at']),
            (int)$row['lease_seconds'],
            DependencyFailurePolicy::from((string)$row['on_dependency_failure']),
            new TaskCollection(),
            new TaskIdCollection(...$dependsOnIds),
            TaskStatus::from((string)$row['status']),
            new \DateTimeImmutable((string)$row['created_at'])
        );
    }


    /**
     * Find out whether it's in the queue (or was, when it was fetched), so it has an ID, a status and a created time.
     * @return bool
     */
    public function isQueued() : bool
    {
        return ($this->m_id !== null);
    }


    /**
     * Get its ID in the queue, a UUID (version 7, so the IDs of tasks added later sort after earlier ones, to the
     * millisecond).
     *
     * @return string
     *
     * @throws \LogicException - if it hasn't been queued (isQueued()).
     */
    public function getId() : string
    {
        if ($this->m_id === null)
        {
            throw new \LogicException("A {$this->m_type} task has no ID until it's been enqueued.");
        }

        return $this->m_id;
    }


    /**
     * Get where it is in the queue, as it was when it was fetched.
     * @return TaskStatus
     * @throws \LogicException - if it hasn't been queued (isQueued()).
     */
    public function getStatus() : TaskStatus
    {
        if ($this->m_status === null)
        {
            throw new \LogicException("A {$this->m_type} task has no status until it's been enqueued.");
        }

        return $this->m_status;
    }


    /**
     * Get when it was added to the queue.
     * @return \DateTimeImmutable
     * @throws \LogicException - if it hasn't been queued (isQueued()).
     */
    public function getCreatedAt() : \DateTimeImmutable
    {
        if ($this->m_createdAt === null)
        {
            throw new \LogicException("A {$this->m_type} task has no created time until it's been enqueued.");
        }

        return $this->m_createdAt;
    }


    /**
     * Check a type, queue or group name isn't empty or too long, as an empty one is almost certainly a mistake (e.g.
     * an unset variable).
     *
     * @param string $name
     *
     * @param string $what - what it is, for the message.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertValidName(string $name, string $what) : void
    {
        if (trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH || str_contains($name, "\0"))
        {
            $max = self::MAX_NAME_LENGTH;
            throw new \InvalidArgumentException("A task's {$what} must be 1 to {$max} characters, not \"{$name}\".");
        }
    }


    /**
     * Get the data as it'll be stored and given back: decoded from its JSON.
     * @param \JsonSerializable|array<mixed> $data
     * @return array<mixed>
     * @throws \InvalidArgumentException - if it can't be encoded as a JSON object or list.
     */
    private static function normaliseData(\JsonSerializable|array $data) : array
    {
        try
        {
            $decoded = json_decode(json_encode($data, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $exception)
        {
            throw new \InvalidArgumentException("A task's data must be encodable as JSON.", 0, $exception);
        }

        if (!is_array($decoded))
        {
            throw new \InvalidArgumentException("A task's data must be a JSON object or list, not a single value.");
        }

        return $decoded;
    }


    # Accessors
    public function getType() : string { return $this->m_type; }
    /** @return array<mixed> */
    public function getData() : array { return $this->m_data; }
    public function getQueue() : string { return $this->m_queue; }
    public function getPriority() : int { return $this->m_priority; }
    public function getGroup() : ?string { return $this->m_group; }
    public function getAvailableAt() : ?\DateTimeImmutable { return $this->m_availableAt; }
    public function getLeaseSeconds() : int { return $this->m_leaseSeconds; }
    public function getOnDependencyFailure() : DependencyFailurePolicy { return $this->m_onDependencyFailure; }
    public function getDependsOn() : TaskCollection { return $this->m_dependsOn; }
    public function getDependsOnIds() : TaskIdCollection { return $this->m_dependsOnIds; }
}
