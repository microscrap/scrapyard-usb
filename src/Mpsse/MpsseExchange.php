<?php

namespace Microscrap\ScrapyardUSB\Mpsse;

use Ftdi\FTDITransferControl;
use Microscrap\Bindings\MPSSE\MPSSERecording;

/** One recorded transaction on its way over USB: its two transfers, its deadline, and in the end its reply. */
final class MpsseExchange
{
    public ?FTDITransferControl $read = null;

    public ?FTDITransferControl $write = null;

    public float $deadline = 0.0;

    public bool $done = false;

    /** The reply bytes, or false when a transfer failed or never finished. */
    public string|false $reply = false;

    public function __construct(
        public readonly MPSSERecording $recording,
    ) {}

    public function settle(string|false $reply): static
    {
        [$this->done, $this->reply] = [true, $reply];

        return $this;
    }
}
