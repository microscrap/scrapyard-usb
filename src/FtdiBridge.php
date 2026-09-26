<?php

namespace Microscrap\ScrapyardUSB;

use GeneralPurposeIO\Contracts\Core\GPIOLevelException;

/**
 * Which engine each FTDI interface runs in this process. One interface is a UART or an MPSSE engine, never both:
 * opening one resets the other under whatever uses it. Keys are MPSSE device names ('ft232h', 'ft2232hl-a').
 * Another process holding the interface is refused by libusb at open.
 */
final class FtdiBridge
{
    /** @var array<string, BridgeMode> */
    private static array $modes = [];

    /**
     * @throws GPIOLevelException when $device runs the other engine
     */
    public static function ensureFree(string $device, BridgeMode $mode): void
    {
        $held = self::$modes[$device] ?? null;

        if (! is_null($held) && $held !== $mode) {
            throw GPIOLevelException::ftdiEngineBusy($device, $held->value, $mode->value);
        }
    }

    public static function claim(string $device, BridgeMode $mode): void
    {
        self::ensureFree($device, $mode);

        self::$modes[$device] = $mode;
    }

    public static function release(string $device, BridgeMode $mode): void
    {
        if ((self::$modes[$device] ?? null) === $mode) {
            unset(self::$modes[$device]);
        }
    }

    public static function holder(string $device): ?BridgeMode
    {
        return self::$modes[$device] ?? null;
    }
}
