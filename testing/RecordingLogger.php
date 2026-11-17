<?php

/*
 * A logger that keeps what's logged, as "level: message", for the tests to check.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Testing;


use Psr\Log\AbstractLogger;


final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $m_lines = [];


    /**
     * @param mixed $level
     * @param string|\Stringable $message
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []) : void
    {
        $levelName = is_scalar($level) ? (string)$level : get_debug_type($level);
        $this->m_lines[] = "{$levelName}: {$message}";
    }


    /**
     * Find out whether any line logged contains the text.
     * @param string $text
     * @return bool
     */
    public function hasLineContaining(string $text) : bool
    {
        $found = false;

        foreach ($this->m_lines as $line)
        {
            $found = ($found || str_contains($line, $text));
        }

        return $found;
    }


    # Accessors
    /** @return list<string> */
    public function getLines() : array { return $this->m_lines; }
}
