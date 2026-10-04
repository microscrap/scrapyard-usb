<?php

use Microscrap\Bindings\MPSSE\Enums\MPSSEEndianness;
use Microscrap\Bindings\MPSSE\Enums\MPSSEMode;
use Microscrap\Bindings\MPSSE\MPSSE;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\Tests\Fixtures\ScriptedSerialLink;
use Microscrap\ScrapyardUSB\UART\FtdiUARTTransport;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\MailHandler;
use Voyager\IOPools\EventLoop;
use Voyager\IOPools\LoopWaiter;
use Voyager\IOPools\PromiseEngines\GuzzlePromiseEngine;
use Voyager\IOPools\ResourceRegistry;
use Voyager\IOPools\Waiter\StreamSelectWaiterBackend;

pest()->in('Digital', 'I2C', 'SPI', 'Mpsse', 'UART');

/** A loop on the select backend, polling at most every $pace_ms, handing its mail to $mail when given. */
function testLoop(?MailHandler $mail = null, int $pace_ms = 16): EventLoop
{
    $registry = new ResourceRegistry;

    return new EventLoop($registry, new LoopWaiter($registry, new StreamSelectWaiterBackend, $pace_ms * 1_000_000), new GuzzlePromiseEngine, $mail);
}

/**
 * An SPI-mode context that never touches USB, set up the way MpsseSPIConnectionDriver::register() leaves one:
 * D3 parked, every GPIO line high. The setup is recorded and thrown away.
 */
function recordableSpiContext(): MPSSEContext
{
    $ctx = new MPSSEContext;
    $ctx->ftdi = ftdi_new();
    $ctx->open = true;
    $ctx->mode = MPSSEMode::SPI0->value;
    $ctx->status = 1;
    $ctx->xsize = 63 * 1024;
    $ctx->clock = 1_000_000;

    MPSSE::record($ctx, function () use ($ctx) {
        MPSSE::setMode($ctx, MPSSEEndianness::MSB);
        MPSSE::disableHardwareChipSelect($ctx);

        foreach (range(0, 11) as $pin) {
            MPSSE::pinHigh($ctx, $pin);
        }
    });

    return $ctx;
}

/**
 * The stream as [opcode, value]:
 * - SET_BITS_LOW/HIGH carry their value byte.
 * - A write or full-duplex command carries its bytes; a read carries its length.
 * - TCK_DIVISOR carries its divisor.
 * - GET_BITS, the clock-base commands and SEND_IMMEDIATE carry null.
 * @return list<array{int, int|string|null}>
 */
function mpsseCommands(MPSSEContext $ctx, string $stream): array
{
    $commands = [];

    for ($i = 0; $i < strlen($stream);) {
        $op = ord($stream[$i]);

        if ($op === 0x80 || $op === 0x82) {
            $commands[] = [$op, ord($stream[$i + 1])];
            $i += 3;
        } elseif ($op === $ctx->tx || $op === $ctx->txrx) {
            $length = (ord($stream[$i + 1]) | (ord($stream[$i + 2]) << 8)) + 1;
            $commands[] = [$op, substr($stream, $i + 3, $length)];
            $i += 3 + $length;
        } elseif ($op === $ctx->rx) {
            $commands[] = [$op, (ord($stream[$i + 1]) | (ord($stream[$i + 2]) << 8)) + 1];
            $i += 3;
        } elseif ($op === 0x86) {
            $commands[] = [$op, ord($stream[$i + 1]) | (ord($stream[$i + 2]) << 8)];
            $i += 3;
        } elseif (in_array($op, [0x81, 0x83, 0x87, 0x8A, 0x8B], true)) {
            $commands[] = [$op, null];
            $i++;
        } else {
            throw new RuntimeException(sprintf('Unexpected MPSSE opcode 0x%02X at byte %d', $op, $i));
        }
    }

    return $commands;
}

/**
 * A UART port on a scripted FT232H, bound to $loop when given.
 * @return array{FtdiUARTTransport, ScriptedSerialLink}
 */
function scriptedUart(?Loop $loop = null): array
{
    $link = new ScriptedSerialLink;
    $port = new FtdiUARTTransport('ft232h', 115_200, $link, 'ft232h');

    if (! is_null($loop)) {
        $port->resolvesLoopWith(fn (): Loop => $loop);
    }

    return [$port, $link];
}

/** FtdiBridge keeps its claims for the life of the process: every UART test starts and ends with none. */
function forgetBridges(): void
{
    (new ReflectionProperty(Microscrap\ScrapyardUSB\FtdiBridge::class, 'modes'))->setValue(null, []);
}
