<?php

namespace Microscrap\ScrapyardUSB\Digital;

use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use GeneralPurposeIO\Digital\DigitalOutputTransport;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\Mpsse\RunsOnTheUsbPump;

class MpsseDigitalOutputTransport extends DigitalOutputTransport
{
    use RunsOnTheUsbPump;

    public function __construct(
        int $pin,
        protected readonly MPSSEContext $context,
    ) {
        parent::__construct($pin);
    }

    public function read(): bool
    {
        $this->ensureOpen();

        $pins = $this->transact(
            $this->context,
            fn (): int => mpsse_read_pins($this->context),
            fn (?array $reply): int => is_null($reply) || strlen($reply[1]) !== 2 ? -1 : ord($reply[1][0]) | (ord($reply[1][1]) << 8),
        );

        if ($pins < 0) {
            throw DigitalIOException::pinsReadFailed($this->pin);
        }

        return mpsse_pin_state($this->context, $this->pin, $pins) === 1;
    }

    public function write(bool $state): bool
    {
        $this->ensureOpen();

        $written = $this->transact(
            $this->context,
            fn (): int => $state ? mpsse_pin_high($this->context, $this->pin) : mpsse_pin_low($this->context, $this->pin),
            fn (?array $reply): int => is_null($reply) ? -1 : 0,
        );

        if ($written !== 0) {
            return false;
        }

        return $this->read() === $state;
    }

    /** The context belongs to the connection; disconnect() closes it. */
    protected function release(): void {}
}
