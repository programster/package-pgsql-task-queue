<?php

/*
 * A task for the tests, which its handler (TestTaskHandler) runs according to its behaviour.
 */

declare(strict_types = 1);

namespace Programster\PgsqlTaskQueue\Testing;


use Programster\PgsqlTaskQueue\TaskInterface;


final readonly class TestTask implements TaskInterface
{
    private string $m_label;
    private TestTaskBehaviour $m_behaviour;
    private int $m_sleepMs;


    public function __construct(
        string $label,
        TestTaskBehaviour $behaviour = TestTaskBehaviour::SUCCEED,
        int $sleepMs = 0
    )
    {
        $this->m_label = $label;
        $this->m_behaviour = $behaviour;
        $this->m_sleepMs = $sleepMs;
    }


    /**
     * @param array<mixed> $data
     * @return static
     */
    public static function createFromArray(array $data) : static
    {
        $label = $data['label'] ?? null;
        $behaviour = $data['behaviour'] ?? null;
        $sleepMs = $data['sleepMs'] ?? null;

        if (!is_string($label) || !is_string($behaviour) || !is_int($sleepMs))
        {
            throw new \UnexpectedValueException("A TestTask needs a label, behaviour and sleepMs.");
        }

        return new TestTask($label, TestTaskBehaviour::from($behaviour), $sleepMs);
    }


    /**
     * @return array<string, string|int>
     */
    public function jsonSerialize() : array
    {
        return ['label' => $this->m_label, 'behaviour' => $this->m_behaviour->value, 'sleepMs' => $this->m_sleepMs];
    }


    # Accessors
    public function getLabel() : string { return $this->m_label; }
    public function getBehaviour() : TestTaskBehaviour { return $this->m_behaviour; }
    public function getSleepMs() : int { return $this->m_sleepMs; }
}
