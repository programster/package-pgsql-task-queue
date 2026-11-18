<?php

/*
 * The IDs (UUIDs) of tasks in the queue, e.g. the tasks a new task depends on (Task::create()'s $dependsOn), when the
 * application kept their IDs, rather than the tasks themselves. Each is checked to be a UUID where the collection is
 * created, and kept in lowercase, as the queue gives them.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


use Programster\Collections\AbstractReadonlyCollection;
use Programster\PgsqlLib\PgsqlLib;


/**
 * @extends AbstractReadonlyCollection<string>
 */
final readonly class TaskIdCollection extends AbstractReadonlyCollection
{
    /**
     * @param string ...$ids
     * @throws \InvalidArgumentException - if one isn't a UUID.
     */
    public function __construct(string ...$ids)
    {
        $lowercaseIds = [];

        foreach ($ids as $id)
        {
            if (!PgsqlLib::isValidUuid($id))
            {
                throw new \InvalidArgumentException("A task's ID is a UUID, not \"{$id}\".");
            }

            $lowercaseIds[] = strtolower($id);
        }

        parent::__construct(...$lowercaseIds);
    }
}
