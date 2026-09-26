---
type: Concept
title: FTDI bridge
description: One FTDI interface runs the MPSSE engine or the UART engine, never both; FtdiBridge tracks which per process and refuses the other before USB is touched.
tags: [ftdi, mpsse, uart, bridge, exclusivity]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: bridge
    resource: src/FtdiBridge.php
    title: FtdiBridge
  - id: mode
    resource: src/BridgeMode.php
    title: BridgeMode
  - id: uart-factory
    resource: src/UART/FtdiUARTConnectionFactory.php
    title: FtdiUARTConnectionFactory
---

# Why

Opening one engine on an interface resets the other under whoever uses it. `FtdiBridge` is a static per-process map: MPSSE device name → `BridgeMode::MPSSE|UART`. Another process holding the interface is libusb's to refuse at open.

# API

- `ensureFree($device, $mode)`: throws `GPIOLevelException::ftdiEngineBusy($device, held, wanted)` when the other engine holds it.
- `claim($device, $mode)`: ensureFree + record.
- `release($device, $mode)`: forget only when `$mode` is the holder.
- `holder($device)`: current mode or null.

# Where it is called

| Moment | MPSSE | UART |
|---|---|---|
| `connectTo()` | `ensureFree` in each MPSSE driver's `newConnection` | `ensureFree(bridge(product))` in `newConnection` |
| `register()` | `MpsseDigitalIOConnectionDriver::register` claims — I2C and SPI register through it | `FtdiUARTConnectionDriver::register` claims |
| give back | `MpsseDigitalIOConnectionDriver::disconnect` releases — I2C/SPI disconnect through it | `closeConnection` and the transport's `release()` |

Refusal happens before any USB call.

# Name mapping

`FtdiUARTConnectionFactory::bridge(FtdiProductId)`: FT232H → `ft232h`, FT2232H → `ft2232hl-a`, FT4232H → `ft4232hl-a`, others → lowercased case name. libftdi opens interface A, so a UART on a dual/quad chip shares channel A with MPSSE; a chip with no MPSSE engine never collides.
