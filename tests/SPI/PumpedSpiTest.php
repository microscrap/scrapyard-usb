<?php

use GeneralPurposeIO\Contracts\SPI\SPIException;
use Microscrap\Bindings\MPSSE\MPSSE;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\Bindings\MPSSE\MPSSERecording;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalOutputTransport;
use Microscrap\ScrapyardUSB\Mpsse\MpssePump;
use Microscrap\ScrapyardUSB\SPI\MpsseClock;
use Microscrap\ScrapyardUSB\SPI\MpsseSPIConnectionDriver;
use Microscrap\ScrapyardUSB\SPI\MpsseSPITransport;
use Microscrap\ScrapyardUSB\Tests\Fixtures\ScriptedMpsseLink;
use Voyager\Contracts\IOPools\Loop;
use Voyager\IOPools\EventLoop;

/** @return array{MPSSEContext, ScriptedMpsseLink, EventLoop} a recordable context whose pump runs over a scripted link */
function pumpedSpi(): array
{
    $loop = new EventLoop;
    $ctx = recordableSpiContext();
    $link = new ScriptedMpsseLink;
    MpssePump::for($ctx, $loop, $link);

    return [$ctx, $link, $loop];
}

/** D4 (pin 0) in every SET_BITS_LOW of $recording, in order: true = high, the chip deselected. */
function d4Levels(MPSSEContext $ctx, MPSSERecording $recording): array
{
    return array_values(array_map(
        fn (array $command): bool => ($command[1] & 0x10) !== 0,
        array_filter(mpsseCommands($ctx, $recording->commands), fn (array $command): bool => $command[0] === 0x80),
    ));
}

/** The bytes $recording clocks out, joined. */
function clockedOut(MPSSEContext $ctx, MPSSERecording $recording): string
{
    return implode('', array_map(
        fn (array $command): string => $command[1],
        array_filter(mpsseCommands($ctx, $recording->commands), fn (array $command): bool => $command[0] === $ctx->tx || $command[0] === $ctx->txrx),
    ));
}

it('sends a call made in a job fiber as one exchange: chip select down, the data, chip select up', function () {
    [$ctx, $link, $loop] = pumpedSpi();
    $flash = new MpsseSPITransport(0, $ctx, new MpsseClock($ctx->clock));
    $link->replies = ["\xEF\x40\x17"];

    $id = $loop->async(fn () => $flash->writeRead([0x9F], 3))->wait();

    expect($id)->toBe([0xEF, 0x40, 0x17])
        ->and($link->sent)->toHaveCount(1)
        ->and(clockedOut($ctx, $link->sent[0]))->toBe("\x9F")
        ->and($link->sent[0]->responseLength())->toBe(3)
        ->and(d4Levels($ctx, $link->sent[0]))->toBe([false, false, false, false, true]);      // pin low, start, stop, idle, pin high
});

it('keeps one turn for a select() in a job fiber: each call goes out on its own, chip select up last, nobody in between', function () {
    [$ctx, $link, $loop] = pumpedSpi();
    $clock = new MpsseClock($ctx->clock);
    $flash = new MpsseSPITransport(0, $ctx, $clock);
    $other = new MpsseSPITransport(4, $ctx, $clock);
    $gate = new ArrayObject(['open' => false]);
    $link->replies = ['', "\xEF\x40\x17"];

    $held = $loop->async(fn () => $flash->select(function (MpsseSPITransport $f) use ($loop, $gate): array {
        $f->write([0x9F]);
        $loop->until(fn (): bool => $gate['open']);

        return $f->read(3);
    }));
    $cut_in = $loop->async(fn () => $other->write([0x55]));
    $loop->at(0.01, fn () => $gate['open'] = true);

    expect($held->wait())->toBe([0xEF, 0x40, 0x17]);

    $cut_in->wait();

    expect($link->sent)->toHaveCount(4)
        ->and(clockedOut($ctx, $link->sent[0]))->toBe("\x9F")
        ->and(d4Levels($ctx, $link->sent[0]))->toBe([false, false])                     // pin low, start: chip select stays down
        ->and(d4Levels($ctx, $link->sent[1]))->toBe([])                                 // the read alone
        ->and(d4Levels($ctx, $link->sent[2]))->toBe([false, false, true])               // stop, idle, pin high
        ->and(clockedOut($ctx, $link->sent[3]))->toBe("\x55")                          // the other slave, after chip select went up
        ->and($ctx->recording)->toBeNull();
});

