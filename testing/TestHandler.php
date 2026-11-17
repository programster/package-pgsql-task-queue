<?php

/*
 * Handles the tests' tasks (of the type "test"), doing what each one's data says (its "behaviour"), and keeping the
 * labels of those it ran, in order, for the tests to check:
 *
 * - "succeed" (the default): does nothing.
 * - "fail": throws, so the task fails.
 * - "record": records in public.test_run when it started and finished (by the database's clock), after sleeping for its
 *   "sleepMs", for the tests with worker processes.
 * - "hang": records that it started, then sleeps for a minute, for a worker process to be killed partway through.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Testing;


use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlTaskQueue\DependencyFailurePolicy;
use Programster\PgsqlTaskQueue\Task;
use Programster\PgsqlTaskQueue\TaskCollection;
use Programster\PgsqlTaskQueue\TaskIdCollection;
use Programster\PgsqlTaskQueue\TaskHandlerInterface;


final class TestHandler implements TaskHandlerInterface
{
    public const TYPE = 'test';

    private readonly PgSqlConnection $m_connection;
    private readonly string $m_worker;

    /** @var list<string> */
    private array $m_handled = [];


    /**
     * @param PgSqlConnection $connection - where "record" and "hang" record what they did.
     * @param string $worker - who's running it, which they record.
     */
    public function __construct(PgSqlConnection $connection, string $worker = 'test')
    {
        $this->m_connection = $connection;
        $this->m_worker = $worker;
    }


    /**
     * Create a test task, in the "tests" queue unless it's given another.
     *
     * @param string $label - what the tests know it by.
     *
     * @param string $behaviour - what its handler does (see above).
     *
     * @param TaskCollection|TaskIdCollection $dependsOn - the tasks it depends on, or their IDs.
     *
     * @param DependencyFailurePolicy $onDependencyFailure
     *
     * @param int $priority
     *
     * @param string|null $group
     *
     * @param \DateTimeImmutable|null $notBefore
     *
     * @param int $leaseSeconds
     *
     * @param string $queue
     *
     * @param int $sleepMs - how long "record" takes.
     *
     * @return Task
     */
    public static function createTask(
        string $label,
        string $behaviour = 'succeed',
        TaskCollection|TaskIdCollection $dependsOn = new TaskCollection(),
        DependencyFailurePolicy $onDependencyFailure = DependencyFailurePolicy::REMOVE,
        int $priority = 0,
        ?string $group = null,
        ?\DateTimeImmutable $notBefore = null,
        int $leaseSeconds = 300,
        string $queue = 'tests',
        int $sleepMs = 0
    ) : Task
    {
        return Task::create(
            self::TYPE,
            ['label' => $label, 'behaviour' => $behaviour, 'sleepMs' => $sleepMs],
            $queue,
            $priority,
            $group,
            $notBefore,
            $leaseSeconds,
            $onDependencyFailure,
            $dependsOn
        );
    }


    /**
     * Get a test task's label, which the tests know it by.
     * @param Task $task
     * @return string
     */
    public static function getLabel(Task $task) : string
    {
        $label = $task->getData()['label'] ?? null;

        if (!is_string($label))
        {
            throw new \UnexpectedValueException("A {$task->getType()} task has no label.");
        }

        return $label;
    }


    public function getType() : string
    {
        return self::TYPE;
    }


    public function handle(Task $task) : void
    {
        $data = $task->getData();
        $label = self::getLabel($task);
        $sleepMs = $data['sleepMs'] ?? 0;
        $this->m_handled[] = $label;

        switch ($data['behaviour'] ?? 'succeed')
        {
            case 'fail':
                throw new \RuntimeException("{$label} failed.");

            case 'record':
            case 'hang':
                $this->record($label, ($data['behaviour'] === 'hang') ? 60000 : (is_int($sleepMs) ? $sleepMs : 0));
                break;

            default:
                break;
        }
    }


    /**
     * Record when the task started and finished, in public.test_run, by the database's clock, so tasks run by
     * different processes can be compared.
     * @param string $label
     * @param int $sleepMs - how long to take.
     */
    private function record(string $label, int $sleepMs) : void
    {
        $insert = 'INSERT INTO public.test_run (label, worker, started_at)'
            . ' VALUES ($1, $2, clock_timestamp()) RETURNING id';
        $id = (int)pg_fetch_result($this->m_connection->query($insert, [$label, $this->m_worker]), 0, 0);
        usleep($sleepMs * 1000);
        $this->m_connection->query('UPDATE public.test_run SET finished_at = clock_timestamp() WHERE id = $1', [$id]);
    }


    # Accessors
    /** @return list<string> */
    public function getHandled() : array { return $this->m_handled; }
}
