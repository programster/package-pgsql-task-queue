<?php

/*
 * What a task did, returned by its handler, and stored with the task: data for code to read (as JSON), and output for
 * people to read (e.g. a test's log), either of which may be empty.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue;


final readonly class TaskResult
{
    /** @var array<mixed> */
    private array $m_data;

    private string $m_output;


    /**
     * @param array<mixed> $data - data about what it did, which must be encodable as JSON. Defaults to none.
     * @param string $output - text about what it did. Defaults to none.
     * @throws \InvalidArgumentException - if the data can't be encoded as JSON, so it fails in the handler, not later.
     */
    public function __construct(array $data = [], string $output = '')
    {
        try
        {
            json_encode($data, JSON_THROW_ON_ERROR);
        }
        catch (\JsonException $exception)
        {
            throw new \InvalidArgumentException("A task's result data must be encodable as JSON.", 0, $exception);
        }

        $this->m_data = $data;
        $this->m_output = $output;
    }


    # Accessors
    /** @return array<mixed> */
    public function getData() : array { return $this->m_data; }
    public function getOutput() : string { return $this->m_output; }
}
