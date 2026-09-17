<?php

namespace Microscrap\ScrapyardUSB\Providers;

use GeneralPurposeIO\UART\UARTConnectionManager;
use Microscrap\ScrapyardUSB\UART\FtdiUARTConnectionDriver;
use Voyager\NutsAndBolts\ServiceProvider;
use GeneralPurposeIO\SPI\SPIConnectionManager;
use GeneralPurposeIO\Digital\DigitalOConnectionManager;
use GeneralPurposeIO\I2C\I2CConnectionManager;
use Microscrap\ScrapyardUSB\SPI\MpsseSPIConnectionDriver;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;
use Microscrap\ScrapyardUSB\I2C\MpsseI2CConnectionDriver;

class ScrapyardUSBServiceProvider extends ServiceProvider
{
    public function register(): void
    {

    }

    public function boot(): void
    {
        $this->bootI2C();
        $this->bootSPI();
        $this->bootUART();
        $this->bootDigitalIO();
    }

    private function bootDigitalIO(): void
    {
        /** @var DigitalOConnectionManager $manager */
        $manager = $this->app->make(DigitalOConnectionManager::class);
        $manager->extend('usb', fn() => new MpsseDigitalIOConnectionDriver);
    }

    private function bootUART(): void
    {
        /** @var UARTConnectionManager $manager */
        $manager = $this->app->make(UARTConnectionManager::class);
        $manager->extend('usb', fn() => new FtdiUARTConnectionDriver);
    }

    private function bootSPI(): void
    {
        /** @var SPIConnectionManager $manager */
        $manager = $this->app->make(SPIConnectionManager::class);
        $manager->extend('usb', fn() => new MpsseSPIConnectionDriver);
    }

    private function bootI2C(): void
    {
        /** @var I2CConnectionManager $manager */
        $manager = $this->app->make(I2CConnectionManager::class);
        $manager->extend('usb', fn() => new MpsseI2CConnectionDriver);
    }
}