# Directory update log

## 2026-10-04
* **Revision**: 0.10.0 — [overview](/overview.md), [index](/index.md): `gpio/*`, `microscrap/mpsse` and `ext-ftdi` ^0.10; `microscrap/ftdi` is gone (ext-ftdi 0.10 absorbed it, the VID/PID enums are `Ftdi\FtdiVendorId` / `Ftdi\FtdiProductId`). ext-ftdi 0.10 returns null from `ftdi_new()` and the submit functions on failure (no int `handle`), and false from `ftdi_read_data()`; the serial link still reads a lost device as "". [mpsse](/mpsse.md): a named pool is refused with `offloadPoolUnsupported`.

## 2026-09-25
* **Creation**: bundle for 0.9 — [overview](/overview.md), [ftdi-bridge](/ftdi-bridge.md), [mpsse](/mpsse.md), [digital](/digital.md), [uart](/uart.md), [testing](/testing.md).
