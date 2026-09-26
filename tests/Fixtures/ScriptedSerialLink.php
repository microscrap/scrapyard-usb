<?php

namespace Microscrap\ScrapyardUSB\Tests\Fixtures;

use Microscrap\ScrapyardUSB\UART\SerialLink;

/** An FT232H with no USB behind it: reads play back $replies, sends are kept, and a test decides when they finish. */
final class ScriptedSerialLink implements SerialLink
{
    /** @var list<string> what each read() hands back, oldest first; '' once they run out */
    public array $replies = [];

    /** How many reads were made. */
    public int $reads = 0;

    /** @var list<int> the $max each read asked for */
    public array $asked = [];

    /** The modem status word; negative plays a lost device. */
    public int $status_word = 0x6002;

    /** @var list<string> every send, oldest first */
    public array $sends = [];

    /** How many sent() calls report each new send unfinished. */
    public int $busy_polls = 0;

    /** While true, no send ever finishes. */
    public bool $stuck = false;

    /** What a finished send reports written; null: all of it. */
    public ?int $writes = null;

    /** libusb refuses every send. */
    public bool $refuse = false;

    /** What setDtr()/setRts() return. */
    public int $line_result = 0;

    /** @var array<string, bool> */
    public array $lines = [];

    public int $purges = 0;

    public bool $closed = false;

    /** Whether the last send had finished when close() came. */
    public bool $finished_before_close = false;

    private bool $sending = false;

    private int $busy = 0;

    public function read(int $max): string
    {
        $this->reads++;
        $this->asked[] = $max;

        if ($this->replies === []) {
            usleep(1_000);          // the chip answers an empty read after one latency period

            return '';
        }

        return array_shift($this->replies);
    }

    public function status(): int
    {
        return $this->status_word;
    }

    public function send(string $bytes): bool
    {
        if ($this->refuse) {
            return false;
        }

        $this->sends[] = $bytes;
        [$this->sending, $this->busy] = [true, $this->busy_polls];

        return true;
    }

    public function sent(): bool
    {
        if (! $this->sending) {
            return true;
        }

        if ($this->stuck || $this->busy-- > 0) {
            return false;
        }

        $this->sending = false;

        return true;
    }

    public function sentBytes(): int
    {
        return $this->writes ?? strlen((string) end($this->sends));
    }

    public function awaitSent(int $timeout_ms): void
    {
        $deadline = $timeout_ms < 0 ? null : hrtime(true) + $timeout_ms * 1_000_000;

        while (! $this->sent() && (is_null($deadline) || hrtime(true) < $deadline)) {
            usleep(1_000);
        }
    }

    public function setDtr(bool $asserted): int
    {
        $this->lines['dtr'] = $asserted;

        return $this->line_result;
    }

    public function setRts(bool $asserted): int
    {
        $this->lines['rts'] = $asserted;

        return $this->line_result;
    }

    public function purge(): void
    {
        $this->purges++;
        $this->replies = [];
    }

    public function close(): void
    {
        $this->finished_before_close = ! $this->sending;
        $this->closed = true;
    }
}
