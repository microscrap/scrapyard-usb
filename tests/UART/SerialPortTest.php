<?php

use GeneralPurposeIO\Contracts\UART\UARTException;
use Microscrap\ScrapyardUSB\BridgeMode;
use Microscrap\ScrapyardUSB\FtdiBridge;

beforeEach(fn () => forgetBridges());
afterEach(fn () => forgetBridges());

it('reads what the chip sent and keeps what was not asked for', function () {
    [$port, $link] = scriptedUart();
    $link->replies = ["+OK\r\n+RE", "ADY\r\n"];

    expect($port->readUntil("\r\n", 0))->toBe("+OK\r\n")
        ->and($port->readUntil("\r\n", 0))->toBe("+READY\r\n");
});

it('waits for a reply the chip sends a few reads later', function () {
    [$port, $link] = scriptedUart();
    $link->replies = ['', '', "+OK\r\n"];

    expect($port->readUntil("\r\n", 500))->toBe("+OK\r\n");
});

it('tells a lost device from a silent one', function () {
    [$port, $link] = scriptedUart();
    $link->status_word = -4;

    expect(fn () => $port->read(4, 0))->toThrow(UARTException::class, 'Could not read from UART port ft232h.');
});

it('a silent chip times a read out without spinning', function () {
    [$port, $link] = scriptedUart();

    $started = hrtime(true);
    $bytes = $port->read(4, 30);

    expect($bytes)->toBe([])
        ->and((hrtime(true) - $started) / 1e6)->toBeGreaterThanOrEqual(25.0)
        ->and($link->reads)->toBeLessThan(80);
});

it('sends 256-byte chunks, each once the one before has finished', function () {
    [$port, $link] = scriptedUart();
    $link->busy_polls = 2;
    $payload = random_bytes(600);

    expect($port->write($payload))->toBe(600)
        ->and(array_map('strlen', $link->sends))->toBe([256, 256, 88])
        ->and(implode('', $link->sends))->toBe($payload);
});

it('says so when libusb refuses a send', function () {
    [$port, $link] = scriptedUart();
    $link->refuse = true;

    expect(fn () => $port->write('AT'))->toThrow(UARTException::class, 'Could not write to UART port ft232h: 0 of 2 bytes went out.');
});

it('says so when USB wrote less than it was given', function () {
    [$port, $link] = scriptedUart();
    $link->writes = 100;
    $port->write(str_repeat('z', 256));      // this chunk's count is checked when the next write needs room

    expect(fn () => $port->write('z'))->toThrow(UARTException::class, 'USB wrote 100 of 256 bytes to UART port ft232h.');
});

it('times a write out while the send before it never finishes', function () {
    [$port, $link] = scriptedUart();
    $port->write('first');
    $link->stuck = true;

    expect(fn () => $port->write('second', 30))
        ->toThrow(UARTException::class, 'UART port ft232h took no more bytes before the timeout: 0 of 6 went out.');
});

it('asserts and releases DTR and RTS on the chip, and says so when the chip refuses', function () {
    [$port, $link] = scriptedUart();

    $port->dtr(true);
    $port->rts(false);

    expect($link->lines)->toBe(['dtr' => true, 'rts' => false]);

    $link->line_result = -1;

    expect(fn () => $port->dtr(false))->toThrow(UARTException::class, 'Could not set DTR on UART port ft232h.');
});

it('flush() purges the chip and what is unread', function () {
    [$port, $link] = scriptedUart();
    $link->replies = ['stale'];
    $port->pollBytes(0);

    $port->flush();

    expect($link->purges)->toBe(1)
        ->and($port->read(8, 0))->toBe([]);
});

it('close() lets the running send finish, closes the chip and frees the bridge', function () {
    [$port, $link] = scriptedUart();
    FtdiBridge::claim('ft232h', BridgeMode::UART);
    $link->busy_polls = 3;
    $port->write('AT');

    $port->close();

    expect($link->finished_before_close)->toBeTrue()
        ->and($link->closed)->toBeTrue()
        ->and(FtdiBridge::holder('ft232h'))->toBeNull();
});

it('asks the chip for one sampling interval of line data at a time, so a streaming device cannot hold the loop', function () {
    [$port, $link] = scriptedUart();
    $link->replies = [str_repeat('x', 115)];

    $port->pollBytes();

    expect($link->asked[0])->toBe(115);             // 115200 baud, 10 bits a byte, 10 ms
});

it('close() says so when the last send failed, and still closes the chip and frees the bridge', function () {
    [$port, $link] = scriptedUart();
    FtdiBridge::claim('ft232h', BridgeMode::UART);
    $link->writes = 100;
    $port->write(str_repeat('z', 256));

    expect(fn () => $port->close())->toThrow(UARTException::class, 'USB wrote 100 of 256 bytes to UART port ft232h.')
        ->and($link->closed)->toBeTrue()
        ->and(FtdiBridge::holder('ft232h'))->toBeNull();
});
