<?php

use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use Microscrap\Bindings\MPSSE\Enums\MPSSEClockRate;
use Microscrap\Bindings\MPSSE\Enums\MPSSEEndianness;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionFactory;

it('only connects devices the MPSSE bindings know by name', function (): void {
    $driver = new MpsseDigitalIOConnectionDriver;

    expect(fn () => $driver->connectTo(0))->toThrow(DigitalIOException::class, 'must be a string')
        ->and(fn () => $driver->connectTo('ft9999'))->toThrow(DigitalIOException::class, 'Invalid MPSSE device ft9999')
        ->and($driver->connectTo(MpsseSupportedDevice::cases()[0]->value))->toBeInstanceOf(MpsseDigitalIOConnectionFactory::class);
});

it('returns null for pins on a device that was never connected', function (): void {
    $driver = new MpsseDigitalIOConnectionDriver;

    expect($driver->output('ft232h', 4))->toBeNull()
        ->and($driver->input('ft232h', 4))->toBeNull();
});

it('starts at 1 MHz MSB first and carries clock and endianness as fluent state', function (): void {
    $device = MpsseSupportedDevice::cases()[0]->value;
    $factory = new MpsseDigitalIOConnectionFactory($device, new MpsseDigitalIOConnectionDriver);

    expect($factory->clock_rate)->toBe(MPSSEClockRate::ONE_MHZ)
        ->and($factory->endianness)->toBe(MPSSEEndianness::MSB)
        ->and($factory->clockRate(MPSSEClockRate::FOUR_HUNDRED_KHZ))->toBe($factory)
        ->and($factory->clock_rate)->toBe(MPSSEClockRate::FOUR_HUNDRED_KHZ)
        ->and($factory->endianness(MPSSEEndianness::LSB)->endianness)->toBe(MPSSEEndianness::LSB);
});
