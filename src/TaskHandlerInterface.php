<?php

/*
 * Does the work for one class of task. A worker is given a handler for each class of task it can run, and only claims
 * tasks of those classes, so workers with different handlers can share a queue.
 *
 * To fail the task, throw:
 * - ExceptionTaskFailedPermanently, when trying again can't help (e.g. the email address doesn't exist). The task
 *   dies straight away, and the tasks that depend on it are skipped.
 * - ExceptionTaskDeferred, when it can't run yet, but will be able to later (e.g. a rate limit has been reached). It
 *   goes back in the queue until then, without using up an attempt.
 * - anything else, for a temporary failure, which is retried according to the task's RetrySchedule, and dies once it
 *   has no attempts left.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


interface TaskHandlerInterface
{
    /**
     * Get the class of task this handles.
     * @return class-string<TaskInterface>
     */
    public function getTaskClass() : string;


    /**
     * Do the task's work.
     * @param TaskInterface $task - the task, an instance of getTaskClass().
     * @param TaskContext $context - about this attempt at it, e.g. its number, and a way to extend its lease.
     * @return TaskResult - what it did, which is stored with the task.
     * @throws Exceptions\ExceptionTaskFailedPermanently - if it failed, and trying again can't help.
     * @throws Exceptions\ExceptionTaskDeferred - if it can't run yet.
     * @throws \Throwable - anything else, for a temporary failure.
     */
    public function handle(TaskInterface $task, TaskContext $context) : TaskResult;
}
