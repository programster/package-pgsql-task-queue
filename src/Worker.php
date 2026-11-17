<?php

/*
 * Takes tasks from its queues, one at a time (runNext()), and runs each with the handler for its type. Run as many as
 * you like, on as many machines as can reach the database: no two ever take the same task. Each needs a database
 * connection of its own, as it listens for notifications on it (waitForTask()).
 *
 * It doesn't loop, log, or handle signals itself, so the application can do those however it likes, e.g.:
 *
 *     while (!$shouldStop)
 *     {
 *         try
 *         {
 *             if (!$worker->runNext())
 *             {
 *                 $worker->waitForTask(1);
 *             }
 *         }
 *         catch (ExceptionTaskFailed $failure)
 *         {
 *             // e.g. log it, or re-enqueue the task and its dependents.
 *         }
 *         catch (ExceptionTasksExpired $expiry)
 *         {
 *             // e.g. log them: their workers stopped partway through them.
 *         }
 *     }
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\PgsqlTaskQueue\Exceptions\ExceptionTaskFailed;
use Programster\PgsqlTaskQueue\Exceptions\ExceptionTasksExpired;
use Programster\PgsqlTaskQueue\Internal\TaskHandlerMap;
use Programster\PgsqlTaskQueue\Internal\TaskStore;


final class Worker
{
    private readonly TaskStore $m_store;

    /** @var list<string> */
    private readonly array $m_queues;

    // Each handler, by the type it handles.
    private readonly TaskHandlerMap $m_handlersByType;

    private readonly string $m_name;
    private bool $m_isListening = false;


    /**
     * @param TaskQueue $queue - the queue to take tasks from.
     *
     * @param array<mixed> $queues - the names of the queues it takes tasks from, e.g. ["emails"].
     *
     * @param TaskHandlerInterface ...$handlers - a handler for each type of task it runs. It only takes tasks of those
     * types.
     *
     * @throws \InvalidArgumentException - if there are no queues or handlers, a queue name is empty, or two handlers
     * handle the same type.
     */
    public function __construct(TaskQueue $queue, array $queues, TaskHandlerInterface ...$handlers)
    {
        if (count($queues) === 0 || count($handlers) === 0)
        {
            throw new \InvalidArgumentException("A worker needs at least one queue and at least one handler.");
        }

        $queueNames = [];

        foreach ($queues as $name)
        {
            if (!is_string($name) || trim($name) === '')
            {
                throw new \InvalidArgumentException("Each of a worker's queues must be a name, not an empty one.");
            }

            $queueNames[] = $name;
        }

        $handlersByType = [];

        foreach ($handlers as $handler)
        {
            if (isset($handlersByType[$handler->getType()]))
            {
                throw new \InvalidArgumentException("Two handlers handle the {$handler->getType()} type of task.");
            }

            $handlersByType[$handler->getType()] = $handler;
        }

        $this->m_store = $queue->getStore();
        $this->m_queues = $queueNames;
        $this->m_handlersByType = new TaskHandlerMap(...$handlersByType);
        $this->m_name = sprintf('%s:%d:%s', gethostname(), getmypid(), bin2hex(random_bytes(4)));
    }


    /**
     * Take the next task that's ready, from the worker's queues, of a type it has a handler for, and run it. Once it
     * succeeds, it's removed from the queue, and the tasks waiting for it are released.
     *
     * First, though, it removes every running task whose lease has run out (its worker must have stopped partway
     * through it), with the tasks that depend on it, as each one's DependencyFailurePolicy says. If there were any, it
     * throws, with them all, rather than going on to run a task.
     *
     * @return bool - whether it ran a task (false when there was none ready).
     *
     * @throws ExceptionTasksExpired - if tasks' leases had run out: they've been removed, with their dependents that
     * couldn't continue without them, and are all in the exception. Nothing was run, so call it again to carry on.
     *
     * @throws ExceptionTaskFailed - if the task's handler threw: the task has been removed, with the tasks that
     * depended on it whose policy is to be removed too. All are in the exception.
     */
    public function runNext() : bool
    {
        $this->m_store->removeAllExpired();
        $claimed = $this->m_store->claimNext($this->m_queues, $this->m_handlersByType->keys(), $this->m_name);

        foreach ($claimed as $task)
        {
            try
            {
                $this->m_handlersByType->get($task->getType())->handle($task);
            }
            catch (\Throwable $cause)
            {
                throw new ExceptionTaskFailed($task, $this->m_store->removeFailed($task), $cause);
            }

            $this->m_store->complete($task, $this->m_name);
        }

        return !$claimed->isEmpty();
    }


    /**
     * Wait until a task may be ready (the database says tasks were added or finished), or the time is up, whichever is
     * first, e.g. after runNext() found nothing to do. It returns early if the process is sent a signal.
     *
     * @param float $seconds - the longest to wait.
     *
     * @return void
     */
    public function waitForTask(float $seconds) : void
    {
        if (!$this->m_isListening)
        {
            $this->m_store->listen();
            $this->m_isListening = true;
        }

        $this->m_store->waitForNotification($seconds);
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
}
