<?php

namespace Microscrap\ScrapyardUSB\Mpsse;

use Microscrap\Bindings\MPSSE\MPSSERecording;

/** What carries a recorded MPSSE transaction to the engine and its reply back, without blocking. */
interface MpsseLink
{
    /** Starts carrying $recording. The exchange may come back settled already (nothing could be sent). */
    public function submit(MPSSERecording $recording): MpsseExchange;

    /** Moves $exchange along without blocking; settles it once its reply is in, or once the link gives up on it. */
    public function poll(MpsseExchange $exchange): void;
}
