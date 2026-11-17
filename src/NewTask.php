<?php

/*
 * A task to add to a queue (TaskQueue::enqueue() or enqueueAll()), with the tasks it depends on, which must all
 * succeed before it can run. A dependency is either a task already in the queue (the QueuedTask that enqueuing it
 * returned), or another NewTask enqueued in the same enqueueAll() call.
 *
 * As a NewTask can only be given dependencies that already exist when it's made, a cycle of dependencies (A needs B,
 * which needs A) can't be built, so it never has to be checked for.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class NewTask
{
    private TaskInterface $m_task;
    private QueueName $m_queue;
    private TaskOptions $m_options;
    private NewTaskCollection $m_newDependencies;
    private QueuedTaskCollection $m_queuedDependencies;


    /**
     * @param TaskInterface $task - the task.
     *
     * @param QueueName $queue - the queue to put it in.
     *
     * @param TaskOptions $options - how it's run. Defaults to the default options.
     *
     * @param NewTask|QueuedTask ...$dependencies - the tasks that must all succeed before it can run. If one dies (or
     * is skipped), this one is skipped. A dependency given more than once counts once.
     *
     * @throws \InvalidArgumentException - if the task can't be encoded as JSON, or can't be recreated from it, so the
     * mistake shows here, rather than in a worker later.
     */
    public function __construct(
        TaskInterface $task,
        QueueName $queue,
        TaskOptions $options = new TaskOptions(),
        NewTask|QueuedTask ...$dependencies
    )
    {
        self::assertRoundTrips($task);
        $newDependencies = [];
        $queuedDependencies = [];

        foreach ($dependencies as $dependency)
        {
            if ($dependency instanceof NewTask)
            {
                $newDependencies[spl_object_id($dependency)] = $dependency;
            }
            else
            {
                $queuedDependencies[$dependency->getId()] = $dependency;
            }
        }

        $this->m_task = $task;
        $this->m_queue = $queue;
        $this->m_options = $options;
        $this->m_newDependencies = new NewTaskCollection(...array_values($newDependencies));
        $this->m_queuedDependencies = new QueuedTaskCollection(...array_values($queuedDependencies));
    }


    /**
     * Check the task can be stored as JSON, and recreated from it, as a worker will.
     * @param TaskInterface $task
     * @throws \InvalidArgumentException
     */
    private static function assertRoundTrips(TaskInterface $task) : void
    {
        $class = get_class($task);

        try
        {
            $data = json_decode(json_encode($task, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $exception)
        {
            throw new \InvalidArgumentException("The {$class} task can't be encoded as JSON.", 0, $exception);
        }

        if (!is_array($data))
        {
            throw new \InvalidArgumentException("The {$class} task's jsonSerialize() must give an array.");
        }

        try
        {
            $class::createFromArray($data);
        }
        catch (\Throwable $exception)
        {
            $message = "The {$class} task can't be recreated from its JSON: {$exception->getMessage()}";
            throw new \InvalidArgumentException($message, 0, $exception);
        }
    }


    # Accessors
    public function getTask() : TaskInterface { return $this->m_task; }
    public function getQueue() : QueueName { return $this->m_queue; }
    public function getOptions() : TaskOptions { return $this->m_options; }
    public function getNewDependencies() : NewTaskCollection { return $this->m_newDependencies; }
    public function getQueuedDependencies() : QueuedTaskCollection { return $this->m_queuedDependencies; }
}