it('sends chip select up after a select() body in a job fiber throws', function () {
    [$ctx, $link, $loop] = pumpedSpi();
    $flash = new MpsseSPITransport(0, $ctx, new MpsseClock($ctx->clock));

    $task = $loop->async(fn () => $flash->select(function (MpsseSPITransport $f): never {
        $f->write([0x9F]);

        throw new RuntimeException('chip said no');
    }));

    expect(fn () => $task->wait())->toThrow(RuntimeException::class, 'chip said no')
        ->and($link->sent)->toHaveCount(2)
        ->and(d4Levels($ctx, $link->sent[1]))->toBe([false, false, true])
        ->and($ctx->recording)->toBeNull();
});

it('lets a DigitalIO pin written inside a select() body join the selection instead of starting a recording of its own', function () {
    [$ctx, $link, $loop] = pumpedSpi();
    $display = new MpsseSPITransport(0, $ctx, new MpsseClock($ctx->clock));
    $dc = new MpsseDigitalOutputTransport(5, $ctx);                                     // C1: a display's data/command line
    $high_byte = fn (MPSSERecording $recording): array => array_values(array_column(
        array_filter(mpsseCommands($ctx, $recording->commands), fn (array $command): bool => $command[0] === 0x82),
        1,
    ));

    $loop->async(fn () => $display->select(function (MpsseSPITransport $d) use ($dc): void {
        $d->write([0x2C]);
        $dc->write(false);
        $d->write([0xFF, 0x00]);
    }))->wait();

    expect($link->sent)->toHaveCount(5)                                                 // command, pin, pin read-back, data, chip select up
        ->and(clockedOut($ctx, $link->sent[0]))->toBe("\x2C")
        ->and(d4Levels($ctx, $link->sent[0]))->toBe([false, false])
        ->and($high_byte($link->sent[1]))->toBe([0xFD])                                 // C1 low, every other C line still high
        ->and(clockedOut($ctx, $link->sent[3]))->toBe("\xFF\x00")
        ->and(d4Levels($ctx, $link->sent[4]))->toBe([false, false, true]);
});

it('raises chip select again after a lost exchange, and sends the clock again with the next call', function () {
    [$ctx, $link, $loop] = pumpedSpi();
    $flash = (new MpsseSPITransport(0, $ctx, new MpsseClock($ctx->clock)))->speed(2_000_000);
    $divisors = fn (MPSSERecording $recording): int => count(array_filter(mpsseCommands($ctx, $recording->commands), fn (array $command): bool => $command[0] === 0x86));
    $link->replies = [false];

    $lost = $loop->async(fn () => $flash->write([0x06]))->wait();
    $after = $loop->async(fn (): array => [$flash->write([0x04]), $flash->write([0x05])])->wait();

    expect($lost)->toBe(-1)
        ->and($after)->toBe([1, 1])
        ->and($link->sent)->toHaveCount(4)                                              // the lost write, chip select up again, two more writes
        ->and($divisors($link->sent[0]))->toBe(1)
        ->and(clockedOut($ctx, $link->sent[1]))->toBe('')
        ->and(d4Levels($ctx, $link->sent[1]))->toBe([true, true, true])                  // stop, idle, pin high
        ->and($divisors($link->sent[2]))->toBe(1)                                       // the clock goes out again
        ->and($divisors($link->sent[3]))->toBe(0);                                      // and only once
});

it('runs an offloaded job in a loop fiber against the real slave, and refuses a named work target', function () {
    $loop = new EventLoop;
    $ctx = recordableSpiContext();
    $link = new ScriptedMpsseLink;
    $link->replies = ["\xEF\x40\x17"];
    $driver = (new MpsseSPIConnectionDriver(new MpsseDigitalIOConnectionDriver))->resolvesLoopWith(fn (): Loop => $loop);
    $flash = null;

    MPSSE::record($ctx, function () use ($driver, $ctx) {                               // the connect-time pin setup stays off USB
        $driver->register('ft232h', $ctx);
    });
    MpssePump::for($ctx, $loop, $link);
    MPSSE::record($ctx, function () use ($driver, &$flash) {
        $flash = $driver->device('ft232h', 0);
    });

    expect($flash->via()->writeRead([0x9F], 3)->wait())->toBe([0xEF, 0x40, 0x17])
        ->and(fn () => $flash->via('pool')->writeRead([0x9F], 3))->toThrow(SPIException::class, 'MPSSE SPI transfers run on the device\'s own USB pump');
});
