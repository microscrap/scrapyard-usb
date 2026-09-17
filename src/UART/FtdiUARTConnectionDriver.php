<?php

namespace Microscrap\ScrapyardUSB\UART;

use Ftdi\FTDIContext;
use GeneralPurposeIO\Contracts\UART\UARTException;
use GeneralPurposeIO\Contracts\UART\UARTTransport;
use GeneralPurposeIO\UART\UARTConnectionDriver;

class FtdiUARTConnectionDriver extends UARTConnectionDriver
{

    protected function newConnection(string $device): FtdiUARTConnectionFactory
    {
        if(!is_null(FtdiUARTConnectionFactory::product($device)))
        {
            return new FtdiUARTConnectionFactory($device, $this);
        }

        throw new UARTException("Invalid FTDI device {$device}");
    }

    protected function getTransport(int|string $device): UARTTransport
    {
        /** @var FTDIContext $handle */
        $handle = $this->connections->get($device);

        return new FtdiUARTTransport($handle);
    }
}