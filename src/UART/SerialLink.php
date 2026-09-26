<?php

namespace Microscrap\ScrapyardUSB\UART;

/** The FTDI calls a serial port makes, so a test can script the chip. */
interface SerialLink
{
    /** What the chip has sent, up to $max bytes: '' when nothing came within one latency period. */
    public function read(int $max): string;

    /** The chip's modem status word, or a negative libftdi error once the device is gone. */
    public function status(): int;

    /** Starts sending $bytes and returns at once; false when libusb refused them. */
    public function send(string $bytes): bool;

    /** Whether the last send has finished (true when there is none); never blocks. */
    public function sent(): bool;

    /** How many bytes the last finished send wrote; negative when it failed. */
    public function sentBytes(): int;

    /** Blocks up to $timeout_ms (-1: no limit) for the last send to finish. */
    public function awaitSent(int $timeout_ms): void;

    public function setDtr(bool $asserted): int;

    public function setRts(bool $asserted): int;

    public function purge(): void;

    /** Cancels a send still running, then closes the device. */
    public function close(): void;
}
