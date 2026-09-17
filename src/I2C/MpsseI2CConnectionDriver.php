<?php

namespace Microscrap\ScrapyardUSB\I2C;

use GeneralPurposeIO\Contracts\I2C\I2CException;
use GeneralPurposeIO\I2C\I2CConnectionDriver;
use GeneralPurposeIO\I2C\I2CTransport;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\Bindings\MPSSE\MPSSEContext;

class MpsseI2CConnectionDriver extends I2CConnectionDriver
{
    protected function newConnection(int|string $device): MpsseI2CConnectionFactory
    {
        if(is_int($device)) {
            throw new I2CException('MPSSE device must be a string');
        }

        if(MpsseSupportedDevice::tryFrom($device))
        {
            return new MpsseI2CConnectionFactory($device, $this);
        }

        throw new I2CException("Invalid MPSSE device {$device}");
    }

    protected function getTransport(int|string $device, int $slave_address): I2CTransport
    {
        /** @var MPSSEContext $context */
        $context = $this->connections->get($device);

        return new MpsseI2CTransport($slave_address, $context);
    }
}