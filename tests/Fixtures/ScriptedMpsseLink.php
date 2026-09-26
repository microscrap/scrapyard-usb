<?php

namespace Microscrap\ScrapyardUSB\Tests\Fixtures;

use Microscrap\Bindings\MPSSE\MPSSERecording;
use Microscrap\ScrapyardUSB\Mpsse\MpsseExchange;
use Microscrap\ScrapyardUSB\Mpsse\MpsseLink;

/** A link with no USB behind it: keeps every recording it is handed and answers each on the next poll. */
final class ScriptedMpsseLink implements MpsseLink
{
    /** @var list<MPSSERecording> every recording submitted, oldest first */
    public array $sent = [];

    /** @var list<string|false> one reply per exchange, in order (false: lost); once empty, zeros of the expected length */
    public array $replies = [];

    public function submit(MPSSERecording $recording): MpsseExchange
    {
        $this->sent[] = $recording;

        return new MpsseExchange($recording);
    }

    public function poll(MpsseExchange $exchange): void
    {
        $exchange->settle($this->replies === [] ? str_repeat("\0", $exchange->recording->responseLength()) : array_shift($this->replies));
    }
}
