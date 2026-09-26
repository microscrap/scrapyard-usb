<?php

use Microscrap\Bindings\MPSSE\Enums\MPSSEClockRate;
use Microscrap\Bindings\MPSSE\Enums\MPSSEEndianness;
use Microscrap\Bindings\MPSSE\Enums\MPSSEMode;
use Microscrap\Bindings\MPSSE\Enums\MpsseSupportedDevice;
use Microscrap\Bindings\MPSSE\MPSSE;
use Microscrap\ScrapyardUSB\Digital\MpsseDigitalIOConnectionDriver;
use PHPUnit\Framework\SkippedWithMessageException;

function ft232h(): MpsseDigitalIOConnectionDriver
{
    $probe = MPSSE::openDevice(MpsseSupportedDevice::FT232H, MPSSEMode::GPIO, MPSSEClockRate::ONE_MHZ->value, MPSSEEndianness::MSB);

    if (! $probe->open) {
        throw new SkippedWithMessageException('FT232H not detected: '.MPSSE::errorString($probe));
    }

    MPSSE::close($probe);

    $driver = new MpsseDigitalIOConnectionDriver;
    $driver->connectTo('ft232h')->register();

    return $driver;
}

it('drives and reads back all twelve GPIO pins on an FT232H', function () {
    $driver = ft232h();

    try {
        foreach (range(0, 11) as $pin) {
            $out = $driver->output('ft232h', $pin);

            // write() answers whether the pin reached the level, so true both ways
            expect($out->write(true))->toBeTrue("pin {$pin} high")
                ->and($out->read())->toBeTrue("pin {$pin} reads high")
                ->and($out->write(false))->toBeTrue("pin {$pin} low")
                ->and($out->read())->toBeFalse("pin {$pin} reads low");
        }
    } finally {
        $driver->disconnect('ft232h');
    }
});
