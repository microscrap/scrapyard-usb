---
type: Concept
title: Testing
description: How the suite stands in for FTDI hardware — scripted serial and MPSSE links, recorded command streams — plus the FT232H bench that skips itself and the CI job.
tags: [testing, pest, fixtures, ci]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: pest
    resource: tests/Pest.php
    title: tests/Pest.php
  - id: ci
    resource: .github/workflows/tests.yml
    title: tests.yml
---

# Rule

The suite never needs a board or a chip. FTDI traffic is scripted or recorded; chips are for scratch smoke runs outside the suite.

# Stand-ins

| Fixture | Plays | Used by |
|---|---|---|
| `ScriptedSerialLink` | a `SerialLink`: queued reads, modem status, send completion and byte counts, DTR/RTS results | `UART/SerialPortTest`, `UART/SerialLoopTest`, `UART/FtdiBridgeTest` |
| `ScriptedMpsseLink` | an `MpsseLink`: settles exchanges with scripted replies or losses | `Mpsse/PumpTest`, `SPI/PumpedSpiTest` |
| `ScriptedMpsseDigitalInputTransport` | an input pin with scripted samples | `Digital/MpsseDigitalInputTransportTest` |
| `MPSSE::record()` streams | the exact command bytes a call would send | `SPI/ChipSelectRecordingTest`, `SPI/PumpedSpiTest` |

`I2C/ProviderTest` boots the provider and checks the I2C and SPI drivers share the `usb` DigitalIO driver. `Digital/MpsseDigitalIOConnectionDriverTest` covers device-name refusal and close/disconnect order.

# FT232H bench

`Digital/Ft232hDigitalIOTest` drives and reads back all twelve pins on a real FT232H. It needs a bare board with nothing wired to its pins. It probes with `MPSSE::openDevice` first and skips when no board answers, which is every CI run.

# Running

```bash
composer install
vendor/bin/pest
```

# CI

`.github/workflows/tests.yml`: ubuntu, PHP 8.4 and 8.5, apt `libftdi1-dev libusb-1.0-0-dev pkg-config`, `pie install php-io-extensions/ftdi:^0.9`, `composer update --prefer-stable`, `vendor/bin/pest`. Resolves `gpio/*` and `microscrap/*` 0.9 from Packagist.
