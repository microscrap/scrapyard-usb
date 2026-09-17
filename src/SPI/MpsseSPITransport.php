<?php

namespace Microscrap\ScrapyardUSB\SPI;

use GeneralPurposeIO\Digital\DigitalIO;
use Microscrap\Bindings\MPSSE\MPSSE;
use GeneralPurposeIO\SPI\SPITransport;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;

class MpsseSPITransport extends SPITransport
{
    public function __construct(
        int $chip_select,
        protected readonly MPSSEContext $context,
        public readonly string $device
    ) {
        parent::__construct($chip_select);
    }

    public function handle(): MPSSEContext
    {
        return $this->context;
    }

    public function close(): void
    {
        mpsse_close($this->context);
    }

    public function read(int $len): array|false
    {
        $this->toggleChip($this->chip_select);
        MPSSE::start($this->context);
        $rx = MPSSE::read($this->context, $len);
        MPSSE::stop($this->context);
        $this->untoggleChip($this->chip_select);
        return is_null($rx) ? false : bytes2array($rx);
    }

    public function write(array|string $data): int
    {
        $this->toggleChip($this->chip_select);

        if (is_array($data)) {
            $data = array2bytes($data);
        }

        MPSSE::start($this->context);
        $result = MPSSE::write($this->context, $data);
        MPSSE::stop($this->context);
        $this->untoggleChip($this->chip_select);
        return $result === 0 ? strlen($data) : -1;
    }

    public function transfer(array|string $data): array|false
    {
        $this->toggleChip($this->chip_select);

        if (is_array($data)) {
            $data = array2bytes($data);
        }

        MPSSE::start($this->context);
        $rx = MPSSE::transfer($this->context, $data);
        MPSSE::stop($this->context);

        $this->untoggleChip($this->chip_select);
        return is_null($rx) ? false : bytes2array($rx);
    }

    protected function toggleChip(int $chip_select): void
    {
        /** @var MpsseDigitalIOConnectionDriver $driver */
        $driver = DigitalIO::driver('usb');
        $pin = $driver->output($this->device, $chip_select);
        $pin->low();

    }

    protected function untoggleChip(int $chip_select): void
    {
        /** @var MpsseDigitalIOConnectionDriver $driver */
        $driver = DigitalIO::driver('usb');
        $pin = $driver->output($this->device, $chip_select);
        $pin->high();
    }
}