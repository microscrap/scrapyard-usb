<?php

namespace Microscrap\ScrapyardUSB\Mpsse;

use Fiber;

/** A place in MpssePump's line: granted once every turn ahead of it has finished. $fiber is where its holder runs (null: the main stack). */
final class MpsseTurn
{
    public bool $granted = false;

    public function __construct(
        public readonly ?Fiber $fiber,
    ) {}
}
