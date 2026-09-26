<?php

namespace Microscrap\ScrapyardUSB\Mpsse;

use Microscrap\Bindings\MPSSE\MPSSEContext;
use Microscrap\Bindings\MPSSE\MPSSERecording;

/**
 * libftdi's asynchronous transfers. The read is submitted before the write, so the reply always has somewhere to
 * land. poll() collects completions: on Linux they arrive as write-readiness on the usbfs fd, which stream_select
 * does not watch, and on macOS the only pollfd is libusb's own pipe. A transaction past its deadline is cancelled and
 * the RX buffer purged, so a late reply cannot land in the next one.
 */
final class FtdiLink implements MpsseLink
{
    public function __construct(
        private readonly MPSSEContext $context,
    ) {}

    /**
     * How long a transaction may take before it counts as lost: one second, plus twice the time the engine needs to
     * clock every command and reply byte at $clock_hz. A read's commands are 3 bytes per 63 KB, so the budget follows
     * the bytes clocked, not the bytes written.
     */
    public static function deadline(MPSSERecording $recording, int $clock_hz): float
    {
        $bytes = strlen($recording->commands) + $recording->responseLength();

        return 1.0 + 2 * 8 * $bytes / max($clock_hz, 1_000);
    }

    public function submit(MPSSERecording $recording): MpsseExchange
    {
        $exchange = new MpsseExchange($recording);
        $ftdi = $this->context->ftdi;
        $length = $recording->responseLength();

        if ($length > 0) {
            $exchange->read = ftdi_read_data_submit($ftdi, $length);

            if ($exchange->read->handle === 0) {
                return $exchange->settle(false);            // nothing written yet: the chip never saw this transaction
            }
        }

        $exchange->write = ftdi_write_data_submit($ftdi, $recording->commands, strlen($recording->commands));

        if ($exchange->write->handle === 0) {
            if (! is_null($exchange->read)) {
                ftdi_transfer_data_cancel($exchange->read);
            }

            return $exchange->settle(false);
        }

        $exchange->deadline = microtime(true) + self::deadline($recording, $this->context->clock);

        return $exchange;
    }

    public function poll(MpsseExchange $exchange): void
    {
        ftdi_handle_events_timeout($this->context->ftdi, 0);

        $done = ftdi_transfer_completed($exchange->write) !== 0
            && (is_null($exchange->read) || ftdi_transfer_completed($exchange->read) !== 0);

        if (! $done) {
            if (microtime(true) < $exchange->deadline) {
                return;
            }

            ftdi_transfer_data_cancel($exchange->write);

            if (! is_null($exchange->read)) {
                ftdi_transfer_data_cancel($exchange->read);
            }

            ftdi_usb_purge_rx_buffer($this->context->ftdi);    // a late reply must not land in the next transaction
            $exchange->settle(false);

            return;
        }

        $wrote = ftdi_transfer_data_done($exchange->write);
        $reply = is_null($exchange->read) ? '' : ftdi_transfer_read_done($exchange->read);

        $failed = $wrote !== strlen($exchange->recording->commands)
            || $reply === false
            || strlen($reply) !== $exchange->recording->responseLength();

        $exchange->settle($failed ? false : $reply);
    }
}
