<?php

namespace Microscrap\ScrapyardUSB\SPI;

use Microscrap\Bindings\MPSSE\MPSSEContext;
use GeneralPurposeIO\SPI\SPIConnectionDriver;
use GeneralPurposeIO\SPI\SPIConnectionFactory;
use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\Contracts\SPI\SPITransport;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;

class MpsseSPIConnectionDriver extends SPIConnectionDriver
{
    protected function newConnection(int|string $device): SPIConnectionFactory
    {
        if(is_int($device)) {
            throw new SPIException('MPSSE device must be a string');
        }

        if(MpsseSupportedDevice::tryFrom($device))
        {
            return new MpsseSPIConnectionFactory($device, $this);
        }

        throw new SPIException("Invalid MPSSE device {$device}");
    }

    protected function getTransport(int|string $device, int $chip_select): SPITransport
    {
        /** @var MPSSEContext $context */
        $context = $this->connections->get($device);

        return new MpsseSPITransport($chip_select, $context, $device);
    }
}