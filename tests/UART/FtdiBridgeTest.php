<?php

use GeneralPurposeIO\Contracts\Core\GPIOLevelException;
use Microscrap\ScrapyardUSB\BridgeMode;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionFactory;
use Microscrap\ScrapyardUSB\FtdiBridge;
use Microscrap\ScrapyardUSB\I2C\MpsseI2CConnectionDriver;
use Microscrap\ScrapyardUSB\SPI\MpsseSPIConnectionDriver;
use Microscrap\ScrapyardUSB\Tests\Fixtures\ScriptedSerialLink;
use Microscrap\ScrapyardUSB\UART\FtdiPort;
use Microscrap\ScrapyardUSB\UART\FtdiUARTConnectionDriver;
use Microscrap\ScrapyardUSB\UART\FtdiUARTConnectionFactory;

beforeEach(fn () => forgetBridges());
afterEach(fn () => forgetBridges());

it('refuses a UART on an FT232H that MPSSE holds, and MPSSE on one the UART holds, before touching USB', function () {
    FtdiBridge::claim('ft232h', BridgeMode::MPSSE);

    expect(fn () => (new FtdiUARTConnectionDriver)->connectTo('ft232h'))
        ->toThrow(GPIOLevelException::class, 'FTDI device ft232h is open for mpsse: its one engine runs mpsse or uart, not both.');

    FtdiBridge::release('ft232h', BridgeMode::MPSSE);
    FtdiBridge::claim('ft232h', BridgeMode::UART);
    $digital = new MpsseDigitalIOConnectionDriver;
    $busy = 'FTDI device ft232h is open for uart: its one engine runs uart or mpsse, not both.';

    expect(fn () => $digital->connectTo('ft232h'))->toThrow(GPIOLevelException::class, $busy)
        ->and(fn () => (new MpsseI2CConnectionDriver($digital))->connectTo('ft232h'))->toThrow(GPIOLevelException::class, $busy)
        ->and(fn () => (new MpsseSPIConnectionDriver($digital))->connectTo('ft232h'))->toThrow(GPIOLevelException::class, $busy);
});

it('lets the other engine in once the first lets go', function () {
    FtdiBridge::claim('ft232h', BridgeMode::UART);
    FtdiBridge::release('ft232h', BridgeMode::UART);

    expect((new MpsseDigitalIOConnectionDriver)->connectTo('ft232h'))->toBeInstanceOf(MpsseDigitalIOConnectionFactory::class);
});

it('maps a UART on an FT2232H to its channel A', function () {
    FtdiBridge::claim('ft2232hl-b', BridgeMode::MPSSE);

    expect((new FtdiUARTConnectionDriver)->connectTo('ft2232h'))->toBeInstanceOf(FtdiUARTConnectionFactory::class);

    FtdiBridge::claim('ft2232hl-a', BridgeMode::MPSSE);

    expect(fn () => (new FtdiUARTConnectionDriver)->connectTo('ft2232h'))
        ->toThrow(GPIOLevelException::class, 'FTDI device ft2232hl-a is open for mpsse');
});

it('takes the bridge for MPSSE when a context registers, and gives it back on disconnect', function () {
    $digital = new MpsseDigitalIOConnectionDriver;

    $digital->register('ft232h', recordableSpiContext());

    expect(FtdiBridge::holder('ft232h'))->toBe(BridgeMode::MPSSE);

    $digital->disconnect('ft232h');

    expect(FtdiBridge::holder('ft232h'))->toBeNull();
});

it('takes the bridge for the UART on register, and gives it back when a port was never handed out', function () {
    $driver = new FtdiUARTConnectionDriver;
    $link = new ScriptedSerialLink;

    $driver->register('ft232h', new FtdiPort($link, 115_200, 'ft232h'));

    expect(FtdiBridge::holder('ft232h'))->toBe(BridgeMode::UART);

    $driver->disconnect('ft232h');

    expect(FtdiBridge::holder('ft232h'))->toBeNull()
        ->and($link->closed)->toBeTrue();
});
