<?php

namespace Microscrap\ScrapyardUSB\UART;

use GeneralPurposeIO\Contracts\UART\UARTException;
use GeneralPurposeIO\UART\UARTConnectionDriver;
use Microscrap\ScrapyardUSB\BridgeMode;
use Microscrap\ScrapyardUSB\FtdiBridge;

class FtdiUARTConnectionDriver extends UARTConnectionDriver
{
    /** Refused before any USB is touched when MPSSE already runs the interface. */
    protected function newConnection(string $device): FtdiUARTConnectionFactory
    {
        $product = FtdiUARTConnectionFactory::product($device) ?? throw UARTException::invalidFtdiDevice($device);

        FtdiBridge::ensureFree(FtdiUARTConnectionFactory::bridge($product), BridgeMode::UART);

        return new FtdiUARTConnectionFactory($device, $this);
    }

    /** @param FtdiPort $handle */
    public function register(string $name, mixed $handle): static
    {
        FtdiBridge::claim($handle->bridge, BridgeMode::UART);

        return parent::register($name, $handle);
    }

    protected function getTransport(string $device): FtdiUARTTransport
    {
        /** @var FtdiPort $port */
        $port = $this->connections->get($device);

        return new FtdiUARTTransport($device, $port->baud, $port->link, $port->bridge);
    }

    /** @param FtdiPort $handle */
    protected function closeConnection(mixed $handle): void
    {
        $handle->link->close();
        FtdiBridge::release($handle->bridge, BridgeMode::UART);
    }
}
