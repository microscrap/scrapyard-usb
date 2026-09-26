<?php

namespace Microscrap\ScrapyardUSB\Digital;

use GeneralPurposeIO\Digital\DigitalIOConnectionDriver;
use Microscrap\Bindings\MPSSE\Enums\MPSSEMode;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use GeneralPurposeIO\Contracts\Digital\LineBias;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\Mpsse\MpssePump;
use Microscrap\ScrapyardUSB\BridgeMode;
use Microscrap\ScrapyardUSB\FtdiBridge;

class MpsseDigitalIOConnectionDriver extends DigitalIOConnectionDriver
{
    protected function newConnection(int|string $device): MpsseDigitalIOConnectionFactory
    {
        if(is_int($device)) {
            throw new DigitalIOException('MPSSE device must be a string');
        }

        if(MpsseSupportedDevice::tryFrom($device))
        {
            FtdiBridge::ensureFree($device, BridgeMode::MPSSE);

            return new MpsseDigitalIOConnectionFactory($device, $this);
        }

        throw new DigitalIOException("Invalid MPSSE device {$device}");
    }

    /** Every MPSSE protocol registers its context here, so this is where the bridge is taken for MPSSE. */
    public function register(string $name, mixed $handle): static
    {
        FtdiBridge::claim($name, BridgeMode::MPSSE);

        return parent::register($name, $handle);
    }

    /** I2C and SPI disconnect through here too, so the bridge is given back once, whichever protocol opened it. */
    public function disconnect(string|int $device): void
    {
        parent::disconnect($device);

        FtdiBridge::release((string) $device, BridgeMode::MPSSE);
    }

    protected function getOutputTransport(string|int $device, int $pin): MpsseDigitalOutputTransport
    {
        $key = "{$device}:{$pin}";

        if(isset($this->pins[$key]))
        {
            if($this->pins[$key] instanceOf MpsseDigitalOutputTransport)
            {
                return $this->pins[$key];
            }

            throw new DigitalIOException("Pin {$pin} on device {$device} is not an output");
        }

        /** @var MPSSEContext $context */
        $context = $this->connections->get($device);

        $this->onTheBus($context, fn () => mpsse_configure_pin_direction($context, $pin, true));
        $this->pins[$key] = new MpsseDigitalOutputTransport($pin, $context);

        return $this->pins[$key];
    }

    protected function getInputTransport(string|int $device, int $pin, LineBias $bias = LineBias::AS_IS, bool $active_low = false): MpsseDigitalInputTransport
    {
        $key = "{$device}:{$pin}";

        if(isset($this->pins[$key]))
        {
            if($this->pins[$key] instanceOf MpsseDigitalInputTransport)
            {
                return $this->pins[$key];
            }

            throw new DigitalIOException("Pin {$pin} on device {$device} is not an input");
        }

        /** @var MPSSEContext $context */
        $context = $this->connections->get($device);

        $this->onTheBus($context, fn () => mpsse_configure_pin_direction($context, $pin, false));
        $this->pins[$key] = new MpsseDigitalInputTransport($pin, $context);

        return $this->pins[$key];
    }

    /**
     * Closes only a context this driver opened (GPIO mode). A context another protocol's
     * driver opened and shared here (I2C mode) is closed by that driver's disconnect().
     * @param MPSSEContext $handle
     */
    protected function closeConnection(mixed $handle): void
    {
        if ($handle->mode === MPSSEMode::GPIO->value) {
            mpsse_close($handle);
        }
    }

    /** A direction change is a USB write too: once the context has a pump, it waits its turn there. */
    private function onTheBus(MPSSEContext $context, \Closure $write): mixed
    {
        $pump = MpssePump::existing($context);

        return is_null($pump) ? $write() : $pump->exclusive($write);
    }
}
