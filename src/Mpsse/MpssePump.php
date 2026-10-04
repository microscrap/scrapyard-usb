<?php

namespace Microscrap\ScrapyardUSB\Mpsse;

use Closure;
use Fiber;
use LogicException;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\Bindings\MPSSE\MPSSERecording;
use Voyager\Contracts\IOPools\Loop;
use Voyager\Contracts\IOPools\LoopResources\Timer;
use WeakMap;

/**
 * One FTDI context's USB traffic, taken in turns: first in first out, one holder at a time.
 * - exclusive() waits for its turn (borrowing the loop, or suspending a fiber) and holds the context while its body
 *   runs. On the main stack the body talks to USB directly; in a fiber it records and exchange()s, so the loop turns.
 *   Called again from the stack that holds the turn, it runs the body at once: the turn is already that stack's.
 * - exchange() carries one recorded transaction over the link and polls it on a 1 ms timer until the reply is in or
 *   the link gives up on it.
 * - The timer lives while any turn is held or waiting, not only while a transfer is in flight: a fiber granted its
 *   turn is resumed on a loop turn, and until() with no timer left cancels waiting fibers instead of turning.
 * Every wait is Loop::until(), which never flushes promise callbacks inside a fiber: a job fiber runs no one else's code.
 */
final class MpssePump
{
    /** @var WeakMap<MPSSEContext, self>|null */
    private static ?WeakMap $pumps = null;

    /** @var list<MpsseTurn> */
    private array $turns = [];

    private ?MpsseTurn $holder = null;

    private ?MpsseExchange $inflight = null;

    private ?Timer $ticker = null;

    private function __construct(
        private readonly MPSSEContext $context,
        private readonly Loop $loop,
        private readonly MpsseLink $link,
    ) {}

    /**
     * The context's pump, made on first use. From then on every USB exchange on the context goes through it.
     * $link carries the transactions (libftdi's asynchronous transfers when not given); it counts on first use only.
     */
    public static function for(MPSSEContext $context, Loop $loop, ?MpsseLink $link = null): self
    {
        self::$pumps ??= new WeakMap;

        return self::$pumps[$context] ??= new self($context, $loop, $link ?? new FtdiLink($context));
    }

    public static function existing(MPSSEContext $context): ?self
    {
        return isset(self::$pumps[$context]) ? self::$pumps[$context] : null;
    }

    /** Waits for the context's pump to go idle, then drops it. */
    public static function forget(MPSSEContext $context): void
    {
        if (isset(self::$pumps[$context])) {
            self::$pumps[$context]->quiesce();
            unset(self::$pumps[$context]);
        }
    }

    /** Runs $body once every turn taken before this one has finished; no other turn starts until it returns. */
    public function exclusive(Closure $body): mixed
    {
        $fiber = Fiber::getCurrent();

        if ($this->heldBy($fiber)) {
            return $body();                 // the holder's own nested call: the turn is already its
        }

        $turn = $this->turns[] = new MpsseTurn($fiber);
        $this->grant();
        $this->keepTicking();
        $this->loop->until(fn (): bool => $turn->granted || $this->heldBy($fiber));

        if (! $turn->granted) {
            // main stack only: a loop callback ran while a turn further down this stack waited, and that turn came up
            // first. The wire is this stack's now; waiting for that turn to end would wait for this callback to return.
            $this->turns = array_values(array_filter($this->turns, fn (MpsseTurn $waiting): bool => $waiting !== $turn));

            return $body();
        }

        try {
            return $body();
        } finally {
            $this->holder = null;
            $this->grant();
        }
    }

    /**
     * Inside exclusive(): sends $recording and waits for its reply.
     * @return string|false the reply bytes, or false when the link lost the transaction
     */
    public function exchange(MPSSERecording $recording): string|false
    {
        if (is_null($this->holder)) {
            throw new LogicException('MpssePump::exchange() outside MpssePump::exclusive().');
        }

        $exchange = $this->link->submit($recording);

        if (! $exchange->done) {
            $this->inflight = $exchange;
            $this->keepTicking();
            $this->loop->until(fn (): bool => $exchange->done);
        }

        return $exchange->reply;
    }

    public function idle(): bool
    {
        return is_null($this->holder) && $this->turns === [];
    }

    /** Waits (borrowing the loop, or suspending a fiber) until no turn is held or waiting. */
    public function quiesce(): void
    {
        if (! $this->idle()) {
            $this->loop->until(fn (): bool => $this->idle());
        }
    }

    /** Whether the turn now held belongs to $fiber's stack (null: the main stack). */
    private function heldBy(?Fiber $fiber): bool
    {
        return ! is_null($this->holder) && $this->holder->fiber === $fiber;
    }

    private function keepTicking(): void
    {
        $this->ticker ??= $this->loop->every(0.001, fn () => $this->tick(), 'mpsse-pump:'.spl_object_id($this->context));
    }

    private function grant(): void
    {
        if (is_null($this->holder) && $this->turns !== []) {
            $this->holder = array_shift($this->turns);
            $this->holder->granted = true;
        }
    }

    private function tick(): void
    {
        $exchange = $this->inflight;

        if (is_null($exchange)) {
            if ($this->idle()) {
                $this->ticker?->cancel();
                $this->ticker = null;
            }

            return;
        }

        $this->link->poll($exchange);

        if ($exchange->done) {
            $this->inflight = null;
        }
    }
}
