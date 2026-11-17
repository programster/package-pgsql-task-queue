<?php

/*
 * The name of a queue, e.g. "emails" or "tests". Each task goes in one queue, and each worker takes tasks from the
 * queues it's given, so separate kinds of work can have workers of their own. A name is checked when it's made, so a
 * mistyped one fails where it's written, rather than leaving tasks that no worker ever takes.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class QueueName
{
    private const PATTERN = '#^[a-z0-9][a-z0-9_.-]{0,99}$#';

    private string $m_name;


    /**
     * @param string $name - the name: 1 to 100 lowercase letters, digits, underscores, dots and dashes, starting with
     * a letter or digit, e.g. "emails".
     *
     * @throws \InvalidArgumentException - if it isn't.
     */
    public function __construct(string $name)
    {
        if (preg_match(self::PATTERN, $name) !== 1)
        {
            throw new \InvalidArgumentException(
                "A queue name must be 1 to 100 lowercase letters, digits, underscores, dots and dashes, starting with "
                . "a letter or digit, not \"{$name}\"."
            );
        }

        $this->m_name = $name;
    }


    # Accessors
    public function getName() : string { return $this->m_name; }
}
