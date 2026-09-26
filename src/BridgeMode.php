<?php

namespace Microscrap\ScrapyardUSB;

/** The two engines an FTDI interface can run. */
enum BridgeMode: string
{
    case MPSSE = 'mpsse';

    case UART = 'uart';
}
