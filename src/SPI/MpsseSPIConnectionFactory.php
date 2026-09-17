<?php

namespace Microscrap\ScrapyardUSB\SPI;

use GeneralPurposeIO\Digital\DigitalIO;
use GeneralPurposeIO\Contracts\SPI\SPIEndianness;
use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\Contracts\SPI\SPIMode;
use GeneralPurposeIO\SPI\SPIConnectionDriver;
use GeneralPurposeIO\SPI\SPIConnectionFactory;
use Microscrap\Bindings\FTDI\Enums\FtdiVendorId;
use Microscrap\Bindings\MPSSE\Enums\MPSSEClockRate;
use Microscrap\Bindings\MPSSE\Enums\MPSSEEndianness;
use Microscrap\Bindings\MPSSE\Enums\MPSSEMode;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;

class MpsseSPIConnectionFactory extends SPIConnectionFactory
{
    public MPSSEClockRate $clock_rate = MPSSEClockRate::FOUR_HUNDRED_KHZ;

    public function __construct(
        string                   $device,
        MpsseSPIConnectionDriver $driver
    )
    {
        parent::__construct($device, $driver);
    }

    public function chipSelect(int $chip_select): static
    {
        $this->chip_select = $chip_select;
        return $this;
    }

    public function clockRate(MPSSEClockRate $rate): static
    {
        $this->clock_rate = $rate;

        return $this;
    }

    protected function device(): MpsseSupportedDevice
    {
        return MpsseSupportedDevice::from($this->device);
    }

    public function getHandle(): MPSSEContext
    {
        $error = '';
        $interface = $this->device()->interface();

        $context = mpsse_open(
            vid: FtdiVendorId::FTDI->value,
            pid: $this->device()->productId(),
            mode: match ($this->spi_mode) {
                SPIMode::MODE_1 => MPSSEMode::SPI1,
                SPIMode::MODE_2 => MPSSEMode::SPI2,
                SPIMode::MODE_3 => MPSSEMode::SPI3,
                default => MPSSEMode::SPI0,
            },
            freq: $this->clock_rate->value,
            endianness: $this->endianness === SPIEndianness::MSB
                ? MPSSEEndianness::MSB
                : MPSSEEndianness::LSB,
            iface: $interface,
            error: $error,
        );

        if (! empty($error) || is_null($context)) {
            throw new SPIException("MPSSE SPI context for [{$this->device()->value}] could not be opened. {$error}");
        }

        return $context;
    }

    public function register(): SPIConnectionDriver
    {
        $handle = $this->getHandle();
        /** @var MpsseDigitalIOConnectionDriver $driver */
        $driver = DigitalIO::driver('usb');
        $driver->register($this->device, $handle);
        return $this->driver->register($this->device, $handle);

    }
}