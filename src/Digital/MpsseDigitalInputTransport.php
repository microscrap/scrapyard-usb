<?php

namespace Microscrap\ScrapyardUSB\Digital;

use GeneralPurposeIO\Contracts\Digital\DigitalEdgeEvent;
use GeneralPurposeIO\Contracts\Digital\DigitalIOException;
use GeneralPurposeIO\Contracts\Digital\SignalEdge;
use GeneralPurposeIO\Digital\DigitalInputTransport;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\Mpsse\RunsOnTheUsbPump;

/**
 * No GPIO interrupt on FTDI: edges are level changes between samples, taken every pollEvery() ms.
 */
class MpsseDigitalInputTransport extends DigitalInputTransport
{
    use RunsOnTheUsbPump;

    private int $poll_ms = 10;
    private ?bool $sampled = null;
    private int $seqno = 0;

    public function __construct(
        int $pin,
        protected readonly MPSSEContext $context,
    ) {
        parent::__construct($pin);
    }

    /** Sample interval, on the loop and in a blocking listen(). Applies at once to a pin already on the loop. */
    public function pollEvery(int $ms): static
    {
        $this->poll_ms = max(1, $ms);
        $this->settle();

        return $this;
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

    /** One sample; an edge when it differs from this method's previous sample. read() never moves that baseline. */
    protected function drainEdges(): array
    {
        [$previous, $this->sampled] = [$this->sampled, $this->read()];

        if (is_null($previous) || $previous === $this->sampled) {
            return [];
        }

        return [new DigitalEdgeEvent(
            $this->device,
            $this->pin,
            $this->sampled ? SignalEdge::RISING : SignalEdge::FALLING,
            hrtime(true),
            ++$this->seqno,
        )];
    }

    protected function awaitEdges(int $timeout_ms): void
    {
        usleep(1_000 * ($timeout_ms < 0 ? $this->poll_ms : min($this->poll_ms, $timeout_ms)));
    }

    protected function edgeStreams(): array
    {
        return [];
    }

    protected function samplingInterval(): ?float
    {
        return $this->poll_ms / 1000;
    }

    /** The context belongs to the connection; disconnect() closes it. */
    protected function release(): void {}
}
