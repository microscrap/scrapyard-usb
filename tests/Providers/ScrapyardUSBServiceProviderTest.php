<?php

use GeneralPurposeIO\Digital\DigitalIOServiceProvider;
use GeneralPurposeIO\Digital\DigitalOConnectionManager;
use GeneralPurposeIO\Digital\NoneDigitalIOConnectionDriver;
use GeneralPurposeIO\I2C\I2CConnectionManager;
use GeneralPurposeIO\I2C\I2CServiceProvider;
use GeneralPurposeIO\I2C\NoneI2CConnectionDriver;
use GeneralPurposeIO\SPI\SPIConnectionManager;
use GeneralPurposeIO\SPI\SPIServiceProvider;
use GeneralPurposeIO\UART\UARTConnectionManager;
use GeneralPurposeIO\UART\UARTServiceProvider;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;
use Microscrap\ScrapyardUSB\I2C\MpsseI2CConnectionDriver;
use Microscrap\ScrapyardUSB\Providers\ScrapyardUSBServiceProvider;
use Microscrap\ScrapyardUSB\SPI\MpsseSPIConnectionDriver;
use Microscrap\ScrapyardUSB\UART\FtdiUARTConnectionDriver;
use Voyager\Config\Repository;
use Voyager\Vessel\Vessel;

/*
| The framework's protocol providers bind the managers; this provider only
| extends them with `usb`. So the container here is the framework's own
| Vessel with those providers registered, nothing hand-bound.
*/
function frameworkVessel(array $gpio = []): Vessel
{
    $vessel = new Vessel;
    Vessel::setInstance($vessel);
    $vessel->instance('config', new Repository(['gpio' => $gpio]));

    foreach ([I2CServiceProvider::class, SPIServiceProvider::class, UARTServiceProvider::class, DigitalIOServiceProvider::class] as $provider) {
        (new $provider($vessel))->register();
    }

    return $vessel;
}

it('extends every protocol manager it serves with the usb driver', function (): void {
    $vessel = frameworkVessel();

    (new ScrapyardUSBServiceProvider($vessel))->boot();

    expect($vessel->make(I2CConnectionManager::class)->driver('usb'))->toBeInstanceOf(MpsseI2CConnectionDriver::class)
        ->and($vessel->make(SPIConnectionManager::class)->driver('usb'))->toBeInstanceOf(MpsseSPIConnectionDriver::class)
        ->and($vessel->make(UARTConnectionManager::class)->driver('usb'))->toBeInstanceOf(FtdiUARTConnectionDriver::class)
        ->and($vessel->make(DigitalOConnectionManager::class)->driver('usb'))->toBeInstanceOf(MpsseDigitalIOConnectionDriver::class);
});

it('leaves none as the default until the app opts into usb', function (): void {
    $vessel = frameworkVessel();
    (new ScrapyardUSBServiceProvider($vessel))->boot();

    expect($vessel->make('gpio.i2c')->driver())->toBeInstanceOf(NoneI2CConnectionDriver::class)
        ->and($vessel->make('gpio.digital')->driver())->toBeInstanceOf(NoneDigitalIOConnectionDriver::class);
});

it('becomes the default when configured', function (): void {
    $vessel = frameworkVessel(['protocols' => ['spi' => ['default' => 'usb'], 'digital-in' => ['default' => 'usb']]]);
    (new ScrapyardUSBServiceProvider($vessel))->boot();

    expect($vessel->make('gpio.spi')->driver())->toBeInstanceOf(MpsseSPIConnectionDriver::class)
        ->and($vessel->make('gpio.digital')->driver())->toBeInstanceOf(MpsseDigitalIOConnectionDriver::class);
});
