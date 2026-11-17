<?php

/*
 * A task: the data a handler needs to do one piece of work, e.g. which email to send, or which test to run. It's
 * stored as JSON (jsonSerialize()), under its class's name, and recreated from that JSON (createFromArray()) by the
 * worker that claims it, which gives it to the handler for its class (TaskHandlerInterface).
 *
 * Keep tasks small: an ID to load something by, rather than the thing itself, as it may have changed by the time the
 * task runs.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


interface TaskInterface extends \JsonSerializable
{
    /**
     * Recreate the task from the decoded JSON its jsonSerialize() gave.
     * @param array<mixed> $data
     * @return static
     */
    public static function createFromArray(array $data) : static;


    /**
     * Get the task's data, to be stored as JSON. It must be an array (a JSON object or list), so it can be given back
     * to createFromArray().
     *
     * @return array<mixed>
     */
    public function jsonSerialize() : array;
}
