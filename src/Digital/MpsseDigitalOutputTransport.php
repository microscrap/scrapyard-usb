<?php

namespace Microscrap\ScrapyardUSB\Digital;

use GeneralPurposeIO\Digital\DigitalOutputTransport;
use Microscrap\Bindings\MPSSE\MPSSEContext;

class MpsseDigitalOutputTransport extends DigitalOutputTransport
{
    protected array $line_values = [];

    public function __construct(
        int $pin,
        protected readonly MPSSEContext $context,
    ) {
        parent::__construct($pin);
    }

    public function read(): bool
    {
        $value = mpsse_pin_state($this->context, $this->pin, mpsse_read_pins($this->context)) == 1;

        return $this->line_values[$this->pin] = $value;
    }

    public function write(bool $state): bool
    {
        $written = $state
            ? mpsse_pin_high($this->context, $this->pin)
            : mpsse_pin_low($this->context, $this->pin);

        if ($written !== 0) {
            return false;
        }

        return $this->read() === $state;
    }

    public function close(): void
    {
        mpsse_close($this->context);
    }
}