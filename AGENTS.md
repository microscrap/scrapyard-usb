# AGENTS.md — microscrap/spi

**Always read `.okf/index.md` first** before changing this package. Open only the concepts needed for the task; prefer `status: stable` when present. When you learn a durable package fact, update `.okf/` and append `.okf/log.md`.

## Role

Bindings-only Composer package over **ext-posi** + **microscrap/posix** for Linux `spidev`. Global helpers, enums, and data objects. No ServiceProvider, no Chassis/Core coupling.

## Rules

* Helpers call `Microscrap\Bindings\SPI\Device` only; `Device` uses posix helpers (`posix_open`, `posix_read`, `posix_write`, `posix_close`, `ioctl`, `posi_mem_*`) — do not invent parallel APIs.
* Keep 1:1 coverage with helpers already in `src/Helpers/spi-device.php`; document drift in README / ecosystem docs.
* Enums in `src/Enums/*` are int-backed with **FULLY UPPERCASE** cases.
* Prefer `is_null($var)` over `$var === null`.
* No class-level constants; no Fabricate remaps in this package.

## Quick OKF map

| Need | Concept |
|------|---------|
| Identity / scope | `.okf/orientation/package.md` |
| Docs site | `.okf/orientation/ecosystem-docs.md` |
| Call stack | `.okf/architecture/helpers-device-posix.md` |
| Enums | `.okf/conventions/enums-spidev-flags.md` |
| Peer stack | `.okf/orientation/pairing-protocol-peers.md` |
| Transfer paths | `.okf/traps/spi-transfer-native-vs-posi-mem.md` |
| Half vs full duplex | `.okf/traps/half-duplex-vs-full-duplex.md` |
