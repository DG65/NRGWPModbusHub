🇩🇪 [Deutsche Fassung](README.md)

# WPModbusHub — local Modbus connection for heat pumps (Symcon)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Module Version](https://img.shields.io/badge/Module_Version-0.9.1-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGWPModbusHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGWPModbusHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

## Overview

WPModbusHub reads a heat pump directly via **Modbus TCP** on the local network — no internet and no manufacturer account needed. Third building block of the heat pump line in the NRG-Stack, next to [WPHub](https://github.com/DG65/NRGWPHub) (manufacturer clouds: Panasonic, Vaillant) and [HeishaMon](https://github.com/DG65/NRGHeishaMon) (local, Panasonic only, with the HeishaMon board):

| Module | Route | Manufacturers |
|---|---|---|
| WPHub | cloud (internet needed) | Panasonic, Vaillant, more planned |
| HeishaMon | local, MQTT | Panasonic only (with the HeishaMon board) |
| **WPModbusHub** / **WPModbusHubGateway** | **local, Modbus TCP** or via Symcon's **ModBus gateway** (also RS485/RTU) | **NIBE, Stiebel Eltron, LG, Samsung, Waterkotte, IDM, Proxon** |

One instance = one heat pump. Choosing the manufacturer in the form switches to the matching register map. A status line in the form shows live whether the heat pump answers and which values are arriving.

Two modules in one library, same register maps, same contract, only the transport differs:

- **WPModbusHub** — its own Modbus TCP connection (IP address, port, unit ID in the form). For heat pumps with a network connection or an RS485-to-Ethernet adapter in "Modbus TCP to RTU" mode.
- **WPModbusHubGateway** — hangs as a child below Symcon's built-in **ModBus Gateway**. This also makes **RS485/Modbus RTU directly on a serial port** possible (e.g. a USB-RS485 dongle on the Symcon host): `Serial Port → ModBus Gateway → WPModbusHubGateway`. You set the slave/device ID at the ModBus Gateway, baud rate/parity at the Serial Port. The button "Test connection" and `WPMBGW_ReadRaw($id, $function, $address, $count)` help with commissioning (read-only). **Confirmed on a real system** (Proxon T300 via USB-RS485 dongle, 19 Sep 2026), not yet for the other manufacturers via this route.

## Status

As of 0.9.0 (5 Nov 2026) — **read-only by design** (no control commands) and **not yet verified on real hardware for most of the seven manufacturers** (confirmed: Proxon T300 completely, IDM ALM completely, five Waterkotte temperature registers, the Stiebel Eltron addressing on a WPF 15). The register maps come, as far as possible, from official manufacturer documentation or from a reference implementation officially adopted by the respective smart-home platform:

- **NIBE** (S series, e.g. S1155/S1255/S2125) — register map from an actively maintained, independent reference library for NIBE heat pumps.
- **Stiebel Eltron** (ISG gateway) — two cards, both taken directly from the official Stiebel Eltron "ISG Modbus" documentation: **heat pumps with WPMsystem/WPM 3/WPM 3i** and **integral ventilation units LWZ/LWA** (their own registers, not interchangeable). The ISG counts 1-based: on the wire the documentation address minus 1 applies (confirmed on a WPF 15); the values themselves are not yet checked against the display. Besides the temperatures (on the WPM also the source temperature) the card provides heat quantity and electrical energy for heating and hot water as totals in kWh (kWh and MWh part added up), the operating mode and, on the LWZ, compressor starts. **The energy values are the ISG's own estimate** (compressor and heating element only, not pumps, fans or electronics; in practice 15 to 20 % below a real meter), not a meter reading. The form warns if the controller code of the device does not fit the selected card.
- **LG Therma V** — register map from a community-maintained Home Assistant configuration, no official LG source found.
- **Samsung EHS** (via the official accessory module MIM-B19N) — register map from a community collection that refers to Samsung's official MIM-B19N installation manual. Does **not** apply to the RS485/NASA route without that module (that would need another, separate module).
- **Waterkotte** (EcoTouch controller, built-in Modbus/TCP interface) — register map taken directly from Waterkotte's own PDF "Software Technische Information — Modbus/TCP".
- **IDM Energiesysteme** (Navigator control 2.0, e.g. ALM series) — register map taken directly from IDM's own PDF "Modbus TCP Navigatorregelung 2.0", fully confirmed on a running ALM (hot water actual is the tank sensor at the top, plus hot water bottom). The only manufacturer in this list with 32-bit floating-point values instead of integer × factor.
- **Proxon T300** (Zimmermann ventilation and heating systems, domestic hot water heat pump) — four registers from Zimmermann's customer register list (hot water actual/target, tank sensors bottom/middle), all checked against the display on a real system. Natively speaks Modbus RTU on a serial port, not TCP — needs an RS485-to-Ethernet gateway in "Modbus TCP to RTU" mode in front of it. The FWT ventilation unit (supply/exhaust air, no flow/return) is deliberately not included.

**Temperatures are read** (outdoor, flow, partly return/hot water/buffer tank/heating circuit/source); for Stiebel Eltron also energy totals, operating mode and compressor starts. Stiebel has no instantaneous power via Modbus. In the contract with EMS and Dashboard `PowerID` and `EnergyID` stay 0 for now.

If you own one of the seven heat pumps and want to help with the first real test: very welcome, see the note in the form.

## Languages

The module is bilingual (German/English): form, status messages, notes and the names of new variables follow the Symcon language. The source texts are English, the German version lives in `libs/locale.json` (`php .tools/locale-bauen.php` copies it into both module folders).

## Part of the NRG-Stack

Part of the **NRG-Stack** — DG65's energy module family.

## License

PolyForm Noncommercial 1.0.0 — see [LICENSE](LICENSE). Free for private use, commercial use requires a license.
