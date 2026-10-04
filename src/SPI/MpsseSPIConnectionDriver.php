<?php

namespace Microscrap\ScrapyardUSB\SPI;

use Fiber;
use GeneralPurposeIO\Contracts\NutsAndBolts\BusJob;
use GeneralPurposeIO\Contracts\SPI\SPIException;
use GeneralPurposeIO\NutsAndBolts\BusQueue;
use GeneralPurposeIO\SPI\SPIConnectionDriver;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\Bindings\MPSSE\MPSSE;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\BridgeMode;
use Microscrap\ScrapyardUSB\FtdiBridge;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;
use Microscrap\ScrapyardUSB\Mpsse\MpssePump;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\Promise;

/**
 * SPI over an FT232H's MPSSE engine. The driver opens the context and shares it with the usb DigitalIO driver, so the
 * spare lines stay usable as pins. A chip select is a DigitalIO pin, as in 0.8: 0-3 are D4-D7, 4-11 are C0-C7. D3, the
 * engine's own chip select, stays parked high: 0.8 took it low on every call, selecting whatever sat on it as well.
 * Offloaded jobs cannot leave the process (libusb claims the device), so they run in a loop fiber against the real
 * slave, and every USB exchange inside them rides the context's pump.
 */
class MpsseSPIConnectionDriver extends SPIConnectionDriver
{
    /** @var array<string, MpsseClock> device => the engine clock its slaves share */
    private array $clocks = [];

    public function __construct(
        private readonly MpsseDigitalIOConnectionDriver $digital,
    ) {
        parent::__construct();
    }

    protected function newConnection(int|string $device): MpsseSPIConnectionFactory
    {
        if (is_int($device)) {
            throw new SPIException('MPSSE device must be a string');
        }

        if (is_null(MpsseSupportedDevice::tryFrom($device))) {
            throw new SPIException("Invalid MPSSE device {$device}");
        }

        if ($this->digital->connections->has($device)) {
            throw SPIException::bridgeInUse($device);
        }

        FtdiBridge::ensureFree($device, BridgeMode::MPSSE);

        return new MpsseSPIConnectionFactory($device, $this);
    }

    /**
     * Every chip select starts deasserted: the engine opens with D4-D7 and C0-C7 driven low, which would leave a chip on
     * any of them selected while another slave talks.
     * @param MPSSEContext $handle
     */
    public function register(string|int $name, mixed $handle): static
    {
        MPSSE::disableHardwareChipSelect($handle);

        foreach (range(0, 11) as $pin) {
            MPSSE::pinHigh($handle, $pin);
        }

        $this->digital->register($name, $handle);
        $this->clocks[$name] = new MpsseClock($handle->clock);

        return parent::register($name, $handle);
    }

    /** Digital pins on the shared context close first; this driver opened the context and closes it last. */
    public function disconnect(string|int $device): void
    {
        $this->digital->disconnect($device);

        parent::disconnect($device);

        unset($this->clocks[$device]);
    }

    public function offload(string|int $device, int $chip_select, BusJob $job, ?string $pool = null): Promise
    {
        if (! is_null($pool)) {
            throw SPIException::offloadPoolUnsupported($pool);
        }

        return parent::offload($device, $chip_select, $job);
    }

    protected function getTransport(int|string $device, int $chip_select): MpsseSPITransport
    {
        if ($chip_select < 0 || $chip_select > 11) {
            throw SPIException::invalidChipSelectPin($device, $chip_select);
        }

        $this->digital->output($device, $chip_select);          // an output from now on: DigitalIO can no longer take it as an input

        return new MpsseSPITransport($chip_select, $this->connections->get($device), $this->clocks[$device]);
    }

    /** The job runs here, in a loop fiber, against the real slave; the pump carries its USB traffic. */
    protected function dispatch(string|int $device, int $chip_select, BusJob $job, ?string $pool, Loop $loop, BusQueue $queue): Promise
    {
        MpssePump::for($this->connections->get($device), $loop);

        return $loop->async(function () use ($device, $chip_select, $job, $queue): mixed {
            $queue->claim(Fiber::getCurrent());

            return $job->run($this->device($device, $chip_select));
        });
    }

    /** @param MPSSEContext $handle */
    protected function closeConnection(mixed $handle): void
    {
        MpssePump::forget($handle);
        mpsse_close($handle);
    }
}
