<?php

use GeneralPurposeIO\Contracts\UART\UARTReceived;
use Voyager\Contracts\IOPools\MailCollection;
use Voyager\Contracts\IOPools\Receivable;
use Voyager\IOPools\EventLoop;

beforeEach(fn () => forgetBridges());
afterEach(fn () => forgetBridges());

it('samples a watched port on its timer and mails what the chip sent', function () {
    $mail = new class implements Receivable {
        public array $events = [];

        public function handOff(MailCollection $mail): void
        {
            foreach ($mail->mail() as $event) {
                $this->events[] = $event;
            }
        }
    };
    $loop = new EventLoop(null, 16, $mail);
    [$port, $link] = scriptedUart($loop);

    $port->watch();
    $loop->at(0.02, function () use ($link) { $link->replies[] = "+READY\r\n"; });
    $loop->at(0.06, fn () => $port->unwatch());
    $loop->run();

    expect(array_map(fn (UARTReceived $received) => $received->bytes, $mail->events))->toBe(["+READY\r\n"])
        ->and($mail->events[0]->name())->toBe('gpio.uart.ft232h');
});

it('a read in a fiber suspends while the chip is silent', function () {
    $loop = new EventLoop;
    [$port, $link] = scriptedUart($loop);
    $order = [];

    $reader = $loop->async(function () use ($port, &$order) {
        $order[] = 'waiting';
        $order[] = 'got '.$port->readUntil("\r\n", 1_000);
    });
    $loop->async(function () use (&$order) { $order[] = 'other fiber'; });
    $loop->at(0.03, function () use ($link) { $link->replies[] = "+OK\r\n"; });
    $loop->until(fn (): bool => $reader->settled());

    expect($order)->toBe(['waiting', 'other fiber', "got +OK\r\n"]);
});

it('write() on the loop waits for the running send while other timers fire', function () {
    $loop = new EventLoop;
    [$port, $link] = scriptedUart($loop);
    $port->write('first');
    $link->stuck = true;
    $ticks = 0;
    $ticker = $loop->every(0.005, function () use (&$ticks) { $ticks++; }, 'ticker');
    $loop->at(0.03, function () use ($link) { $link->stuck = false; });

    $wrote = $port->write('second', 1_000);
    $ticker->cancel();

    expect($wrote)->toBe(6)
        ->and($ticks)->toBeGreaterThanOrEqual(3)
        ->and($link->sends)->toBe(['first', 'second']);
});

it('stops sampling once unwatched', function () {
    $loop = new EventLoop;
    [$port] = scriptedUart($loop);

    $port->watch();
    $loop->at(0.02, fn () => $port->unwatch());
    $loop->run();

    $notebook = (new ReflectionProperty(EventLoop::class, 'notebook'))->getValue($loop);

    expect((new ReflectionMethod(EventLoop::class, 'nextDue'))->invoke($loop))->toBeNull()
        ->and($notebook->hasResources())->toBeFalse();
});
