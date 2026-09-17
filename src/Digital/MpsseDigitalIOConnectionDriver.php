<?php

namespace Microscrap\ScrapyardUSB\Digital;

use GeneralPurposeIO\Digital\DigitalIOConnectionDriver;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use GeneralPurposeIO\Contracts\Digital\LineBias;
use Microscrap\Bindings\MPSSE\MPSSEContext;

class MpsseDigitalIOConnectionDriver extends DigitalIOConnectionDriver
{

    protected function newConnection(int|string $device): MpsseDigitalIOConnectionFactory
    {
        if(is_int($device)) {
            throw new DigitalIOException('MPSSE device must be a string');
        }

        if(MpsseSupportedDevice::tryFrom($device))
        {
            return new MpsseDigitalIOConnectionFactory($device, $this);
        }

        throw new DigitalIOException("Invalid MPSSE device {$device}");
    }

    protected function getOutputTransport(string|int $device, int $pin): MpsseDigitalOutputTransport
    {
        if(isset($this->pins[$pin]))
        {
            if($this->pins[$pin] instanceOf MpsseDigitalOutputTransport)
            {
                return $this->pins[$pin];
            }

            throw new DigitalIOException("Pin {$pin} is not an output");
        }

        /** @var MPSSEContext $context */
        $context = $this->connections->get($device);

        mpsse_configure_pin_direction($context, $pin, true);
        $this->pins[$pin] = new MpsseDigitalOutputTransport($pin, $context);

        return $this->pins[$pin];
    }

    protected function getInputTransport(string|int $device, int $pin, LineBias $bias = LineBias::AS_IS, bool $active_low = false): MpsseDigitalInputTransport
    {
        if(isset($this->pins[$pin]))
        {
            if($this->pins[$pin] instanceOf MpsseDigitalInputTransport)
            {
                return $this->pins[$pin];
            }

            throw new DigitalIOException("Pin {$pin} is not an input");
        }

        /** @var MPSSEContext $context */
        $context = $this->connections->get($device);

        mpsse_configure_pin_direction($context, $pin, false);
        $this->pins[$pin] = new MpsseDigitalInputTransport($pin, $context);

        return $this->pins[$pin];
    }
}