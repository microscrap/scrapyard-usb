<?php

use GeneralPurposeIO\Contracts\I2C\I2CException;
use Microscrap\Bindings\MPSSE\Enums\MPSSEClockRate;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\ScrapyardUSB\I2C\MpsseI2CConnectionDriver;
use Microscrap\ScrapyardUSB\I2C\MpsseI2CConnectionFactory;

it('only connects devices the MPSSE bindings know by name', function (): void {
    $driver = new MpsseI2CConnectionDriver;

    expect(fn () => $driver->connectTo(1))->toThrow(I2CException::class, 'must be a string')
        ->and(fn () => $driver->connectTo('ft9999'))->toThrow(I2CException::class, 'Invalid MPSSE device ft9999')
        ->and($driver->connectTo(MpsseSupportedDevice::cases()[0]->value))->toBeInstanceOf(MpsseI2CConnectionFactory::class);
});

it('returns null for a slave on a device that was never connected', function (): void {
    expect((new MpsseI2CConnectionDriver)->device('ft232h', 0x3C))->toBeNull();
});

it('starts at 400 kHz for I2C', function (): void {
    $factory = new MpsseI2CConnectionFactory(MpsseSupportedDevice::cases()[0]->value, new MpsseI2CConnectionDriver);

    expect($factory->clock_rate)->toBe(MPSSEClockRate::FOUR_HUNDRED_KHZ);
});
