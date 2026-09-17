<?php

use GeneralPurposeIO\Contracts\UART\Parity;
use GeneralPurposeIO\Contracts\UART\UARTException;
use Microscrap\Bindings\FTDI\Enums\FtdiProductId;
use Microscrap\ScrapyardUSB\UART\FtdiUARTConnectionDriver;
use Microscrap\ScrapyardUSB\UART\FtdiUARTConnectionFactory;

it('resolves an FTDI product by case name in any case, or by its USB id as decimal or hex', function (): void {
    expect(FtdiUARTConnectionFactory::product('ft232h'))->toBe(FtdiProductId::FT232H)
        ->and(FtdiUARTConnectionFactory::product('FT2232H'))->toBe(FtdiProductId::FT2232H)
        ->and(FtdiUARTConnectionFactory::product('0x6001'))->toBe(FtdiProductId::FT232R)
        ->and(FtdiUARTConnectionFactory::product('24596'))->toBe(FtdiProductId::FT232H)
        ->and(FtdiUARTConnectionFactory::product('nope'))->toBeNull()
        ->and(FtdiUARTConnectionFactory::product('0x9999'))->toBeNull()
        ->and(FtdiUARTConnectionFactory::product(''))->toBeNull();
});

it('only connects FTDI products the bindings know', function (): void {
    $driver = new FtdiUARTConnectionDriver;

    expect(fn () => $driver->connectTo('nope'))->toThrow(UARTException::class, 'Invalid FTDI device nope')
        ->and($driver->connectTo('ft232h'))->toBeInstanceOf(FtdiUARTConnectionFactory::class);
});

it('returns null for a port that was never connected', function (): void {
    expect((new FtdiUARTConnectionDriver)->device('ft232h'))->toBeNull();
});

it('carries line settings as fluent state', function (): void {
    $factory = new FtdiUARTConnectionFactory('ft232h', new FtdiUARTConnectionDriver);

    expect($factory->baud_rate)->toBe(9_600)
        ->and($factory->baud(115_200)->parity(Parity::EVEN))->toBe($factory)
        ->and($factory->baud_rate)->toBe(115_200)
        ->and($factory->parity)->toBe(Parity::EVEN);
});
