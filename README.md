# microscrap/scrapyard-usb

[![Tests](https://github.com/microscrap/scrapyard-usb/actions/workflows/tests.yml/badge.svg)](https://github.com/microscrap/scrapyard-usb/actions/workflows/tests.yml)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/microscrap/scrapyard-usb.svg)](https://packagist.org/packages/microscrap/scrapyard-usb)
[![License](https://img.shields.io/packagist/l/microscrap/scrapyard-usb.svg)](LICENSE)
[![Requires ext-ftdi](https://img.shields.io/badge/ext--ftdi-%5E0.9-777bb4?logo=php&logoColor=white)](https://github.com/php-io-extensions/ftdi)

The FTDI adapter for [`scrapyard-io/framework`](https://github.com/scrapyard-io/framework): the `usb` driver for I2C, SPI, digital pins and UART over an FTDI USB board such as the FT232H. It works on any machine with a USB port, including a Mac.

```
ext-ftdi                        1:1 libftdi1 and libmpsse calls
  → microscrap/{ftdi,mpsse}     PHP bindings
    → microscrap/scrapyard-usb  the `usb` driver per protocol   ← this package
      → scrapyard-io/framework  managers, transports, the event loop and via()
```

| Protocol | Engine | Device names |
|---|---|---|
| I2C, SPI, digital | MPSSE | `ft232h`, `ft2232hl-a`, `ft2232hl-b`, `ft4232hl-a` … `ft4232hl-d` |
| UART | FTDI serial | product name (`ft232h`, `ft2232h`, `ft4232h`, `ft232r`, `ft230x`, …) or USB product id (`0x6014`) |

There is no PWM driver.

## Requirements

- PHP 8.4 or newer, on Linux or macOS
- libftdi1 and libusb-1.0:
  - Debian and Ubuntu: `apt install libftdi1-dev libusb-1.0-0-dev pkg-config`
  - macOS: `brew install libftdi`
- [`ext-ftdi`](https://github.com/php-io-extensions/ftdi) 0.10: `pie install php-io-extensions/ftdi`
- `scrapyard-io/framework` 0.10, or just the `gpio/*` components it is split into
- On Linux, access to the USB device. For example, add `/etc/udev/rules.d/99-ftdi.rules`:

  ```
  SUBSYSTEM=="usb", ATTR{idVendor}=="0403", MODE="0660", GROUP="plugdev"
  ```

  Then reload udev and add your user to `plugdev`. When libftdi opens an interface it detaches the kernel's `ftdi_sio` driver, so the board's `/dev/ttyUSB` node disappears while this adapter holds it.

## Installation

```bash
composer require microscrap/scrapyard-usb
```

The service provider is discovered automatically and registers `usb` on the DigitalIO, I2C, SPI and UART managers. Make it the default in `config/gpio.php`, or name it at each call.

## Usage

An FT232H runs one of these at a time, so each example below is a separate board, or the same board after `disconnect()`.

SPI, with chip select on D4 (`0`) and a data/command pin on D5 (`1`):

```php
use GeneralPurposeIO\Contracts\SPI\SPIMode;

$panel = app('gpio.spi')->driver('usb')->connectTo('ft232h')->mode(SPIMode::MODE_0)->speed(10_000_000)->register()->device('ft232h', 0);
$dc = app('gpio.digital')->driver('usb')->output('ft232h', 1);
```

I2C:

```php
$sensor = app('gpio.i2c')->driver('usb')->connectTo('ft232h')->register()->device('ft232h', 0x76);
$id = $sensor->writeRead([0xD0], 1);
```

Serial, on the chip's UART engine:

```php
$radio = app('gpio.uart')->driver('usb')->connectTo('ft232h')->baud(115_200)->register()->device('ft232h');
$radio->write("AT+VER?\r\n");
$reply = $radio->readUntil("\r\n", timeout_ms: 1000);
```

The framework's README covers the transports, the event loop and `via()`. What this adapter adds:

### One engine per interface

An FTDI interface runs its MPSSE engine or its UART engine, never both. Once one is registered, opening the other on the same interface throws `GPIOLevelException::ftdiEngineBusy` before any USB traffic. `disconnect()` frees the interface for the other engine.

A UART on an FT2232H or FT4232H uses interface A, the same interface as `ft2232hl-a` / `ft4232hl-a`.

### Shared MPSSE context

I2C and SPI open the interface and hand it to the `usb` digital driver, so the spare lines are pins at once with no `connectTo()`. Connect I2C or SPI first: SPI refuses an interface the digital driver opened on its own.

### Digital pins

- FTDI pins have no interrupt, so edges are level changes between samples.
- A watched input is sampled on a loop timer every 10 ms; set another interval with `pollEvery($ms)`. Blocking `listen()` samples at the same interval.

### I2C

- The default clock is 400 kHz; set another with `connectTo('ft232h')->clockRate(MPSSEClockRate::ONE_MHZ)`.
- Framing matches Linux `I2C_RDWR`: `writeRead()` uses a repeated START, and `bulkWrite()` puts each chunk behind its own START with one STOP at the end.

### SPI

- Chip selects are pins: 0-3 are D4-D7, and 4-11 are C0-C7. D3, the engine's own chip select, stays high.
- Every chip select starts high when the bus registers. A pin used as a chip select is an output from then on.
- The bus clock comes from `speed($hz)` on the connection, or from `clockRate()` (default 400 kHz). `speed($hz)` on a slave gives it its own clock, and the engine switches clocks between slaves as needed.

### UART

- Reads are sized to 10 ms of line data, so a device that streams constantly cannot hold a caller or the loop.
- Sends are asynchronous USB transfers, and a send that wrote less than it was given throws.
- A device unplugged mid-read throws instead of returning silence.
- A watched port is sampled every 10 ms, because USB completions cannot wake the event loop.
- `dtr()` and `rts()` drive the modem lines. Both are released when the port opens.
- `close()` waits up to a second for a running send to finish.

### Offloading

libusb holds the device for this process, so `via()` jobs cannot move to another process:
- A job runs in a loop fiber against the real device.
- Its USB traffic takes turns with everything else on the interface.
- `via()` accepts no target name.

## Testing

```bash
composer install
vendor/bin/pest
```

The suite uses scripted serial and MPSSE links and recorded command streams, so it runs without a board.

`tests/Digital/Ft232hDigitalIOTest.php` drives all twelve pins of a real FT232H with nothing wired to them. When no board answers it skips itself.

## License

MIT. See [LICENSE](LICENSE).
