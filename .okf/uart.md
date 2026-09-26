---
type: Concept
title: UART
description: FTDI serial through a SerialLink over one libftdi context — line setup at open, reads sized to one sampling interval, asynchronous sends polled to completion, lost-device detection via modem status, 10 ms loop sampling, DTR/RTS.
tags: [uart, ftdi, libftdi, loop, modem-lines]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: driver
    resource: src/UART/FtdiUARTConnectionDriver.php
    title: FtdiUARTConnectionDriver
  - id: factory
    resource: src/UART/FtdiUARTConnectionFactory.php
    title: FtdiUARTConnectionFactory
  - id: transport
    resource: src/UART/FtdiUARTTransport.php
    title: FtdiUARTTransport
  - id: link
    resource: src/UART/SerialLink.php
    title: SerialLink
  - id: ftdi-link
    resource: src/UART/FtdiSerialLink.php
    title: FtdiSerialLink
  - id: port
    resource: src/UART/FtdiPort.php
    title: FtdiPort
---

# Opening

`connectTo('ft232h')` → product lookup ([overview.md](/overview.md)), bridge check ([ftdi-bridge.md](/ftdi-bridge.md)). `register()`:

1. `ftdi_new()` (allocates and initialises — no second `ftdi_init`), `ftdi_usb_open(vid, pid)`.
2. `ftdi_set_bitmode(0, 0)` async serial, baud, line properties (data bits, stop bits 0/2, parity).
3. Flow control: XON/XOFF through `ftdi_setflowctrl_xonxoff(0x11, 0x13)` (the plain 0x400 flag would stop and start on 0x00); RTS/CTS 0x100; none 0.
4. Latency timer 1 ms: an empty read returns after 1 ms, bounding how long a sample holds the loop.
5. DTR and RTS released, so a module wired to reset on DTR boots whatever the last run left.
6. Purge buffers.

Any step failing closes and frees the context and throws `couldNotConfigureFtdiDevice(device, operation, libftdi error)`. Handle = `FtdiPort(link, baud, bridge)`. `path()` = `usb:<device>`.

# SerialLink

Interface over the libftdi calls a port makes, so tests script the chip. `FtdiSerialLink`: `read` = `ftdi_read_data`; `status` = `ftdi_poll_modem_status` (negative once the device is gone); `send` = `ftdi_write_data_submit` (async, false when libusb refuses); `sent` polls completion without blocking; `awaitSent($ms)` loops `ftdi_handle_events_timeout(1 ms)`; `close` cancels a running send, then close/deinit/free.

# Framework hooks

| Hook | Implementation |
|---|---|
| `drainBytes()` | bytes an earlier wait read + `read(readSize())`; empty and `status() < 0` → `readFailed` (lost device vs silence) |
| `awaitBytes($ms)` | read into an early buffer until bytes, timeout, or a lost device; each empty read already waits one latency period, so it never spins |
| `roomNow()` | previous send finished; confirms it wrote everything |
| `awaitRoom($ms)` | `awaitSent($ms)` |
| `transmit()` | `send()`; refused → -1 |
| `purge()` | `ftdi_usb_purge_buffers` + drop early bytes |
| `intakeStreams()` | none — completions cannot wake select |
| `samplingInterval()` | 0.01 s |

`readSize()` = `max(64, baud / 1000)` — one 10 ms interval of line data at 10 bits a byte. libftdi keeps reading until it has what was asked or the line idles one latency period, so a large read against a streaming device would hold the caller; sizing to one interval bounds it.

A send that wrote fewer bytes than given → `usbWriteFailed(device, sent, expected)`. The framework chunks writes (256 bytes) and paces loop writes by chunk airtime.

# Modem lines

`dtr()` / `rts()` → `ftdi_setdtr` / `ftdi_setrts`; negative → `modemLineFailed(device, 'DTR'|'RTS')`.

# Release

`close()`: up to 1 s for a running send, confirm it (a failed last send throws), then — always — close the link and release the bridge.
