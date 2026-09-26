<?php

use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalInputTransport;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalOutputTransport;

it('refuses an int or an unknown device', function () {
    $driver = new MpsseDigitalIOConnectionDriver;

    expect(fn () => $driver->connectTo(0))->toThrow(DigitalIOException::class, 'must be a string')
        ->and(fn () => $driver->connectTo('toaster'))->toThrow(DigitalIOException::class, 'Invalid MPSSE device');
});

it('closes a pin without closing the device, and disconnect() closes both', function () {
    $driver = new MpsseDigitalIOConnectionDriver;
    $context = new MPSSEContext;
    $driver->register('ft232h', $context);

    $in = $driver->input('ft232h', 5);
    $out = $driver->output('ft232h', 6);

    $in->close();

    expect($in)->toBeInstanceOf(MpsseDigitalInputTransport::class)
        ->and($out)->toBeInstanceOf(MpsseDigitalOutputTransport::class)
        ->and($out->closed())->toBeFalse()
        ->and($driver->connections->has('ft232h'))->toBeTrue();

    $driver->disconnect('ft232h');

    expect($out->closed())->toBeTrue()
        ->and($driver->connections->has('ft232h'))->toBeFalse();
});

it('an output refuses to read once closed', function () {
    $driver = new MpsseDigitalIOConnectionDriver;
    $driver->register('ft232h', new MPSSEContext);

    $out = $driver->output('ft232h', 6);
    $out->close();

    expect(fn () => $out->read())->toThrow(DigitalIOException::class, 'closed')
        ->and(fn () => $out->write(true))->toThrow(DigitalIOException::class, 'closed');
});
