# AGENTS.md — microscrap/scrapyard-usb

**Always read `.okf/index.md` first** before changing this package. Open only the concepts needed for the task; prefer `status: stable` when present. When you learn a durable package fact, update `.okf/` and append `.okf/log.md`.

## Role

The FTDI adapter for `scrapyard-io/framework` 0.9: the `usb` driver on the DigitalIO, I2C, SPI and UART managers, over `ext-ftdi` and the `microscrap/{ftdi,mpsse}` bindings. Depends on the `gpio/*` splits, never on the whole framework.

## Rules

* One FTDI interface runs one engine: every MPSSE or UART open goes through `FtdiBridge`.
* Once a context has a pump, every USB exchange on it goes through the pump (`RunsOnTheUsbPump::transact()` / `holdTheWire()`); nothing talks to that context around it.
* Offloaded jobs stay in the process (libusb holds the device): loop fibers, never a named work target.
* Test suites stay hardware-free: scripted links and recorded MPSSE streams. A test that needs a board skips itself when none answers.
* Prefer `is_null($var)` over `$var === null`.

## Quick OKF map

| Need | Concept |
|------|---------|
| Identity, provider wiring, device names | `.okf/overview.md` |
| MPSSE vs UART on one interface | `.okf/ftdi-bridge.md` |
| Shared context, pump, I2C, SPI | `.okf/mpsse.md` |
| Pins and sampled edges | `.okf/digital.md` |
| FTDI serial | `.okf/uart.md` |
| Test stand-ins, CI | `.okf/testing.md` |
