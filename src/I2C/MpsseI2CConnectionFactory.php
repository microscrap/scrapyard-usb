<?php

namespace Microscrap\ScrapyardUSB\I2C;

use GeneralPurposeIO\Contracts\I2C\I2CException;
use GeneralPurposeIO\Digital\DigitalIO;
use GeneralPurposeIO\I2C\I2CConnectionDriver;
use GeneralPurposeIO\I2C\I2CConnectionFactory;
use Microscrap\Bindings\FTDI\Enums\FtdiVendorId;
use Microscrap\Bindings\MPSSE\Enums\MPSSEClockRate;
use Microscrap\Bindings\MPSSE\Enums\MPSSEEndianness;
use Microscrap\Bindings\MPSSE\Enums\MPSSEMode;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;

class MpsseI2CConnectionFactory extends I2CConnectionFactory
{
    public MPSSEEndianness $endianness = MPSSEEndianness::MSB;

    public MPSSEClockRate $clock_rate = MPSSEClockRate::FOUR_HUNDRED_KHZ;

    public function __construct(
        string $device,
        MpsseI2CConnectionDriver $driver
    ) {
        parent::__construct($device, $driver);
    }

    protected function device(): MpsseSupportedDevice
    {
        return MpsseSupportedDevice::from($this->device);
    }

    public function endianness(MPSSEEndianness $endianness): static
    {
        $this->endianness = $endianness;

        return $this;
    }

    public function clockRate(MPSSEClockRate $rate): static
    {
        $this->clock_rate = $rate;

        return $this;
    }

    protected function getHandle(): MPSSEContext
    {
        $error = '';
        $interface = $this->device()->interface();

        $context = mpsse_open(
            vid: FtdiVendorId::FTDI->value,
            pid: $this->device()->productId(),
            mode: MPSSEMode::I2C,
            freq: $this->clock_rate->value,
            endianness: $this->endianness,
            iface: $interface,
            error: $error,
        );

        if (! empty($error) || is_null($context)) {
            throw new I2CException("MPSSE I2C context for [{$this->device()->value}] could not be opened. {$error}");
        }

        return $context;
    }

    public function register(): I2CConnectionDriver
    {
        $handle = $this->getHandle();
        /** @var MpsseDigitalIOConnectionDriver $driver */
        $driver = DigitalIO::driver('usb');
        $driver->register($this->device, $handle);
        return $this->driver->register($this->device, $handle);
    }
}