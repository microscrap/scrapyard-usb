<?php

namespace Microscrap\ScrapyardUSB\Digital;

use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use GeneralPurposeIO\Digital\DigitalIOConnectionFactory;
use Microscrap\Bindings\FTDI\Enums\FtdiVendorId;
use Microscrap\Bindings\MPSSE\Enums\MPSSEClockRate;
use Microscrap\Bindings\MPSSE\Enums\MPSSEEndianness;
use Microscrap\Bindings\MPSSE\Enums\MPSSEMode;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\Bindings\MPSSE\MPSSEContext;

class MpsseDigitalIOConnectionFactory extends DigitalIOConnectionFactory
{
    public MPSSEEndianness $endianness = MPSSEEndianness::MSB;

    public MPSSEClockRate $clock_rate = MPSSEClockRate::ONE_MHZ;

    public function __construct(
        string $device,
        MpsseDigitalIOConnectionDriver $driver
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
            mode: MPSSEMode::GPIO,
            freq: $this->clock_rate->value,
            endianness: $this->endianness,
            iface: $interface,
            error: $error,
        );

        if (! empty($error) || is_null($context)) {
            throw new DigitalIOException("MPSSE DigitalIO context for [{$this->device()->value}] could not be opened. {$error}");
        }

        return $context;
    }
}