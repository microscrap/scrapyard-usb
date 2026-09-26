<?php

namespace Microscrap\ScrapyardUSB\UART;

use GeneralPurposeIO\Contracts\UART\UARTException;
use GeneralPurposeIO\UART\UARTTransport;
use Microscrap\ScrapyardUSB\BridgeMode;
use Microscrap\ScrapyardUSB\FtdiBridge;

class FtdiUARTTransport extends UARTTransport
{
    /** Bytes a wait read before anyone asked, handed over by the next drainBytes(). */
    private string $early = '';

    /** The size of the send in flight, checked once it finishes. */
    private int $sending = 0;

    public function __construct(
        string $device,
        int $baud,
        public readonly SerialLink $link,
        public readonly string $bridge,
    ) {
        parent::__construct($device, $baud);
    }

    public function handle(): SerialLink
    {
        return $this->link;
    }

    public function path(): string
    {
        return "usb:{$this->device}";
    }

    public function dtr(bool $asserted): void
    {
        $this->ensureOpen();

        if ($this->link->setDtr($asserted) < 0) {
            throw UARTException::modemLineFailed($this->device, 'DTR');
        }
    }

    public function rts(bool $asserted): void
    {
        $this->ensureOpen();

        if ($this->link->setRts($asserted) < 0) {
            throw UARTException::modemLineFailed($this->device, 'RTS');
        }
    }

    /** libftdi hands back '' for both silence and a lost device; the modem status tells them apart. */
    protected function drainBytes(): string
    {
        [$bytes, $this->early] = [$this->early.$this->link->read($this->readSize()), ''];

        if ($bytes === '' && $this->link->status() < 0) {
            throw UARTException::readFailed($this->device);
        }

        return $bytes;
    }

    /** Each empty read already waits one latency period in the chip, so this never spins; a lost device ends it early. */
    protected function awaitBytes(int $timeout_ms): void
    {
        $deadline = $timeout_ms < 0 ? null : hrtime(true) + $timeout_ms * 1_000_000;

        while ($this->early === '' && (is_null($deadline) || hrtime(true) < $deadline)) {
            $this->early .= $this->link->read($this->readSize());

            if ($this->early === '' && $this->link->status() < 0) {
                return;                                     // drainBytes() says so
            }
        }
    }

    /** Room = the send before has finished, and wrote everything it was given. */
    protected function roomNow(): bool
    {
        if (! $this->link->sent()) {
            return false;
        }

        $this->confirm();

        return true;
    }

    protected function awaitRoom(int $timeout_ms): void
    {
        $this->link->awaitSent($timeout_ms);
    }

    protected function transmit(string $bytes): int
    {
        if (! $this->link->send($bytes)) {
            return -1;
        }

        $this->sending = strlen($bytes);

        return strlen($bytes);
    }

    protected function purge(): void
    {
        $this->link->purge();
        $this->early = '';
    }

    /** libusb completions arrive as write-readiness (Linux) or on libusb's own pipe (macOS): select can't be woken by them. */
    protected function intakeStreams(): array
    {
        return [];
    }

    /** A sample costs one latency period (1 ms) when the chip has nothing; every 10 ms keeps the loop's share at a tenth. */
    protected function samplingInterval(): ?float
    {
        return 0.01;
    }

    /** A running send gets up to a second to finish before the device closes under it; a failed one is reported. */
    protected function release(): void
    {
        try {
            $this->link->awaitSent(1_000);

            if ($this->link->sent()) {
                $this->confirm();
            }
        } finally {
            $this->link->close();
            FtdiBridge::release($this->bridge, BridgeMode::UART);
        }
    }

    /**
     * libftdi keeps reading until it has what was asked for or the line goes quiet for one latency period, so a
     * streaming device answers a large read only when it is full. Asking for one sampling interval of line data
     * (10 bits a byte) bounds how long a read holds the caller or the loop.
     */
    private function readSize(): int
    {
        return max(64, intdiv($this->baud, 1000));          // baud / 10 bits a byte × 10 ms
    }

    private function confirm(): void
    {
        [$expected, $this->sending] = [$this->sending, 0];

        if ($expected > 0 && $this->link->sentBytes() !== $expected) {
            throw UARTException::usbWriteFailed($this->device, $this->link->sentBytes(), $expected);
        }
    }
}
