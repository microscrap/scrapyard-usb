<?php

use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\Contracts\SPI\SPIMode;
use Microscrap\Bindings\MPSSE\Enums\MPSSEClockRate;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\ScrapyardUSB\SPI\MpsseSPIConnectionDriver;
use Microscrap\ScrapyardUSB\SPI\MpsseSPIConnectionFactory;

it('only connects devices the MPSSE bindings know by name', function (): void {
    $driver = new MpsseSPIConnectionDriver;

    expect(fn () => $driver->connectTo(0))->toThrow(SPIException::class, 'must be a string')
        ->and(fn () => $driver->connectTo('ft9999'))->toThrow(SPIException::class, 'Invalid MPSSE device ft9999')
        ->and($driver->connectTo(MpsseSupportedDevice::cases()[0]->value))->toBeInstanceOf(MpsseSPIConnectionFactory::class);
});

it('returns null for a device on a master that was never connected', function (): void {
    expect((new MpsseSPIConnectionDriver)->device('ft232h', 0))->toBeNull();
});

it('carries chip select, mode and clock as fluent state', function (): void {
    $factory = new MpsseSPIConnectionFactory(MpsseSupportedDevice::cases()[0]->value, new MpsseSPIConnectionDriver);

    expect($factory->clock_rate)->toBe(MPSSEClockRate::FOUR_HUNDRED_KHZ)
        ->and($factory->chipSelect(3))->toBe($factory)
        ->and($factory->chip_select)->toBe(3)
        ->and($factory->mode(2)->spi_mode)->toBe(SPIMode::MODE_2)
        ->and($factory->clockRate(MPSSEClockRate::ONE_MHZ)->clock_rate)->toBe(MPSSEClockRate::ONE_MHZ);
});
