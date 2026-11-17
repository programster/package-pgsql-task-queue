<?php

/*
 * Claims tasks from its queues, one at a time, runs each with the handler for its class, and records how it went,
 * until one of its stop conditions is met, or it's told to stop. Run as many as you like, on as many machines as can
 * reach the database: no two ever claim the same task. Created by TaskQueue::createWorker().
 *
 * While there's nothing to claim, it waits for the database to say tasks are ready (LISTEN/NOTIFY), so it starts on
 * a task as soon as it's added, checking again every so often in case a notification was missed.
 *
 * Before each claim, it deals with any task whose lease has run out (its worker stopped partway through it), retrying
 * or failing it according to its InterruptedTaskPolicy.
 *
 * With the pcntl extension, SIGTERM and SIGINT make it stop once it has finished the task it's on.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\PgsqlTaskQueue\Exceptions\ExceptionLeaseLost;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskDeferred;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskFailedPermanently;
use Programster\PgsqlTaskQueue\Internal\ClaimedTask;
use Programster\PgsqlTaskQueue\Internal\TaskStore;
use Psr\Log\LoggerInterface;


final class Worker
{
    private readonly TaskStore $m_store;
    private readonly TaskHandlerCollection $m_handlers;
    private readonly QueueNameCollection $m_queues;
    private readonly LoggerInterface $m_logger;
    private readonly WorkerStopConditions $m_stopConditions;
    private readonly float $m_pollSeconds;
    private readonly string $m_name;

    /** @var list<string> */
    private readonly array $m_taskClasses;

    // Set by stop(), or by SIGTERM or SIGINT, and checked between tasks.
    private bool $m_shouldStop = false;


    /**
     * @internal - use TaskQueue::createWorker().
     *
     * @param TaskStore $store
     *
     * @param TaskHandlerCollection $handlers - a handler for each class of task it runs.
     *
     * @param QueueNameCollection $queues - the queues it takes tasks from.
     *
     * @param LoggerInterface $logger
     *
     * @param WorkerStopConditions $stopConditions
     *
     * @param float $pollSeconds - the longest it waits for a notification before checking the queue again.
     *
     * @throws \InvalidArgumentException - if there are no handlers or queues, two handlers handle the same class, a
     * handler's class isn't a TaskInterface, or the poll time is out of range.
     */
    public function __construct(
        TaskStore $store,
        TaskHandlerCollection $handlers,
        QueueNameCollection $queues,
        LoggerInterface $logger,
        WorkerStopConditions $stopConditions,
        float $pollSeconds
    )
    {
        if ($handlers->count() === 0 || $queues->count() === 0)
        {
            throw new \InvalidArgumentException("A worker needs at least one handler and at least one queue.");
        }

        if ($pollSeconds < 0.01 || $pollSeconds > 3600)
        {
            throw new \InvalidArgumentException("A worker's poll time must be from 0.01 to 3600 seconds.");
        }

        $this->m_store = $store;
        $this->m_handlers = $handlers;
        $this->m_queues = $queues;
        $this->m_logger = $logger;
        $this->m_stopConditions = $stopConditions;
        $this->m_pollSeconds = $pollSeconds;
        $this->m_name = sprintf('%s:%d:%s', gethostname(), getmypid(), bin2hex(random_bytes(4)));
        $this->m_taskClasses = self::findTaskClasses($handlers);
    }


    /**
     * Run tasks until a stop condition is met, or the worker is told to stop.
     * @return WorkerStopReason - why it stopped.
     */
    public function run() : WorkerStopReason
    {
        $this->m_shouldStop = false;
        $restoreSignalHandlers = $this->installSignalHandlers();
        $this->m_store->listen();
        $startedAt = hrtime(true);
        $numTasks = 0;
        $reason = null;

        try
        {
            while ($reason === null)
            {
                $reason = $this->findStopReason($startedAt, $numTasks);

                if ($reason === null)
                {
                    $this->m_store->recoverExpiredLeases();
                    $claimed = $this->m_store->claimNext($this->m_queues, $this->m_taskClasses, $this->m_name);

                    if ($claimed === null && $this->m_stopConditions->getStopWhenIdle())
                    {
                        $reason = WorkerStopReason::IDLE;
                    }
                    elseif ($claimed === null)
                    {
                        $this->m_store->waitForNotification($this->m_pollSeconds);
                    }
                    else
                    {
                        $this->runClaimed($claimed);
                        $numTasks++;
                    }
                }
            }
        }
        finally
        {
            $this->m_store->unlisten();
            $restoreSignalHandlers();
        }

        $this->m_logger->info("Worker {$this->m_name} stopped ({$reason->value}) after {$numTasks} tasks.");
        return $reason;
    }


    /**
     * Stop once the task being run (if any) has finished, e.g. from a handler, or a signal handler of your own.
     */
    public function stop() : void
    {
        $this->m_shouldStop = true;
    }


    /**
     * Run a claimed task, and record how it went, unless the worker lost it (its lease ran out) in the meantime.
     * @param ClaimedTask $claimed
     */
    private function runClaimed(ClaimedTask $claimed) : void
    {
        try
        {
            $this->runAndRecord($claimed);
        }
        catch (ExceptionLeaseLost $exception)
        {
            $this->m_logger->warning($exception->getMessage());
        }
    }


    /**
     * @param ClaimedTask $claimed
     * @throws ExceptionLeaseLost - if the worker no longer holds the task.
     */
    private function runAndRecord(ClaimedTask $claimed) : void
    {
        $queued = $claimed->getTask();
        $label = self::describe($queued);
        $startedAt = hrtime(true);

        try
        {
            $handler = $this->findHandler($queued->getTaskClass());
            $result = $handler->handle($queued->getTask(), new TaskContext($this->m_store, $claimed));
            $this->m_store->markSucceeded($claimed, $result, self::getMillisecondsSince($startedAt));
            $this->m_logger->info("{$label} succeeded.");
        }
        catch (ExceptionLeaseLost $exception)
        {
            throw $exception;
        }
        catch (ExceptionTaskDeferred $exception)
        {
            $this->m_store->markDeferred($claimed, $exception->getUntil(), $exception->getMessage());
            $until = $exception->getUntil()->format(\DateTimeInterface::ATOM);
            $this->m_logger->info("{$label} was deferred until {$until}: {$exception->getMessage()}");
        }
        catch (ExceptionTaskFailedPermanently $exception)
        {
            $this->recordFailure($claimed, $exception, true, $startedAt);
        }
        catch (\Throwable $exception)
        {
            $this->recordFailure($claimed, $exception, false, $startedAt);
        }
    }


    /**
     * @param ClaimedTask $claimed
     * @param \Throwable $exception - what the handler threw.
     * @param bool $isPermanent
     * @param int|float $startedAt - when it started, from hrtime(true).
     * @throws ExceptionLeaseLost
     */
    private function recordFailure(
        ClaimedTask $claimed,
        \Throwable $exception,
        bool $isPermanent,
        int|float $startedAt
    ) : void
    {
        $where = "{$exception->getFile()}:{$exception->getLine()}";
        $error = get_class($exception) . ": {$exception->getMessage()} @ {$where}";
        $durationMs = self::getMillisecondsSince($startedAt);
        $status = $this->m_store->markFailed($claimed, $error, $isPermanent, $durationMs);
        $label = self::describe($claimed->getTask());

        if ($status === TaskStatus::WAITING)
        {
            $this->m_logger->notice("{$label} failed, and will be retried: {$error}");
        }
        else
        {
            $this->m_logger->error("{$label} died: {$error}");
        }
    }


    /**
     * @param int|float $startedAt - when it started, from hrtime(true).
     * @return ?WorkerStopReason - why the worker should stop, or null to carry on.
     */
    private function findStopReason(int|float $startedAt, int $numTasks) : ?WorkerStopReason
    {
        $conditions = $this->m_stopConditions;
        $maxSeconds = $conditions->getMaxSeconds();
        $maxMemoryBytes = $conditions->getMaxMemoryBytes();
        $maxTasks = $conditions->getMaxTasks();
        $batch = $conditions->getUntilBatchFinished();

        if ($this->m_shouldStop)
        {
            $reason = WorkerStopReason::STOPPED;
        }
        elseif ($maxSeconds !== null && self::getMillisecondsSince($startedAt) >= $maxSeconds * 1000)
        {
            $reason = WorkerStopReason::TIME_LIMIT;
        }
        elseif ($maxMemoryBytes !== null && memory_get_usage(true) > $maxMemoryBytes)
        {
            $reason = WorkerStopReason::MEMORY_LIMIT;
        }
        elseif ($maxTasks !== null && $numTasks >= $maxTasks)
        {
            $reason = WorkerStopReason::TASK_LIMIT;
        }
        elseif ($batch !== null && $this->m_store->isBatchFinished($batch))
        {
            $reason = WorkerStopReason::BATCH_FINISHED;
        }
        else
        {
            $reason = null;
        }

        return $reason;
    }


    /**
     * Make SIGTERM and SIGINT stop the worker between tasks, if the pcntl extension is loaded.
     * @return \Closure(): void - puts back the handlers there were before.
     */
    private function installSignalHandlers() : \Closure
    {
        if (function_exists('pcntl_async_signals'))
        {
            $wasAsync = pcntl_async_signals(true);
            $previousTerm = pcntl_signal_get_handler(SIGTERM);
            $previousInt = pcntl_signal_get_handler(SIGINT);

            $stopWorker = function () : void {
                $this->m_shouldStop = true;
            };

            pcntl_signal(SIGTERM, $stopWorker);
            pcntl_signal(SIGINT, $stopWorker);

            $restore = function () use ($wasAsync, $previousTerm, $previousInt) : void {
                pcntl_signal(SIGTERM, $previousTerm);
                pcntl_signal(SIGINT, $previousInt);
                pcntl_async_signals($wasAsync);
            };
        }
        else
        {
            $restore = function () : void {
                // There was nothing to put back.
            };
        }

        return $restore;
    }


    /**
     * @param class-string<TaskInterface> $taskClass
     * @return TaskHandlerInterface
     * @throws \LogicException - if there's none, which can't happen, as the worker only claims tasks it can handle.
     */
    private function findHandler(string $taskClass) : TaskHandlerInterface
    {
        $isForClass = function (TaskHandlerInterface $handler) use ($taskClass) : bool {
            return ($handler->getTaskClass() === $taskClass);
        };

        $found = $this->m_handlers->filter($isForClass);

        if ($found->count() !== 1)
        {
            throw new \LogicException("Worker {$this->m_name} has no handler for {$taskClass}.");
        }

        return $found->first();
    }


    /**
     * Get the class of task each handler handles, checking each is a TaskInterface, handled only once.
     * @param TaskHandlerCollection $handlers
     * @return list<string>
     * @throws \InvalidArgumentException
     */
    private static function findTaskClasses(TaskHandlerCollection $handlers) : array
    {
        $classes = [];

        foreach ($handlers as $handler)
        {
            $class = $handler->getTaskClass();
            $handlerClass = get_class($handler);

            if (!is_subclass_of($class, TaskInterface::class))
            {
                throw new \InvalidArgumentException("{$handlerClass} handles {$class}, which isn't a TaskInterface.");
            }

            if (in_array($class, $classes, true))
            {
                throw new \InvalidArgumentException("More than one handler handles {$class}, as {$handlerClass} does.");
            }

            $classes[] = $class;
        }

        return $classes;
    }


    private static function describe(QueuedTask $task) : string
    {
        $named = ($task->getName() === null) ? "" : " ({$task->getName()})";
        return "Task {$task->getId()}{$named}, attempt {$task->getAttempts()} of {$task->getMaxAttempts()},";
    }


    /**
     * @param int|float $startedAt - from hrtime(true).
     * @return int
     */
    private static function getMillisecondsSince(int|float $startedAt) : int
    {
        return (int)round((hrtime(true) - $startedAt) / 1000000);
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
}
