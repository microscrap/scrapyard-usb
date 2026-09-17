<?php

namespace Microscrap\ScrapyardUSB\UART;

use Ftdi\FTDIContext;
use GeneralPurposeIO\Contracts\UART\FlowControl;
use GeneralPurposeIO\Contracts\UART\StopBits;
use GeneralPurposeIO\Contracts\UART\UARTException;
use GeneralPurposeIO\UART\UARTConnectionFactory;
use Microscrap\Bindings\FTDI\Enums\FtdiProductId;
use Microscrap\Bindings\FTDI\Enums\FtdiVendorId;

class FtdiUARTConnectionFactory extends UARTConnectionFactory
{
    public function __construct(
        string $device,
        FtdiUARTConnectionDriver $driver
    ) {
        parent::__construct($device, $driver);
    }

    protected function device(): FtdiProductId
    {
        return static::product($this->device) ?? throw new UARTException("Invalid FTDI device {$this->device}");
    }

    /**
     * FTDI products are int-backed USB ids; the wire name is the case name
     * (ft232h, ft2232h, …) or the id itself as decimal or 0x hex.
     */
    public static function product(string $device): ?FtdiProductId
    {
        foreach (FtdiProductId::cases() as $product) {
            if (strcasecmp($product->name, $device) === 0) {
                return $product;
            }
        }

        if (preg_match('/^0x[0-9a-f]+$/i', $device) === 1) {
            return FtdiProductId::tryFrom(hexdec($device));
        }

        return ctype_digit($device) ? FtdiProductId::tryFrom((int) $device) : null;
    }

    protected function getHandle(): FTDIContext
    {
        $context = ftdi_new();

        if ($context->handle < 0) {
            throw UARTException::couldNotOpenUARTPort($this->device()->value);
        }

        if (ftdi_init($context) !== 0) {
            $error = ftdi_get_error_string($context);
            ftdi_free($context);

            throw UARTException::couldNotConfigureFtdiDevice($this->device()->name, 'initialization', $error);
        }

        if (ftdi_usb_open($context, FtdiVendorId::FTDI->value, $this->device()->value) !== 0) {
            $error = ftdi_get_error_string($context);
            ftdi_deinit($context);
            ftdi_free($context);

            throw UARTException::couldNotOpenFtdiDevice($this->device()->name, $error);
        }

        $this->assertConfigured($context, 'async serial mode', ftdi_set_bitmode($context, 0x00, 0x00));
        $this->assertConfigured($context, 'baud rate', ftdi_set_baudrate($context, $this->baud_rate));
        $this->assertConfigured(
            $context,
            'line properties',
            ftdi_set_line_property(
                $context,
                $this->data_bits->value,
                $this->ftdiStopBits(),
                $this->parity->value,
            ),
        );
        $this->assertConfigured($context, 'flow control', ftdi_setflowctrl($context, $this->ftdiFlowControl()));

        // 1ms latency keeps short USB bulk reads responsive for sensors like LD2410C.
        if (function_exists('ftdi_set_latency_timer')) {
            $this->assertConfigured($context, 'latency timer', ftdi_set_latency_timer($context, 1));
        }

        ftdi_usb_purge_buffers($context);

        return $context;
    }

    private function ftdiStopBits(): int
    {
        return match ($this->stop_bits) {
            StopBits::ONE => 0,
            StopBits::TWO => 2,
        };
    }

    private function ftdiFlowControl(): int
    {
        return match ($this->flow_control) {
            FlowControl::NONE => 0,
            FlowControl::HARDWARE => 256,
            FlowControl::SOFTWARE => 1024,
        };
    }

    private function assertConfigured(FTDIContext $context, string $operation, int $result): void
    {
        if ($result === 0) {
            return;
        }

        $error = ftdi_get_error_string($context);
        ftdi_usb_close($context);
        ftdi_deinit($context);
        ftdi_free($context);

        throw UARTException::couldNotConfigureFtdiDevice($this->device()->name, $operation, $error);
    }
}