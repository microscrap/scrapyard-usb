<?php

use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use GeneralPurposeIO\Contracts\Digital\SignalEdge;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalInputTransport;
use Microscrap\ScrapyardUSB\Tests\Fixtures\ScriptedMpsseDigitalInputTransport;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\MailHandler;

function scriptedPin(array $levels, ?Loop $loop = null): ScriptedMpsseDigitalInputTransport
{
    $pin = (new ScriptedMpsseDigitalInputTransport(5, new MPSSEContext))->boundTo('ft232h');
    $pin->levels = $levels;

    return is_null($loop) ? $pin : $pin->resolvesLoopWith(fn (): Loop => $loop);
}

function mailRecorder(): MailHandler
{
    return new class implements MailHandler {
        /** @var list<object> */
        public array $events = [];

        public function handOff(array $mail, Loop $loop): void
        {
            foreach ($mail as $event) {
                $this->events[] = $event;
            }
        }
    };
}

it('throws when the device does not answer a pin read', function () {
    $pin = (new MpsseDigitalInputTransport(5, new MPSSEContext))->boundTo('ft232h');

    expect(fn () => $pin->read())->toThrow(DigitalIOException::class, 'did not answer');
});

it('a blocking listen(-1) waits for a level change', function () {
    $pin = scriptedPin([false, false, false, true]);

    $edge = $pin->listen(-1, true, true);

    expect($edge->edge)->toBe(SignalEdge::RISING)
        ->and($edge->device)->toBe('ft232h')
        ->and($edge->pin)->toBe(5)
        ->and($edge->seqno)->toBe(1);
});

it('a blocking listen() with a timeout returns null when the level holds', function () {
    $pin = scriptedPin([true])->pollEvery(2);

    $start = microtime(true);

    expect($pin->listen(20, true, true))->toBeNull()
        ->and(microtime(true) - $start)->toBeGreaterThanOrEqual(0.019);
});

it('read() between samples never hides an edge', function () {
    $pin = scriptedPin([false, true, true]);

    $pin->pollEdges(true, true);
    $pin->read();

    expect($pin->pollEdges(true, true))->toHaveCount(1);
});

it('a watched pin is sampled every pollEvery() ms and mails each change', function () {
    $mail = mailRecorder();
    $loop = testLoop($mail);
    $pin = scriptedPin([false, false, true, true, false], $loop)->pollEvery(2);

    $pin->watch();
    $loop->at(0.05, fn () => $pin->unwatch());
    $loop->run();

    expect(array_map(fn ($e) => $e->edge, $mail->events))->toBe([SignalEdge::RISING, SignalEdge::FALLING])
        ->and(array_map(fn ($e) => $e->name(), $mail->events))->toBe(['gpio.edge.ft232h.5', 'gpio.edge.ft232h.5']);
});

it('pollEvery() retimes a pin already on the loop', function () {
    $loop = testLoop();
    $pin = scriptedPin([false], $loop)->pollEvery(50);

    $pin->watch();
    $pin->pollEvery(2);
    $loop->at(0.03, fn () => $pin->unwatch());
    $loop->run();

    expect($pin->samples)->toBeGreaterThan(5);
});

it('listen() under a loop is paced by pollEvery(), not by its timeout', function () {
    $loop = testLoop();
    $pin = scriptedPin([false, false, true], $loop)->pollEvery(2);

    $start = microtime(true);
    $edge = $pin->listen(1000, true, true);

    expect($edge?->edge)->toBe(SignalEdge::RISING)
        ->and(microtime(true) - $start)->toBeLessThan(0.1);
});
