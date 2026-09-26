<?php

namespace Microscrap\ScrapyardUSB\SPI;

/** The engine clock the slaves on one FT232H share: what it was opened at, and which slave speed is on it now. */
final class MpsseClock
{
    /** The speed() of the slave that last set the engine clock, or null while it runs at the connection's clock. */
    public ?int $applied = null;

    /** False once an exchange was lost: what it set may never have reached the engine, so the next call sets it again. */
    public bool $known = true;

    public function __construct(
        public readonly int $connection_hz,
    ) {}
}
