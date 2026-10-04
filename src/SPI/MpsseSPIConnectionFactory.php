<?php

namespace Microscrap\ScrapyardUSB\SPI;

use GeneralPurposeIO\Contracts\SPI\SPIEndianness;
use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\Contracts\SPI\SPIMode;
use GeneralPurposeIO\SPI\SPIConnectionFactory;
use Ftdi\FtdiVendorId;
use Microscrap\Bindings\MPSSE\Enums\MPSSEClockRate;
use Microscrap\Bindings\MPSSE\Enums\MPSSEEndianness;
use Microscrap\Bindings\MPSSE\Enums\MPSSEMode;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\Bindings\MPSSE\MPSSEContext;

class MpsseSPIConnectionFactory extends SPIConnectionFactory
{
    public MPSSEClockRate $clock_rate = MPSSEClockRate::FOUR_HUNDRED_KHZ;

    /** Hz asked for through the protocol-wide speed(); wins over clock_rate when set. */
    protected ?int $asked_speed = null;

    public function __construct(
        string $device,
        MpsseSPIConnectionDriver $driver
    ) {
        parent::__construct($device, $driver);
    }

    /** A chip select belongs to each slave (device()); the connection keeps whatever is set here unused. */
    public function chipSelect(int $chip_select): static
    {
        $this->chip_select = $chip_select;

        return $this;
    }

    public function clockRate(MPSSEClockRate $rate): static
    {
        $this->clock_rate = $rate;
        $this->asked_speed = null;

        return $this;
    }

    /** The protocol-wide door: MPSSE takes any frequency in Hz, so a config's speed needs no enum. */
    public function speed(int $value): static
    {
        $this->asked_speed = $value;

        return parent::speed($value);
    }

    protected function device(): MpsseSupportedDevice
    {
        return MpsseSupportedDevice::from($this->device);
    }

    public function getHandle(): MPSSEContext
    {
        $error = '';

        $context = mpsse_open(
            vid: FtdiVendorId::FTDI->value,
            pid: $this->device()->productId(),
            mode: match ($this->spi_mode) {
                SPIMode::MODE_1 => MPSSEMode::SPI1,
                SPIMode::MODE_2 => MPSSEMode::SPI2,
                SPIMode::MODE_3 => MPSSEMode::SPI3,
                default => MPSSEMode::SPI0,
            },
            freq: $this->asked_speed ?? $this->clock_rate->value,
            endianness: $this->endianness === SPIEndianness::MSB ? MPSSEEndianness::MSB : MPSSEEndianness::LSB,
            iface: $this->device()->interface(),
            error: $error,
        );

        if (! empty($error) || is_null($context)) {
            throw SPIException::couldNotOpenMpsseContext($this->device()->value, $error);
        }

        return $context;
    }
}
