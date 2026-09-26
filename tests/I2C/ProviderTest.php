<?php

use GeneralPurposeIO\Digital\DigitalOConnectionManager;
use GeneralPurposeIO\I2C\I2CConnectionManager;
use GeneralPurposeIO\SPI\SPIConnectionManager;
use GeneralPurposeIO\UART\UARTConnectionManager;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;
use Microscrap\ScrapyardUSB\I2C\MpsseI2CConnectionDriver;
use Microscrap\ScrapyardUSB\Providers\ScrapyardUSBServiceProvider;
use Microscrap\ScrapyardUSB\SPI\MpsseSPIConnectionDriver;
use Microscrap\ScrapyardUSB\UART\FtdiUARTConnectionDriver;
use Voyager\Config\Repository;
use Voyager\Contracts\Core\FrameworkCore;
use Voyager\Vessel\ControlPanel;

it('boots usb I2C and SPI drivers that share the usb DigitalIO driver, and the usb UART driver', function () {
    $container = new ControlPanel;
    $container->registerInstance('config', new Repository(['gpio' => ['protocols' => [
        'i2c' => ['default' => 'usb'],
        'spi' => ['default' => 'usb'],
        'digital-in' => ['default' => 'usb'],
        'uart' => ['default' => 'usb'],
    ]]]));
    $digital = new DigitalOConnectionManager($container);
    $i2c = new I2CConnectionManager($container);
    $spi = new SPIConnectionManager($container);
    $uart = new UARTConnectionManager($container);

    $app = $this->createMock(FrameworkCore::class);
    $app->method('make')->willReturnCallback(fn (string $abstract): object => match ($abstract) {
        DigitalOConnectionManager::class => $digital,
        I2CConnectionManager::class => $i2c,
        SPIConnectionManager::class => $spi,
        UARTConnectionManager::class => $uart,
    });

    (new ScrapyardUSBServiceProvider($app))->boot();

    $i2c_driver = $i2c->driver();
    $spi_driver = $spi->driver();

    expect($i2c_driver)->toBeInstanceOf(MpsseI2CConnectionDriver::class)
        ->and($spi_driver)->toBeInstanceOf(MpsseSPIConnectionDriver::class)
        ->and($digital->driver())->toBeInstanceOf(MpsseDigitalIOConnectionDriver::class)
        ->and((new ReflectionProperty($i2c_driver, 'digital'))->getValue($i2c_driver))->toBe($digital->driver())
        ->and((new ReflectionProperty($spi_driver, 'digital'))->getValue($spi_driver))->toBe($digital->driver())
        ->and($uart->driver())->toBeInstanceOf(FtdiUARTConnectionDriver::class);
});
