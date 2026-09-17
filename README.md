# scrapyard-usb

The `usb` driver for [`scrapyard-io/framework`](https://github.com/scrapyard-io/framework): talk I2C, SPI, GPIO and serial through an FTDI USB board, such as the FT232H, from any computer.

`microscrap/scrapyard-usb` plugs into the framework's protocol managers, so `I2C::driver('usb')`, `SPI::driver('usb')`, `DigitalIO::driver('usb')` and `UART::driver('usb')` work through an FTDI chip. I2C, SPI and GPIO run over MPSSE, and UART uses the chip's serial mode. Chip drivers written against the framework's transports work unchanged on it, so a sensor can be developed on a laptop and deployed to a Raspberry Pi.

## Requirements

- macOS or Linux
- PHP 8.4 or newer with `ext-ftdi` loaded
- A Venusian application with `scrapyard-io/framework` 0.8
- An FTDI board. On Linux, your user needs permission to open it, usually through a udev rule.

Boards are named by their USB chip:

| Protocols | Names |
|---|---|
| I2C, SPI, digital | `ft232h`, `ft2232hl-a`, `ft2232hl-b`, `ft4232hl-a` to `ft4232hl-d` (the suffix picks the chip's interface) |
| UART | `ft232h`, `ft232r`, `ft230x`, `ft2232h`, `ft4232h`, `ft4232hp`, `ft4232ha`, or the USB product ID such as `0x6014` |

The driver opens the first attached board with that name's USB product ID.

## Installation

```bash
composer require microscrap/scrapyard-usb
```

The service provider is discovered automatically. It registers a `usb` driver on the I2C, SPI, DigitalIO and UART managers. PWM has no `usb` driver.

Name the driver in each call, or make it the default in `config/gpio.php`:

```php
'protocols' => [
    'i2c' => ['default' => 'usb'],
    'spi' => ['default' => 'usb'],
    'uart' => ['default' => 'usb'],
    'digital-in' => ['default' => 'usb'],
],
```

## Quick start

An accelerometer at `0x53` on an FT232H:

```php
use GeneralPurposeIO\I2C\I2C;

$accelerometer = I2C::driver('usb')
    ->connectTo('ft232h')
    ->register()
    ->device('ft232h', 0x53);

$accelerometer->probe();                    // true when it answers
$id = $accelerometer->writeRead([0x00], 1);  // [0xE5]
```

## One mode per board

MPSSE runs a board interface in one mode at a time: I2C, SPI or plain GPIO. Connect it once, with the protocol you need:

- **I2C or SPI.** `connectTo()` → `register()` opens the board in that mode, and also registers it with the `usb` DigitalIO driver. The GPIO lines the protocol doesn't use are then ready as pins, with no separate connection.
- **Only pins.** Connect the board through DigitalIO instead:

```php
use GeneralPurposeIO\Digital\DigitalIO;

$board = DigitalIO::driver('usb')->connectTo('ft232h')->register();
```

Connecting DigitalIO to a board that I2C or SPI already registered throws.

## I2C

```php
use GeneralPurposeIO\I2C\I2C;
use Microscrap\Bindings\MPSSE\Enums\MPSSEClockRate;

$bus = I2C::driver('usb')
    ->connectTo('ft232h')
    ->clockRate(MPSSEClockRate::FOUR_HUNDRED_KHZ)   // the default
    ->register();

$display = $bus->device('ft232h', 0x3C);
$accelerometer = $bus->device('ft232h', 0x53);
```

The bus lines are D0 (SCL) and D1 and D2 tied together (SDA).

- `probe()` sends the device's address and reports whether it acknowledged.
- `writeRead()` writes, then reads after a repeated start.
- `bulkWrite()` sends several writes in one transaction.
- `read()` returns `false`, and `write()` returns `-1`, when the device doesn't acknowledge.

`endianness()` is also available on the connection.

## SPI

```php
use GeneralPurposeIO\SPI\SPI;
use Microscrap\Bindings\MPSSE\Enums\MPSSEClockRate;

$panel = SPI::driver('usb')
    ->connectTo('ft232h')
    ->mode(0)
    ->clockRate(MPSSEClockRate::TEN_MHZ)
    ->register()
    ->device('ft232h', 0);
```

The bus lines are D0 (SCK), D1 (MOSI), D2 (MISO) and D3 (CS). Set the bus speed with `clockRate()`, from 100 kHz to 60 MHz, 400 kHz by default. The framework's `speed()` has no effect on this driver. `mode()` and `endianness()` work as usual.

D3 goes low for every transfer. The transport also drives the GPIO line numbered like the chip select low for the transfer and high after it: chip select 0 uses GPIOL0, chip select 1 uses GPIOL1. That line becomes an output, so don't use it for anything else.

`write()` and `read()` are half duplex. `transfer()` is full duplex.

## Digital pins

```php
use GeneralPurposeIO\Digital\DigitalIO;

$pins = DigitalIO::driver('usb');   // the board is already registered by I2C or SPI

$dc = $pins->output('ft232h', 1);
$reset = $pins->output('ft232h', 2);
$interrupt = $pins->input('ft232h', 3);

$reset->low();
usleep(10_000);
$reset->high();
```

| Pin number | Line | FT232H label |
|---|---|---|
| 0–3 | GPIOL0–GPIOL3 | D4–D7 |
| 4–11 | GPIOH0–GPIOH7 | C0–C7 |

The first `output()` or `input()` for a pin sets its direction, and later calls return the same transport. Asking for a pin in the other direction throws. `input()` accepts a bias and `active_low`, but MPSSE lines have neither, so both are ignored.

Inputs are sampled over USB:

- `read()` reads the pin now.
- `pollEdges()` compares the pin with its last reading and reports at most one edge. The first call only takes a reading.
- `listen($timeout_ms)` reads the pin every millisecond until it changes, and returns `null` on timeout. A negative timeout returns `null` straight away.

Edge timestamps are the host's `hrtime()` at the moment the change was seen.

## UART

```php
use GeneralPurposeIO\UART\UART;

$radar = UART::driver('usb')
    ->connectTo('ft232h')
    ->baud(256_000)
    ->register()
    ->device('ft232h');

$radar->write([0xFD, 0xFC, 0xFB, 0xFA]);
$bytes = $radar->pollBytes();   // whatever has arrived, or ''
```

Opening the board puts it in serial mode and sets the baud rate, data bits, parity, stop bits and flow control. It also sets the latency timer to 1 ms, so short replies arrive promptly, and discards anything already buffered.

`pollBytes()` reads what has arrived, spending at most about a millisecond. `flush()` discards buffered data. `path()` returns `usb:` followed by the libftdi handle.

A single-interface board like the FT232H can be in serial mode or MPSSE mode, not both. Use one per board.

## Closing

`close()` on an I2C, SPI or pin transport closes the board's MPSSE connection, which ends it for every transport on that board. Close a board's transports only when you're finished with all of them. `close()` on a UART transport closes that serial connection.

## The gpio dock

Input pins and serial ports never block when the framework's `gpio` dock resource polls them, so they can go straight onto it:

```php
use GeneralPurposeIO\Core\MagicAliases\GPIO;

GPIO::watch($interrupt, rising: true);   // DigitalEdgeOccurrence per edge
GPIO::receive($radar);                   // UARTBytesOccurrence per batch of bytes
```

Each tick samples a watched pin once, so a pulse shorter than the time between ticks can be missed.

## Errors

Failures throw the framework's protocol exceptions, such as `I2CException`, `SPIException`, `DigitalIOException` and `UARTException`. They all extend `GPIOLevelException`. Typical causes are:

- The device name isn't a supported board.
- The board isn't attached, or can't be opened. The message includes libftdi's or libmpsse's error text.
- A serial setting is refused.

## Testing

```bash
composer install
vendor/bin/pest
```

The suite needs no hardware.

## License

MIT. See [LICENSE](LICENSE).
