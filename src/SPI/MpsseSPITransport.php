<?php

namespace Microscrap\ScrapyardUSB\SPI;

use Closure;
use GeneralPurposeIO\SPI\SPITransport;
use Microscrap\Bindings\MPSSE\MPSSE;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\ScrapyardUSB\Mpsse\RunsOnTheUsbPump;

/**
 * One chip select on a shared FT232H: a DigitalIO pin driven low before start() and high after stop(), in the same
 * command stream as the data. Each call also applies its clock; select() holds all of that open across its calls.
 * Once the bridge has a pump, every call takes a turn there; in a job's fiber it is recorded and carried by the pump,
 * and a select() keeps its one turn from chip select down to chip select up.
 */
class MpsseSPITransport extends SPITransport
{
    use RunsOnTheUsbPump;

    public function __construct(
        int $chip_select,
        protected readonly MPSSEContext $context,
        private readonly MpsseClock $clock,
    ) {
        parent::__construct($chip_select);
    }

    public function handle(): MPSSEContext
    {
        return $this->context;
    }

    public function speed(int $hz): static
    {
        $this->ensureOpen();
        $this->hz = $hz;

        return $this;
    }

    public function read(int $len): array|false
    {
        $this->ensureOpen();

        $rx = $this->onTheWire(fn (): ?string => MPSSE::read($this->context, $len));

        return is_null($rx) ? false : bytes2array($rx);
    }

    public function write(array|string $data): int
    {
        $data = is_array($data) ? array2bytes($data) : $data;

        $this->ensureOpen();

        $rx = $this->onTheWire(fn (): ?string => MPSSE::write($this->context, $data) === 0 ? '' : null);

        return is_null($rx) ? -1 : strlen($data);
    }

    public function transfer(array|string $data): array|false
    {
        $data = is_array($data) ? array2bytes($data) : $data;

        $this->ensureOpen();

        $rx = $this->onTheWire(fn (): ?string => MPSSE::transfer($this->context, $data));

        return is_null($rx) ? false : bytes2array($rx);
    }

    public function writeRead(array|string $bytes_to_write, int $bytes_to_read): array|false
    {
        $tx = is_array($bytes_to_write) ? array2bytes($bytes_to_write) : $bytes_to_write;

        $this->ensureOpen();

        $rx = $this->onTheWire(fn (): ?string => MPSSE::write($this->context, $tx) === 0
            ? MPSSE::read($this->context, $bytes_to_read)
            : null);

        return is_null($rx) ? false : bytes2array($rx);
    }

    protected function beginSelection(): void
    {
        $this->assertChipSelect();
    }

    protected function endSelection(): void
    {
        $this->deassertChipSelect();
    }

    /** One pump turn from chip select down to chip select up. */
    protected function whileSelected(Closure $selection): mixed
    {
        return $this->holdTheWire($this->context, $selection);
    }

    /**
     * A lost exchange may have stopped anywhere in its stream: the engine clock is unknown, and outside select() a chip
     * select may be left down. Blocking, framed()'s finally raises it again; pumped, one more exchange does.
     */
    protected function exchangeLost(): void
    {
        $this->clock->known = false;

        if (! $this->selected()) {
            $this->transact($this->context, fn () => $this->deassertChipSelect(), fn (?array $reply): null => null);
        }
    }

    /** The context belongs to the bus; the driver's disconnect() closes it. */
    protected function release(): void {}

    /**
     * One call: $io framed by chip select (unless select() holds it), carried live or by the pump.
     * $io answers the bytes it read ('' for none), or null when the engine refused.
     * @return string|null what was read, or null when the call failed
     */
    private function onTheWire(Closure $io): ?string
    {
        $refused = false;

        return $this->transact(
            $this->context,
            function () use ($io, &$refused): ?string {
                $rx = $this->framed($io);
                $refused = is_null($rx);

                return $rx;
            },
            function (?array $reply) use (&$refused): ?string {
                if ($refused) {
                    return null;
                }

                if (is_null($reply)) {
                    $this->exchangeLost();

                    return null;
                }

                return $reply[1];
            },
        );
    }

    /** Chip select around $io, unless select() already holds it. */
    private function framed(Closure $io): mixed
    {
        if ($this->selected()) {
            return $io();
        }

        $this->assertChipSelect();

        try {
            return $io();
        } finally {
            $this->deassertChipSelect();
        }
    }

    private function assertChipSelect(): void
    {
        $this->applyClock();

        MPSSE::pinLow($this->context, $this->chip_select);
        MPSSE::start($this->context);
    }

    private function deassertChipSelect(): void
    {
        MPSSE::stop($this->context);
        MPSSE::pinHigh($this->context, $this->chip_select);
    }

    private function applyClock(): void
    {
        if ($this->clock->known && $this->hz === $this->clock->applied) {
            return;
        }

        MPSSE::setClock($this->context, $this->hz ?? $this->clock->connection_hz);
        [$this->clock->applied, $this->clock->known] = [$this->hz, true];
    }
}
