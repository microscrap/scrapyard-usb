<?php

namespace Microscrap\ScrapyardUSB\Tests\Fixtures;

use Microscrap\ScrapyardUSB\Digital\MpsseDigitalInputTransport;

/** The real MPSSE input with read() scripted: $levels in turn, the last one repeating. Everything else is the shipped class. */
final class ScriptedMpsseDigitalInputTransport extends MpsseDigitalInputTransport
{
    /** @var list<bool> */
    public array $levels = [false];

    public int $samples = 0;

    public function read(): bool
    {
        $this->ensureOpen();

        return $this->levels[min($this->samples++, count($this->levels) - 1)];
    }
}
