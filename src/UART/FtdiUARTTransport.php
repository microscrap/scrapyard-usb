<?php

namespace Microscrap\ScrapyardUSB\UART;

use Ftdi\FTDIContext;
use GeneralPurposeIO\UART\UARTTransport;

class FtdiUARTTransport extends UARTTransport
{

    public function __construct(
        protected readonly FTDIContext $context,
    ) {}

    public function handle(): FTDIContext
    {
        return $this->context;
    }

    public function close(): void
    {
        ftdi_usb_close($this->context);
        ftdi_deinit($this->context);
        ftdi_free($this->context);

    }

    public function flush(): void
    {
        ftdi_usb_purge_buffers($this->context);
    }

    public function path(): string
    {
        return 'usb:'.$this->context->handle;
    }

    public function read(int $length): array|false
    {
        $data = ftdi_read_data($this->context, $length);

        return empty($data) ? false : bytes2array($data);
    }

    public function write(array|string $data): int
    {
        $data = static::normalizeData($data);

        return ftdi_write_data($this->context, $data, strlen($data));
    }

    public function pollBytes(int $max_bytes = 4096, int $poll_budget_ns = 1_000_000): string
    {
        $bytes = '';
        $deadline = hrtime(true) + $poll_budget_ns;

        while (strlen($bytes) < $max_bytes && hrtime(true) < $deadline) {
            $byte = ftdi_read_data($this->context, 1);

            if ($byte === '') {
                break;
            }

            $bytes .= $byte;
        }

        return $bytes;
    }
}