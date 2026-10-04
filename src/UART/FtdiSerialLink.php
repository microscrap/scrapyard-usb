<?php

namespace Microscrap\ScrapyardUSB\UART;

use Ftdi\FTDIContext;
use Ftdi\FTDITransferControl;

/** libftdi on one open context: synchronous reads, asynchronous sends whose completion is polled, never waited on. */
final class FtdiSerialLink implements SerialLink
{
    private ?FTDITransferControl $sending = null;

    private int $sent_bytes = 0;

    public function __construct(
        public readonly FTDIContext $context,
    ) {}

    /** Up to $max bytes; "" when none are waiting or the device is gone (then status() turns negative). */
    public function read(int $max): string
    {
        $bytes = ftdi_read_data($this->context, $max);

        return $bytes === false ? '' : $bytes;
    }

    public function status(): int
    {
        return ftdi_poll_modem_status($this->context);
    }

    public function send(string $bytes): bool
    {
        $transfer = ftdi_write_data_submit($this->context, $bytes, strlen($bytes));

        if ($transfer === null) {
            return false;
        }

        $this->sending = $transfer;

        return true;
    }

    public function sent(): bool
    {
        if (is_null($this->sending)) {
            return true;
        }

        ftdi_handle_events_timeout($this->context, 0);

        if (ftdi_transfer_completed($this->sending) === 0) {
            return false;
        }

        $this->sent_bytes = ftdi_transfer_data_done($this->sending);
        $this->sending = null;

        return true;
    }

    public function sentBytes(): int
    {
        return $this->sent_bytes;
    }

    public function awaitSent(int $timeout_ms): void
    {
        $deadline = $timeout_ms < 0 ? null : hrtime(true) + $timeout_ms * 1_000_000;

        while (! $this->sent() && (is_null($deadline) || hrtime(true) < $deadline)) {
            ftdi_handle_events_timeout($this->context, 1_000);      // libusb sleeps until an event or 1 ms passes
        }
    }

    public function setDtr(bool $asserted): int
    {
        return ftdi_setdtr($this->context, $asserted ? 1 : 0);
    }

    public function setRts(bool $asserted): int
    {
        return ftdi_setrts($this->context, $asserted ? 1 : 0);
    }

    public function purge(): void
    {
        ftdi_usb_purge_buffers($this->context);
    }

    public function close(): void
    {
        if (! is_null($this->sending)) {
            ftdi_transfer_data_cancel($this->sending);
            $this->sending = null;
        }

        ftdi_usb_close($this->context);
        ftdi_deinit($this->context);
        ftdi_free($this->context);
    }
}
