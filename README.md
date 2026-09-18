# WPModbusHub — lokale Modbus-Anbindung für Wärmepumpen (IP-Symcon)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.4.1-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGWPModbusHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGWPModbusHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

## Übersicht

WPModbusHub liest eine Wärmepumpe direkt per **Modbus TCP** im lokalen Netz aus — ganz ohne Internet und ohne Herstellerkonto. Dritter Baustein der Wärmepumpen-Vertikale im NRG-Stack, neben [WPHub](https://github.com/DG65/NRGWPHub) (Herstellerclouds: Panasonic, Vaillant) und [HeishaMon](https://github.com/DG65/NRGHeishaMon) (lokal, nur Panasonic mit HeishaMon-Platine):

| Modul | Weg | Hersteller |
|---|---|---|
| WPHub | Cloud (Internet nötig) | Panasonic, Vaillant, weitere geplant |
| HeishaMon | lokal, MQTT | nur Panasonic (mit HeishaMon-Platine) |
| **WPModbusHub** | **lokal, Modbus TCP** | **NIBE, Stiebel Eltron, LG, Samsung, Waterkotte, IDM, Proxon** |

Eine Instanz = eine Wärmepumpe. Eine Hersteller-Auswahl im Formular schaltet auf das passende Registerprofil um.

## Status

Stand 0.4.1 (18.09.2026) — **bewusst nur lesend** (keine Steuerbefehle) und **bei keinem der sieben Hersteller vollständig an echter Hardware verifiziert** (bei Waterkotte sind fünf Temperaturregister durch eine laufende Anlage gegengeprüft). Die Registerkarten stammen so weit wie möglich aus offizieller Herstellerdokumentation oder einer von der jeweiligen Smart-Home-Plattform offiziell übernommenen Referenzimplementierung:

- **NIBE** (S-Serie, z. B. S1155/S1255/S2125) — Registerkarte aus einer aktiv gepflegten, unabhängigen Referenzbibliothek für NIBE-Wärmepumpen.
- **Stiebel Eltron** (ISG-Gateway, WPMsystem/WPM3/WPM3i/LWZ) — Registerkarte direkt aus dem offiziellen Stiebel-Eltron-PDF „ISG Modbus"-Bedienungsanleitung.
- **LG Therma V** — Registerkarte aus einer community-gepflegten Home-Assistant-Konfiguration, keine offizielle LG-Quelle gefunden.
- **Samsung EHS** (über das offizielle Zubehör-Modul MIM-B19N) — Registerkarte aus einer Community-Sammlung, die sich auf Samsungs offizielle MIM-B19N-Installationsanleitung beruft. Gilt **nicht** für die RS485/NASA-Route ohne dieses Modul (dafür wäre ein anderes, eigenes Modul nötig).
- **Waterkotte** (EcoTouch-Regler, eingebaute Modbus/TCP-Schnittstelle) — Registerkarte direkt aus Waterkottes eigenem PDF „Software Technische Information — Modbus/TCP".
- **IDM Energiesysteme** (Navigatorregelung 2.0, z. B. ALM-Serie) — Registerkarte direkt aus IDMs eigenem PDF „Modbus TCP Navigatorregelung 2.0". Einziger Hersteller dieser Liste mit 32-Bit-Fließkommawerten statt Ganzzahl×Faktor.
- **Proxon T300** (Zimmermann Lüftungs- und Wärmesysteme, Trinkwasser-Wärmepumpe) — zwei Register aus Zimmermanns Kunden-Registerliste. Spricht nativ Modbus RTU über einen seriellen Anschluss, nicht TCP — braucht ein RS485-zu-Ethernet-Gateway im „Modbus TCP zu RTU"-Modus davor. Die FWT-Lüftungszentrale (Zu-/Abluft, kein Vorlauf/Rücklauf) ist bewusst nicht enthalten.

**Ausgelesen werden aktuell nur Temperaturen** (Außentemperatur, Vorlauf, teils Rücklauf/Warmwasser/Pufferspeicher/Heizzone) — noch keine Leistungs- oder Energiezähler.

Wer eine der sieben Wärmepumpen hat und beim ersten echten Test helfen möchte: sehr willkommen, siehe Formular-Hinweis.

## Verbund

Teil des **NRG-Stack** — dem Energie-Modulverbund von DG65.

## Lizenz

PolyForm Noncommercial 1.0.0 — siehe [LICENSE](LICENSE). Privat frei, gewerblich lizenzpflichtig.
