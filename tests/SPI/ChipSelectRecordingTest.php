<?php

use Microscrap\Bindings\MPSSE\MPSSE;
use Microscrap\ScrapyardUSB\SPI\MpsseClock;
use Microscrap\ScrapyardUSB\SPI\MpsseSPITransport;

it('records a C-line chip select (pin 4, C0) low around the data, with D3 and D4-D7 high throughout', function () {
    $ctx = recordableSpiContext();
    $slave = new MpsseSPITransport(4, $ctx, new MpsseClock($ctx->clock));

    $commands = mpsseCommands($ctx, MPSSE::record($ctx, fn () => $slave->write([0xAB]))->commands);
    $opcodes = array_map(fn (array $command): int => $command[0], $commands);
    $low = array_values(array_filter($commands, fn (array $command): bool => $command[0] === 0x80));
    $high = array_values(array_filter($commands, fn (array $command): bool => $command[0] === 0x82));
    $data = array_search($ctx->tx, $opcodes, true);

    expect(array_filter($low, fn (array $command): bool => ($command[1] & 0xF8) !== 0xF8))->toBe([])  // D3 (0x08) and D4-D7 (0xF0) high in every low-byte state
        ->and(array_column($high, 1))->toBe([0xFE, 0xFF])                                               // C0 low, then high again
        ->and($data)->toBeGreaterThan(array_search(0x82, $opcodes, true))
        ->and($data)->toBeLessThan(array_key_last(array_filter($opcodes, fn (int $op): bool => $op === 0x82)))
        ->and($commands[$data][1])->toBe("\xAB");
});

it('records a D-line chip select (pin 0, D4) low from before start() until after stop(), with D3 high', function () {
    $ctx = recordableSpiContext();
    $slave = new MpsseSPITransport(0, $ctx, new MpsseClock($ctx->clock));

    $commands = mpsseCommands($ctx, MPSSE::record($ctx, fn () => $slave->write([0xAB]))->commands);
    $opcodes = array_map(fn (array $command): int => $command[0], $commands);
    $low = array_values(array_filter($commands, fn (array $command): bool => $command[0] === 0x80));
    $data = array_search($ctx->tx, $opcodes, true);

    expect(array_filter($commands, fn (array $command): bool => $command[0] === 0x82))->toBe([])
        ->and(array_map(fn (array $command): bool => ($command[1] & 0x10) !== 0, $low))->toBe([false, false, false, false, true])   // pin low, start, stop, idle, pin high
        ->and(array_filter($low, fn (array $command): bool => ($command[1] & 0x08) === 0))->toBe([])
        ->and($data)->toBeGreaterThan(array_search(0x80, $opcodes, true))
        ->and($data)->toBeLessThan(array_key_last(array_filter($opcodes, fn (int $op): bool => $op === 0x80)));
});
