<?php

/*
 * A task in a queue, as it was when it was fetched (it may have moved on since, as workers run it).
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class QueuedTask
{
    private int $m_id;
    private ?int $m_batchId;
    private QueueName $m_queue;

    /** @var class-string<TaskInterface> */
    private string $m_taskClass;

    /** @var array<mixed> */
    private array $m_payload;

    private ?string $m_name;
    private TaskStatus $m_status;
    private int $m_priority;
    private int $m_attempts;
    private int $m_maxAttempts;
    private \DateTimeImmutable $m_createdAt;
    private \DateTimeImmutable $m_availableAt;
    private ?\DateTimeImmutable $m_startedAt;
    private ?\DateTimeImmutable $m_finishedAt;
    private ?int $m_durationMs;
    private ?string $m_worker;

    /** @var array<mixed>|null */
    private ?array $m_resultData;

    private ?string $m_output;
    private ?string $m_error;
    private ?int $m_skippedBecauseId;


    /**
     * @param array<array-key, string|null> $row - the task's row, as pg_fetch_assoc() gives it.
     * @throws \UnexpectedValueException - if the row's task class no longer exists, or its JSON can't be decoded.
     */
    private function __construct(array $row)
    {
        $taskClass = (string)$row['type'];

        if (!is_subclass_of($taskClass, TaskInterface::class))
        {
            throw new \UnexpectedValueException("Task {$row['id']}'s class, {$taskClass}, isn't a TaskInterface.");
        }

        $this->m_id = (int)$row['id'];
        $this->m_batchId = ($row['batch_id'] === null) ? null : (int)$row['batch_id'];
        $this->m_queue = new QueueName((string)$row['queue']);
        $this->m_taskClass = $taskClass;
        $this->m_payload = self::decodeJsonArray((string)$row['payload']);
        $this->m_name = $row['name'];
        $this->m_status = TaskStatus::from((string)$row['status']);
        $this->m_priority = (int)$row['priority'];
        $this->m_attempts = (int)$row['attempts'];
        $this->m_maxAttempts = (int)$row['max_attempts'];
        $this->m_createdAt = new \DateTimeImmutable((string)$row['created_at']);
        $this->m_availableAt = new \DateTimeImmutable((string)$row['available_at']);
        $this->m_startedAt = ($row['started_at'] === null) ? null : new \DateTimeImmutable($row['started_at']);
        $this->m_finishedAt = ($row['finished_at'] === null) ? null : new \DateTimeImmutable($row['finished_at']);
        $this->m_durationMs = ($row['duration_ms'] === null) ? null : (int)$row['duration_ms'];
        $this->m_worker = $row['worker'];
        $this->m_resultData = ($row['result'] === null) ? null : self::decodeJsonArray($row['result']);
        $this->m_output = $row['output'];
        $this->m_error = $row['error'];
        $this->m_skippedBecauseId = ($row['skipped_because_id'] === null) ? null : (int)$row['skipped_because_id'];
    }


    /**
     * Create one from its row in the task table.
     * @internal - only the package's own code reads the table, as its columns may change between releases.
     * @param array<array-key, string|null> $row - the row, with the columns TaskStore::TASK_COLUMNS selects.
     * @return QueuedTask
     * @throws \UnexpectedValueException - if the row's task class no longer exists, or its JSON can't be decoded.
     */
    public static function createFromRow(array $row) : QueuedTask
    {
        return new QueuedTask($row);
    }


    /**
     * Recreate the task, as its worker does.
     * @return TaskInterface
     */
    public function getTask() : TaskInterface
    {
        $class = $this->m_taskClass;
        return $class::createFromArray($this->m_payload);
    }


    /**
     * @param string $json
     * @return array<mixed>
     * @throws \UnexpectedValueException
     */
    private static function decodeJsonArray(string $json) : array
    {
        try
        {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $exception)
        {
            throw new \UnexpectedValueException("A task's JSON couldn't be decoded.", 0, $exception);
        }

        if (!is_array($decoded))
        {
            throw new \UnexpectedValueException("A task's JSON should be an object or list.");
        }

        return $decoded;
    }


    # Accessors
    public function getId() : int { return $this->m_id; }
    public function getBatchId() : ?int { return $this->m_batchId; }
    public function getQueue() : QueueName { return $this->m_queue; }
    /** @return class-string<TaskInterface> */
    public function getTaskClass() : string { return $this->m_taskClass; }
    /** @return array<mixed> */
    public function getPayload() : array { return $this->m_payload; }
    public function getName() : ?string { return $this->m_name; }
    public function getStatus() : TaskStatus { return $this->m_status; }
    public function getPriority() : int { return $this->m_priority; }
    public function getAttempts() : int { return $this->m_attempts; }
    public function getMaxAttempts() : int { return $this->m_maxAttempts; }
    public function getCreatedAt() : \DateTimeImmutable { return $this->m_createdAt; }
    public function getAvailableAt() : \DateTimeImmutable { return $this->m_availableAt; }
    public function getStartedAt() : ?\DateTimeImmutable { return $this->m_startedAt; }
    public function getFinishedAt() : ?\DateTimeImmutable { return $this->m_finishedAt; }
    public function getDurationMs() : ?int { return $this->m_durationMs; }
    public function getWorker() : ?string { return $this->m_worker; }
    /** @return array<mixed>|null */
    public function getResultData() : ?array { return $this->m_resultData; }
    public function getOutput() : ?string { return $this->m_output; }
    public function getError() : ?string { return $this->m_error; }
    public function getSkippedBecauseId() : ?int { return $this->m_skippedBecauseId; }
}
