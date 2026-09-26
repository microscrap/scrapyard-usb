<?php

namespace Microscrap\ScrapyardUSB\UART;

/** What the driver registers for an open FTDI UART: the link, the rate it runs at, and the bridge it holds. */
final readonly class FtdiPort
{
    public function __construct(
        public SerialLink $link,
        public int $baud,
        public string $bridge,
    ) {}
}
