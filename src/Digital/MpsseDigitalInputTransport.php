<?php

namespace Microscrap\ScrapyardUSB\Digital;

use GeneralPurposeIO\Contracts\Digital\DigitalEdgeEvent;
use GeneralPurposeIO\Contracts\Digital\SignalEdge;
use GeneralPurposeIO\Digital\DigitalInputTransport;
use Microscrap\Bindings\MPSSE\MPSSEContext;

class MpsseDigitalInputTransport extends DigitalInputTransport
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

    public function pollEdges(bool $rising_events, bool $falling_events): array
    {
        if (! isset($this->line_values[$this->pin])) {
            $this->read();

            return [];
        }

        $previous = $this->line_values[$this->pin];
        $event = $this->toDigitalInputEvent($previous, $this->read(), $rising_events, $falling_events);

        return is_null($event) ? [] : [$event];
    }

    public function listen(int $timeout, bool $rising_events, bool $falling_events): ?DigitalEdgeEvent
    {
        if ($timeout < 0) {
            return null;
        }

        $previous = $this->line_values[$this->pin] ?? $this->read();

        if ($timeout === 0) {
            return $this->toDigitalInputEvent($previous, $this->read(), $rising_events, $falling_events);
        }

        $deadline_ns = hrtime(true) + ($timeout * 1_000_000);
        do {
            $current = $this->read();
            $event = $this->toDigitalInputEvent($previous, $current, $rising_events, $falling_events);
            if (! is_null($event)) {
                return $event;
            }

            $previous = $current;
            usleep(1_000);
        } while (hrtime(true) < $deadline_ns);

        return null;
    }

    protected function toDigitalInputEvent(bool $previous, bool $current, bool $rising_events, bool $falling_events): ?DigitalEdgeEvent
    {
        if ($previous === $current) {
            return null;
        }

        $edge = $current
            ? ($rising_events ? SignalEdge::RISING : null)
            : ($falling_events ? SignalEdge::FALLING : null);

        return is_null($edge) ? null : new DigitalEdgeEvent($edge, hrtime(true));
    }


    public function close(): void
    {
        mpsse_close($this->context);
    }
}