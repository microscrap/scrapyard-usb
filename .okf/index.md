---
okf_version: "0.2"
---

# microscrap/scrapyard-usb — knowledge bundle

FTDI adapter for `scrapyard-io/framework` 0.10. Registers the `usb` driver on the DigitalIO, I2C, SPI and UART managers. MPSSE (via `microscrap/mpsse`) carries pins, I2C and SPI on one shared context per interface; the FTDI UART engine (ext-ftdi's own functions) carries serial. Everything through `ext-ftdi` / libftdi1 / libusb.

Read this index first, then only the concepts the task needs. Every concept is `status: draft` until a human verifies it.

# Concepts

* [overview.md](/overview.md) - what ships, stack position, provider wiring, device names, what rides the loop
* [ftdi-bridge.md](/ftdi-bridge.md) - one engine per FTDI interface: BridgeMode, claim/release, ftdiEngineBusy
* [mpsse.md](/mpsse.md) - shared context, the USB pump, recorded transactions, via() in a loop fiber, I2C and SPI specifics
* [digital.md](/digital.md) - GPIOL/GPIOH pins on the shared context, sampled edges, pollEvery()
* [uart.md](/uart.md) - SerialLink, sized reads, async sends, lost-device detection, 10 ms loop sampling, DTR/RTS
* [testing.md](/testing.md) - scripted links, recorded MPSSE streams, the self-skipping FT232H bench, CI

# Log

* [log.md](/log.md)
