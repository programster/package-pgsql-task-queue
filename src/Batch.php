<?php

/*
 * A group of tasks, e.g. one test run, which can be summarised together (TaskQueue::fetchBatchSummary()), and waited
 * for by workers (WorkerStopConditions). Made by TaskQueue::createBatch().
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class Batch
{
    private int $m_id;
    private string $m_name;
    private \DateTimeImmutable $m_createdAt;


    private function __construct(int $id, string $name, \DateTimeImmutable $createdAt)
    {
        $this->m_id = $id;
        $this->m_name = $name;
        $this->m_createdAt = $createdAt;
    }


    /**
     * Create one from its row in the batch table.
     * @internal - only the package's own code reads the table, as its columns may change between releases.
     * @param array<array-key, string|null> $row
     * @return Batch
     */
    public static function createFromRow(array $row) : Batch
    {
        return new Batch((int)$row['id'], (string)$row['name'], new \DateTimeImmutable((string)$row['created_at']));
    }


    # Accessors
    public function getId() : int { return $this->m_id; }
    public function getName() : string { return $this->m_name; }
    public function getCreatedAt() : \DateTimeImmutable { return $this->m_createdAt; }
}
