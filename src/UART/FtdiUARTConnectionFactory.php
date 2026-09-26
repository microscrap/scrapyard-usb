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
        return static::product($this->device) ?? throw UARTException::invalidFtdiDevice($this->device);
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

    /**
     * The name an MPSSE driver uses for the same interface. libftdi opens interface A, so a UART on an FT2232H or
     * FT4232H shares it with MPSSE channel A; a chip with no MPSSE engine can never collide.
     */
    public static function bridge(FtdiProductId $product): string
    {
        return match ($product) {
            FtdiProductId::FT232H => 'ft232h',
            FtdiProductId::FT2232H => 'ft2232hl-a',
            FtdiProductId::FT4232H => 'ft4232hl-a',
            default => strtolower($product->name),
        };
    }

    protected function getHandle(): FtdiPort
    {
        // ftdi_new() allocates and initialises the context: a second ftdi_init() would leak its read buffer
        $context = ftdi_new();

        if ($context->handle <= 0) {
            throw UARTException::couldNotOpenUARTPort($this->device);
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
            ftdi_set_line_property($context, $this->data_bits->value, $this->ftdiStopBits(), $this->parity->value),
        );
        // XON/XOFF needs its two characters: flow control 0x400 alone would stop and start on 0x00
        $this->assertConfigured($context, 'flow control', $this->flow_control === FlowControl::SOFTWARE
            ? ftdi_setflowctrl_xonxoff($context, 0x11, 0x13)
            : ftdi_setflowctrl($context, $this->ftdiFlowControl()));
        // 1 ms latency: an empty read comes back after 1 ms, which bounds how long a loop sample holds the loop
        $this->assertConfigured($context, 'latency timer', ftdi_set_latency_timer($context, 1));
        // DTR and RTS released: a module wired to reset on DTR (the bench's RYLR998 on D4) boots, whatever the last run left
        $this->assertConfigured($context, 'modem lines', ftdi_setdtr_rts($context, 0, 0));
        ftdi_usb_purge_buffers($context);

        return new FtdiPort(new FtdiSerialLink($context), $this->baud_rate, static::bridge($this->device()));
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
