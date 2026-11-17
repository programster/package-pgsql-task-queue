<?php

/*
 * What TestTaskHandler has run in this process, in order, as "label#attempt", for the tests to check.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Testing;


final class HandledLog
{
    /** @var list<string> */
    private array $m_entries = [];


    public function add(string $label, int $attempt) : void
    {
        $this->m_entries[] = "{$label}#{$attempt}";
    }


    # Accessors
    /** @return list<string> */
    public function getEntries() : array { return $this->m_entries; }
}
