---
type: Concept
title: MPSSE
description: One MPSSE context per interface shared by pins, I2C and SPI; the USB pump that takes turns and carries recorded transactions asynchronously; via() as a loop fiber; I2C framing and SPI chip selects as pins.
tags: [mpsse, pump, i2c, spi, offload, fibers]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: pump
    resource: src/Mpsse/MpssePump.php
    title: MpssePump
  - id: runs
    resource: src/Mpsse/RunsOnTheUsbPump.php
    title: RunsOnTheUsbPump
  - id: link
    resource: src/Mpsse/FtdiLink.php
    title: FtdiLink
  - id: i2c-driver
    resource: src/I2C/MpsseI2CConnectionDriver.php
    title: MpsseI2CConnectionDriver
  - id: i2c-transport
    resource: src/I2C/MpsseI2CTransport.php
    title: MpsseI2CTransport
  - id: spi-driver
    resource: src/SPI/MpsseSPIConnectionDriver.php
    title: MpsseSPIConnectionDriver
  - id: spi-transport
    resource: src/SPI/MpsseSPITransport.php
    title: MpsseSPITransport
---

# Shared context

`mpsse_open(vid FTDI, pid, mode, freq, endianness, iface)` per interface. The I2C or SPI driver opens it (mode I2C / SPI0-3) and registers it on the `usb` DigitalIO driver too; DigitalIO alone opens it in GPIO mode. Disconnect order: DigitalIO pins first, then the opener closes the context (`MpssePump::forget` → `mpsse_close`). DigitalIO closes only a context it opened itself (GPIO mode).

SPI refuses a device DigitalIO already connected on its own (`SPIException::bridgeInUse`): the GPIO-mode context cannot be switched to SPI underneath. Connect SPI or I2C first; pins come with it.

# Without a pump

No job was ever offloaded on the context → every call runs live, blocking, exactly as the bindings do. `MpssePump::existing($context)` null is that path.

# The pump

`MpssePump::for($context, $loop)` — made on the first `via()` dispatch, one per context (WeakMap). From then on every USB exchange on the context takes a turn:

- `exclusive($body)`: FIFO turns, one holder. Waits with `Loop::until()` (borrows the loop on the main stack, suspends in a fiber). The holder calling again runs at once (nested). A main-stack loop callback needing the wire while a turn lower on the same stack waits rides that turn instead of deadlocking.
- `exchange($recording)`: inside a turn only. `MpsseLink::submit` then polls on a 1 ms `every()` timer until the reply is in or the link gives up.
- The timer lives while any turn is held or waiting, not just while a transfer flies: a granted fiber resumes on a loop turn, and `until()` with no timer left cancels waiting fibers.
- `quiesce()` waits for idle; `forget()` quiesces then drops.

# FtdiLink

Asynchronous libftdi: read submitted before write so the reply has somewhere to land; `ftdi_handle_events_timeout(…, 0)` + `ftdi_transfer_completed` on each poll. Deadline = 1 s + 2 × bits clocked (commands + replies) at the context clock. Past it: cancel both transfers, purge RX so a late reply cannot land in the next transaction, settle false.

# RunsOnTheUsbPump

Trait on every MPSSE transport. `transact($context, $live, $decode)`:

- no pump → `$live()`.
- main stack, pump, no open recording → `$live()` inside a turn.
- fiber → turn, then `MPSSE::record()` the `$live` call instead of sending; the pump carries it; `$decode([acks ok, data])` builds the result (null reply = lost).
- recording already open (this fiber's own `select()`) → `$live` joins it, everything so far goes as one segment.

`holdTheWire($context, $run)`: one turn for a run of calls (SPI `select()`); in a fiber each inner call cuts its own segment, the tail (chip select up) is sent in `finally`, even when `$run` throws. `acknowledgedSoFar()`: before a repeated START, a pumped transaction sends what it has and answers with real ACKs, so it stops on the wire where the blocking call would.

# via()

I2C and SPI drivers override `offload()`: a named target → `offloadTargetUnsupported`; null → queue per bridge (`queueKey` = device), `dispatch` creates the pump and runs `$job->run($slave)` in `$loop->async()`. One engine, one queue per bridge.

# I2C

Default clock 400 kHz (`clockRate()`), MSB. Framing by hand: START, address byte + ACK, data bytes each ACKed, STOP. `clockIn` sends ACKs for all but the last byte, NACK on the last. `writeRead` = write phase, repeated START, read phase. `bulkWrite` = each chunk behind a (repeated) START, one STOP at the end — same framing as Linux `I2C_RDWR`. A NACK → `read`/`writeRead`/`bulkWrite` false, `write` -1, `probe` false.

# SPI

Mode 0-3 → `MPSSEMode::SPI0-3`. Clock: `speed(Hz)` wins over `clockRate(MPSSEClockRate)`; default 400 kHz. Chip select is a DigitalIO pin: 0-3 = D4-D7, 4-11 = C0-C7; anything else → `invalidChipSelectPin`. The engine's own CS (D3) is disabled and parked high; on `register()` all twelve CS pins go high so no chip sits selected. `device()` claims the pin as an output — DigitalIO can no longer take it as an input.

Per call: apply clock if it changed (`MpsseClock` shared by slaves on the bridge; per-slave `speed()`), CS low, START, data, STOP, CS high — one command stream. `select()` holds CS across calls in one pump turn. A lost exchange marks the clock unknown and, outside `select()`, raises CS again with one more exchange.
