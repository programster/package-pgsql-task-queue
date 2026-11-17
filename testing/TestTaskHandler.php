<?php

/*
 * Runs TestTasks, according to their behaviour (TestTaskBehaviour), recording each in the HandledLog.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Testing;


use Programster\PgsqlLib\PgSqlConnection;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskDeferred;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskFailedPermanently;
use Programster\PgsqlTaskQueue\TaskContext;
use Programster\PgsqlTaskQueue\TaskHandlerInterface;
use Programster\PgsqlTaskQueue\TaskInterface;
use Programster\PgsqlTaskQueue\TaskQueue;
use Programster\PgsqlTaskQueue\TaskResult;
use Psr\Clock\ClockInterface;


final readonly class TestTaskHandler implements TaskHandlerInterface
{
    private HandledLog $m_log;
    private ClockInterface $m_clock;
    private TaskQueue $m_queue;
    private PgSqlConnection $m_connection;


    /**
     * @param HandledLog $log - where each task run is recorded.
     * @param ClockInterface $clock - the queue's clock, which LOSE_LEASE moves on, if it's a FakeClock.
     * @param TaskQueue $queue - the queue, which LOSE_LEASE recovers expired leases with.
     * @param PgSqlConnection $connection - where RECORD and HANG record what they did.
     */
    public function __construct(HandledLog $log, ClockInterface $clock, TaskQueue $queue, PgSqlConnection $connection)
    {
        $this->m_log = $log;
        $this->m_clock = $clock;
        $this->m_queue = $queue;
        $this->m_connection = $connection;
    }


    public function getTaskClass() : string
    {
        return TestTask::class;
    }


    public function handle(TaskInterface $task, TaskContext $context) : TaskResult
    {
        if (!$task instanceof TestTask)
        {
            throw new \LogicException("TestTaskHandler was given a " . get_class($task) . ".");
        }

        $this->m_log->add($task->getLabel(), $context->getAttempt());
        $label = $task->getLabel();

        switch ($task->getBehaviour())
        {
            case TestTaskBehaviour::FAIL_TEMPORARILY:
                throw new \RuntimeException("{$label} failed temporarily.");

            case TestTaskBehaviour::FAIL_PERMANENTLY:
                throw new ExceptionTaskFailedPermanently("{$label} failed permanently.");

            case TestTaskBehaviour::DEFER_ONCE:
                if ($context->getTask()->getError() === null)
                {
                    throw new ExceptionTaskDeferred($this->m_clock->now()->modify('+1 hour'), "{$label} must wait.");
                }
                break;

            case TestTaskBehaviour::LOSE_LEASE:
                if ($this->m_clock instanceof FakeClock)
                {
                    $this->m_clock->advance(3600);
                }

                $this->m_queue->recoverExpiredLeases();
                break;

            case TestTaskBehaviour::STOP_WORKER:
                posix_kill((int)getmypid(), SIGTERM);
                break;

            case TestTaskBehaviour::RECORD:
            case TestTaskBehaviour::HANG:
                $this->record($task, $context);
                break;

            case TestTaskBehaviour::SUCCEED:
                break;
        }

        return new TaskResult(['label' => $label], "ran {$label}");
    }


    /**
     * Record when the task started and finished, in public.test_run, by the database's clock, so tasks run by
     * different processes can be compared.
     *
     * @param TestTask $task
     *
     * @param TaskContext $context
     */
    private function record(TestTask $task, TaskContext $context) : void
    {
        $insert = <<<'SQL'
            INSERT INTO public.test_run (label, worker, attempt, started_at)
            VALUES ($1, $2, $3, clock_timestamp())
            RETURNING id
            SQL;

        $params = [$task->getLabel(), $context->getWorker(), $context->getAttempt()];
        $id = (int)pg_fetch_result($this->m_connection->queryWithParams($insert, $params), 0, 0);
        $sleepMs = ($task->getBehaviour() === TestTaskBehaviour::HANG) ? 60000 : $task->getSleepMs();
        usleep($sleepMs * 1000);
        $update = 'UPDATE public.test_run SET finished_at = clock_timestamp() WHERE id = $1';
        $this->m_connection->queryWithParams($update, [$id]);
    }
}
