<?php

/*
 * Does the work for one type of task (Task::getType()). A worker is given a handler for each type it runs, and only
 * claims tasks of those types, so workers with different handlers can share a queue.
 *
 * To fail the task, throw anything: the task is removed from the queue (with the tasks that depend on it, as their
 * policies say), and the worker throws an ExceptionTaskFailed, for whoever runs the worker to deal with (e.g. log it,
 * or re-enqueue the tasks).
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


interface TaskHandlerInterface
{
    /**
     * Get the type of task this handles, e.g. "send-email".
     * @return string
     */
    public function getType() : string;


    /**
     * Do the task's work. Its data is in $task->getData().
     * @param Task $task
     * @return void
     * @throws \Throwable - anything, if it fails.
     */
    public function handle(Task $task) : void;
}
