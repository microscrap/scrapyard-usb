<?php

use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\Bindings\MPSSE\MPSSERecording;
use Microscrap\ScrapyardUSB\Mpsse\FtdiLink;
use Microscrap\ScrapyardUSB\Mpsse\MpssePump;
use Microscrap\ScrapyardUSB\Tests\Fixtures\ScriptedMpsseLink;

it('runs a nested exclusive() of the turn\'s holder at once, on the main stack and in a fiber', function () {
    $loop = testLoop();
    $pump = MpssePump::for(new MPSSEContext, $loop, new ScriptedMpsseLink);
    $gate = new ArrayObject(['open' => false]);
    $log = [];

    $main = $pump->exclusive(fn () => $pump->exclusive(fn (): string => 'inner'));

    $a = $loop->async(function () use ($pump, $loop, $gate, &$log) {
        $pump->exclusive(function () use ($pump, $loop, $gate, &$log) {
            $log[] = 'a1';
            $pump->exclusive(function () use (&$log) { $log[] = 'a2'; });
            $loop->until(fn (): bool => $gate['open']);
            $log[] = 'a3';
        });
    });
    $b = $loop->async(function () use ($pump, &$log) {
        $pump->exclusive(function () use (&$log) { $log[] = 'b'; });
    });
    $loop->at(0.01, fn () => $gate['open'] = true);

    $a->wait();
    $b->wait();

    expect($main)->toBe('inner')
        ->and($log)->toBe(['a1', 'a2', 'a3', 'b']);
});

it('lets a loop callback that needs the wire while the main stack waits for its turn ride that turn instead of deadlocking', function () {
    $loop = testLoop();
    $pump = MpssePump::for(new MPSSEContext, $loop, new ScriptedMpsseLink);
    $gate = new ArrayObject(['open' => false]);
    $log = [];

    $fiber = $loop->async(function () use ($pump, $loop, $gate, &$log) {
        $pump->exclusive(function () use ($loop, $gate, &$log) {
            $loop->until(fn (): bool => $gate['open']);
            $log[] = 'fiber';
        });
    });
    $loop->at(0.005, function () use ($pump, &$log) {
        $pump->exclusive(function () use (&$log) { $log[] = 'callback'; });
    });
    $loop->at(0.01, fn () => $gate['open'] = true);

    $pump->exclusive(function () use (&$log) { $log[] = 'main'; });
    $fiber->wait();

    expect($log)->toBe(['fiber', 'callback', 'main'])
        ->and($pump->idle())->toBeTrue();
});

it('carries a recording over its link and hands back the reply', function () {
    $loop = testLoop();
    $link = new ScriptedMpsseLink;
    $link->replies = ["\x12\x34"];
    $pump = MpssePump::for(new MPSSEContext, $loop, $link);
    $recording = new MPSSERecording;
    $recording->commands = "\x20\x01\x00\x87";
    $recording->expect(2, false);

    $reply = $loop->async(fn () => $pump->exclusive(fn () => $pump->exchange($recording)))->wait();

    expect($reply)->toBe("\x12\x34")
        ->and($link->sent)->toBe([$recording])
        ->and($pump->idle())->toBeTrue();
});

it('gives a transaction time for every byte the engine clocks, replies included', function () {
    $read = new MPSSERecording;
    $read->commands = str_repeat("\x20\xFF\xFB", 17)."\x87";          // 1 MiB read: 17 read commands, 52 bytes
    $read->expect(1 << 20, false);
    $write = new MPSSERecording;
    $write->commands = str_repeat("\x00", 100);

    expect(FtdiLink::deadline($read, 1_000_000))->toBeGreaterThan(1.0 + 8 * (1 << 20) / 1_000_000)   // the bits alone take 8.4 s
        ->and(FtdiLink::deadline($write, 400_000))->toBeLessThan(1.01);
});
