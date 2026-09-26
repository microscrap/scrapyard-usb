<?php

namespace Microscrap\ScrapyardUSB\I2C;

use Fiber;
use GeneralPurposeIO\Contracts\I2C\I2CException;
use GeneralPurposeIO\Contracts\NutsAndBolts\BusJob;
use GeneralPurposeIO\I2C\I2CConnectionDriver;
use GeneralPurposeIO\I2C\I2CTransport;
use GeneralPurposeIO\NutsAndBolts\BusQueue;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\BridgeMode;
use Microscrap\ScrapyardUSB\FtdiBridge;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;
use Microscrap\ScrapyardUSB\Mpsse\MpssePump;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;

/**
 * The FT232H's GPIOH pins stay usable as DigitalIO while its MPSSE engine runs I2C:
 * this driver opens the context and shares it with the usb DigitalIO driver.
 * Offloaded jobs cannot leave the process (libusb claims the device), so they run in a loop fiber against the
 * real slave, and every USB exchange inside them rides the context's pump. One engine, one queue per bridge.
 */
class MpsseI2CConnectionDriver extends I2CConnectionDriver
{
    public function __construct(
        private readonly MpsseDigitalIOConnectionDriver $digital,
    ) {
        parent::__construct();
    }

    protected function newConnection(int|string $device): MpsseI2CConnectionFactory
    {
        if(is_int($device)) {
            throw new I2CException('MPSSE device must be a string');
        }

        if(MpsseSupportedDevice::tryFrom($device))
        {
            FtdiBridge::ensureFree($device, BridgeMode::MPSSE);

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

    public function register(string $name, mixed $handle): static
    {
        $this->digital->register($name, $handle);

        return parent::register($name, $handle);
    }

    /** Digital pins on the shared context close first; this driver opened the context and closes it last. */
    public function disconnect(string|int $device): void
    {
        $this->digital->disconnect($device);

        parent::disconnect($device);
    }

    public function offload(string|int $device, int $address, BusJob $job, ?string $target = null): Promise
    {
        if (! is_null($target)) {
            throw I2CException::offloadTargetUnsupported($target);
        }

        return parent::offload($device, $address, $job);
    }

    protected function queueKey(string|int $device, int $address): string
    {
        return (string) $device;
    }

    protected function dispatch(string|int $device, int $address, BusJob $job, ?string $target, Loop $loop, BusQueue $queue): Promise
    {
        /** @var MPSSEContext $context */
        $context = $this->connections->get($device);
        MpssePump::for($context, $loop);

        return $loop->async(function () use ($device, $address, $job, $queue): mixed {
            $queue->claim(Fiber::getCurrent());

            return $job->run($this->device($device, $address));
        });
    }

    /** @param MPSSEContext $handle */
    protected function closeConnection(mixed $handle): void
    {
        MpssePump::forget($handle);
        mpsse_close($handle);
    }
}
