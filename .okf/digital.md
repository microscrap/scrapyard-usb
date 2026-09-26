---
type: Concept
title: Digital IO
description: FTDI pins on the shared MPSSE context, reads and writes through the pump when one exists, edges as level changes between samples every pollEvery() ms.
tags: [digital, mpsse, sampling, edges]
status: draft
generated: { by: claude-opus-5-5/claude-code, at: "2026-09-25T23:00:00Z" }
sources:
  - id: driver
    resource: src/Digital/MpsseDigitalIOConnectionDriver.php
    title: MpsseDigitalIOConnectionDriver
  - id: factory
    resource: src/Digital/MpsseDigitalIOConnectionFactory.php
    title: MpsseDigitalIOConnectionFactory
  - id: input
    resource: src/Digital/MpsseDigitalInputTransport.php
    title: MpsseDigitalInputTransport
  - id: output
    resource: src/Digital/MpsseDigitalOutputTransport.php
    title: MpsseDigitalOutputTransport
---

# Connection

Standalone: `connectTo('ft232h')->register()` opens a GPIO-mode context (default 1 MHz, MSB; `clockRate()`, `endianness()`). Usually unnecessary: an I2C or SPI connection registers its context here, and `output('ft232h', $pin)` works straight away.

# Pins

Cached per `device:pin`; the other direction on the same pin throws. Creating a pin sets its direction with `mpsse_configure_pin_direction` — through the pump's turn when the context has one.

- **Output:** `write()` → `mpsse_pin_high/low`, then reads the pins back; returns whether the line reports the level written (false on a failed write).
- **Reads:** `mpsse_read_pins` → 16-bit word → `mpsse_pin_state`. No answer → `DigitalIOException::pinsReadFailed`.

Both go through `transact()`, so inside a job fiber they are recorded and carried by the pump, and inside an SPI `select()` they join the selection's recording.

# Edges

FTDI has no GPIO interrupt. `drainEdges()` takes one sample; an edge when it differs from the previous sample (`DigitalEdgeEvent`, `hrtime` timestamp, own seqno). `read()` never moves that baseline, so a read between samples cannot hide an edge.

- `pollEvery($ms)` (default 10, min 1): sample interval on the loop and for blocking `listen()`. Retimes a pin already on the loop.
- `samplingInterval()` = `poll_ms / 1000`; `edgeStreams()` empty — a timer, not an fd, drives it.
- Blocking `awaitEdges()` sleeps one interval (or the remaining timeout when shorter).

`close()` releases nothing — the context belongs to the connection.
