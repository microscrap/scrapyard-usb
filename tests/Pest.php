<?php

/*
| scrapyard-usb is the FTDI layer: its transports call ext-ftdi through the
| microscrap bindings. Nothing here loads the extension. What is proven
| locally stays pure PHP: provider registration, device guards, fluent
| factory state. Byte paths are proven against a plugged-in FT232H.
*/
