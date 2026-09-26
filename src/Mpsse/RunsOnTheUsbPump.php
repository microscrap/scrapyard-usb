<?php

namespace Microscrap\ScrapyardUSB\Mpsse;

use Closure;
use Fiber;
use Microscrap\Bindings\MPSSE\MPSSE;
use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\Bindings\MPSSE\MPSSERecording;

/**
 * One MPSSE exchange, whoever makes it: I2C slaves, SPI slaves and DigitalIO pins on a shared FT232H.
 * - No pump on the context (nothing was ever offloaded there): $live runs now, exactly as it always has.
 * - On the main stack with a pump: wait for this call's turn on the pump, then run $live now.
 * - Inside a fiber: take a turn, record $live instead of sending it, and let the pump carry it while the fiber
 *   suspends. $decode turns [every ACK low, the data bytes], or null for a lost transfer, into the result.
 *   A transaction that checks its ACKs before a repeated START (acknowledgedSoFar()) goes out in segments, so it
 *   stops on the wire where the blocking call stops.
 * - A recording open when the turn comes is this fiber's own select() (holdTheWire()): $live joins it, and all of it
 *   recorded so far goes out as one segment, so the call has its reply before it returns.
 */
trait RunsOnTheUsbPump
{
    /** Set while this transport records inside the pump. */
    private ?MpssePump $pumping = null;

    /** False once a segment of the pumped transaction came back with a NACK or a failed transfer. */
    private bool $pumped_acks = true;

    protected function transact(MPSSEContext $context, Closure $live, Closure $decode): mixed
    {
        $pump = MpssePump::existing($context);

        if (is_null($pump)) {
            return $live();
        }

        if (is_null(Fiber::getCurrent()) && is_null($context->recording)) {
            return $pump->exclusive($live);
        }

        // decided once the turn is ours: a recording open by then belongs to this stack's select()
        return $pump->exclusive(fn (): mixed => is_null($context->recording)
            ? $this->pumped($pump, $context, $live, $decode)
            : $this->segment($pump, $context, $live, $decode));
    }

    /**
     * A run of calls nobody else's USB traffic may split (an SPI select() body): one turn for all of it. On the main
     * stack the calls run live inside the turn. In a fiber they record: each call inside cuts its own segment and sends
     * it, and whatever is left (chip select going up) is sent at the end, also when $run throws.
     */
    protected function holdTheWire(MPSSEContext $context, Closure $run): mixed
    {
        $pump = MpssePump::existing($context);

        if (is_null($pump)) {
            return $run();
        }

        if (is_null(Fiber::getCurrent())) {
            return $pump->exclusive($run);
        }

        return $pump->exclusive(function () use ($pump, $context, $run): mixed {
            $context->recording = new MPSSERecording;

            try {
                return $run();
            } finally {
                $tail = MPSSE::cut($context);
                $context->recording = null;

                if ($pump->exchange($tail) === false) {
                    $this->exchangeLost();
                }
            }
        });
    }

    /** A pumped exchange was lost somewhere in its stream. Transports that leave state on the engine repair it here. */
    protected function exchangeLost(): void {}

    /**
     * Before a repeated START: in a pumped transaction, sends what is recorded so far and answers with its real ACKs.
     * Live, every ACK is already real, so this is true.
     */
    protected function acknowledgedSoFar(MPSSEContext $context): bool
    {
        if (is_null($this->pumping)) {
            return true;
        }

        $segment = MPSSE::cut($context);
        $reply = $this->pumping->exchange($segment);
        $acked = $reply !== false && $segment->decode($reply)[0];
        $this->pumped_acks = $this->pumped_acks && $acked;

        return $acked;
    }

    private function pumped(MpssePump $pump, MPSSEContext $context, Closure $live, Closure $decode): mixed
    {
        [$this->pumping, $this->pumped_acks] = [$pump, true];
        $result = null;

        try {
            $last = MPSSE::record($context, function () use ($live, &$result): void {
                $result = $live();
            });
        } finally {
            $this->pumping = null;
        }

        $reply = $pump->exchange($last);

        // a segment NACKed: $live stopped where the blocking call stops, and this last segment was its STOP
        if (! $this->pumped_acks) {
            return $result;
        }

        return $decode($reply === false ? null : $last->decode($reply));
    }

    /** Adds $live to the recording under way, then sends all of it recorded so far and decodes the reply. */
    private function segment(MpssePump $pump, MPSSEContext $context, Closure $live, Closure $decode): mixed
    {
        $live();
        $segment = MPSSE::cut($context);
        $reply = $pump->exchange($segment);

        return $decode($reply === false ? null : $segment->decode($reply));
    }
}
