<?php

namespace Microscrap\ScrapyardUSB\I2C;

use GeneralPurposeIO\I2C\I2CTransport;
use Microscrap\Bindings\MPSSE\MPSSE;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use GeneralPurposeIO\Contracts\NutsAndBolts\Splices16Bits;
use Microscrap\ScrapyardUSB\Mpsse\RunsOnTheUsbPump;

class MpsseI2CTransport extends I2CTransport
{
    use Splices16Bits;
    use RunsOnTheUsbPump;

    public function __construct(
        int $address,
        protected readonly MPSSEContext $context,
    ) {
        parent::__construct($address);
    }

    public function handle(): MPSSEContext
    {
        return $this->context;
    }

    public function probe(): bool
    {
        $this->ensureOpen();
        $this->awaitTurn();

        return $this->transact(
            $this->context,
            function (): bool {
                MPSSE::start($this->context);
                $acknowledged = $this->writeByte(($this->address << 1) | 0);
                MPSSE::stop($this->context);

                return $acknowledged;
            },
            fn (?array $reply): bool => ! is_null($reply) && $reply[0],
        );
    }

    public function read(int $len): array|false
    {
        $this->ensureOpen();
        $this->ensureFits($len);
        $this->awaitTurn();

        return $this->transact(
            $this->context,
            function () use ($len): array|false {
                MPSSE::start($this->context);

                if (! $this->writeByte(($this->address << 1) | 1)) {
                    MPSSE::stop($this->context);

                    return false;
                }

                $data = $this->clockIn($len);

                MPSSE::stop($this->context);

                return is_null($data) ? false : bytes2array($data);
            },
            fn (?array $reply): array|false => ! is_null($reply) && $reply[0] ? bytes2array($reply[1]) : false,
        );
    }

    public function write(array|string $data): int
    {
        if (is_array($data)) {
            $data = array2bytes($data);
        }

        $this->ensureOpen();
        $this->ensureFits(strlen($data));
        $this->awaitTurn();

        return $this->transact(
            $this->context,
            function () use ($data): int {
                MPSSE::start($this->context);

                $acknowledged = $this->writeByte(($this->address << 1) | 0)
                    && $this->clockOut($data);

                MPSSE::stop($this->context);

                return $acknowledged ? strlen($data) : -1;
            },
            fn (?array $reply): int => ! is_null($reply) && $reply[0] ? strlen($data) : -1,
        );
    }

    public function writeRead(array|string $bytes_to_write, int $bytes_to_read): array|false
    {
        if (is_array($bytes_to_write)) {
            $bytes_to_write = array2bytes($bytes_to_write);
        }

        $this->ensureOpen();
        $this->ensureFits(strlen($bytes_to_write));
        $this->ensureFits($bytes_to_read);
        $this->awaitTurn();

        return $this->transact(
            $this->context,
            function () use ($bytes_to_write, $bytes_to_read): array|false {
                MPSSE::start($this->context);

                $wrote = $this->writeByte(($this->address << 1) | 0)
                    && $this->clockOut($bytes_to_write)
                    && $this->acknowledgedSoFar($this->context);      // pumped: the write phase's ACKs, before the repeated START

                if (! $wrote) {
                    MPSSE::stop($this->context);

                    return false;
                }

                MPSSE::start($this->context); // repeated START

                if (! $this->writeByte(($this->address << 1) | 1)) {
                    MPSSE::stop($this->context);

                    return false;
                }

                $data = $this->clockIn($bytes_to_read);

                MPSSE::stop($this->context);

                return is_null($data) ? false : bytes2array($data);
            },
            fn (?array $reply): array|false => ! is_null($reply) && $reply[0] ? bytes2array($reply[1]) : false,
        );
    }

    /** Each chunk is its own message behind a repeated START, one STOP at the end: the same framing as Linux I2C_RDWR. */
    public function bulkWrite(array|string $messages): array|false
    {
        $this->ensureOpen();

        $chunks = static::normalizeBulkMessages($messages);

        foreach ($chunks as $chunk) {
            $this->ensureFits(strlen($chunk));
        }

        $this->awaitTurn();

        if (count($chunks) === 0) {
            return [];
        }

        return $this->transact(
            $this->context,
            function () use ($chunks): array|false {
                $acknowledged = true;
                $last = array_key_last($chunks);

                foreach ($chunks as $i => $chunk) {
                    MPSSE::start($this->context); // START on the first chunk, repeated START after

                    $acknowledged = $this->writeByte(($this->address << 1) | 0)
                        && $this->clockOut($chunk)
                        && ($i === $last || $this->acknowledgedSoFar($this->context));   // pumped: this message's ACKs, before the next START

                    if (! $acknowledged) {
                        break;
                    }
                }

                MPSSE::stop($this->context);

                return $acknowledged ? array_map('strlen', $chunks) : false;
            },
            fn (?array $reply): array|false => ! is_null($reply) && $reply[0] ? array_map('strlen', $chunks) : false,
        );
    }

    /** The context belongs to the bus; the driver's disconnect() closes it. */
    protected function release(): void {}

    private function writeByte(int $byte): bool
    {
        if (MPSSE::write($this->context, chr($this->getLowByte($byte))) !== 0) {
            return false;
        }

        return MPSSE::getAck($this->context) === 0;
    }

    private function clockOut(string $data): bool
    {
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            if (! $this->writeByte(ord($data[$i]))) {
                return false;
            }
        }

        return true;
    }

    private function clockIn(int $len): ?string
    {
        if ($len <= 0) {
            return '';
        }

        $data = '';

        if ($len > 1) {
            MPSSE::sendAcks($this->context);
            $chunk = MPSSE::read($this->context, $len - 1);

            if (is_null($chunk)) {
                return null;
            }

            $data .= $chunk;
        }

        MPSSE::sendNacks($this->context);
        $last = MPSSE::read($this->context, 1);

        if (is_null($last)) {
            return null;
        }

        return $data.$last;
    }
}
