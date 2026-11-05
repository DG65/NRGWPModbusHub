🇬🇧 [English version](README.en.md)

# WPModbusHub — lokale Modbus-Anbindung für Wärmepumpen (Symcon)

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.9.1-blue)
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
| **WPModbusHub** / **WPModbusHubGateway** | **lokal, Modbus TCP** bzw. über Symcons **ModBus-Gateway** (auch RS485/RTU) | **NIBE, Stiebel Eltron, LG, Samsung, Waterkotte, IDM, Proxon** |

Eine Instanz = eine Wärmepumpe. Eine Hersteller-Auswahl im Formular schaltet auf das passende Registerprofil um. Eine Statuszeile im Formular zeigt live, ob die Wärmepumpe antwortet und welche Werte gerade ankommen.

Zwei Module in einer Bibliothek, gleiche Registerkarten, gleicher Vertrag, nur der Transport unterscheidet sich:

- **WPModbusHub** — eigene Modbus-TCP-Verbindung (IP-Adresse, Port, Unit-ID im Formular). Für Wärmepumpen mit Netzwerkanschluss oder einen RS485-zu-Ethernet-Adapter im Modus „Modbus TCP zu RTU“.
- **WPModbusHubGateway** — hängt als Kind an Symcons eingebautem **ModBus Gateway**. Damit geht auch **RS485/Modbus RTU direkt an einem seriellen Anschluss** (z. B. USB-RS485-Dongle am Symcon-Host): `Serial Port → ModBus Gateway → WPModbusHubGateway`. Slave-/Geräte-ID stellst du am ModBus Gateway ein, Baudrate/Parität am Serial Port. Knopf „Verbindung testen“ und `WPMBGW_ReadRaw($id, $function, $adresse, $anzahl)` helfen bei der Inbetriebnahme (nur lesend). **An einer echten Anlage bestätigt** (Proxon T300 über USB-RS485-Dongle, 19.09.2026), für die übrigen Hersteller über diesen Weg noch nicht.

## Status

Stand 0.9.0 (05.11.2026) — **bewusst nur lesend** (keine Steuerbefehle) und **bei den meisten der sieben Hersteller noch nicht an echter Hardware verifiziert** (bestätigt: Proxon T300 vollständig, IDM ALM vollständig, Waterkotte fünf Temperaturregister, Stiebel Eltron die Adressierung an einer WPF 15). Die Registerkarten stammen so weit wie möglich aus offizieller Herstellerdokumentation oder einer von der jeweiligen Smart-Home-Plattform offiziell übernommenen Referenzimplementierung:

- **NIBE** (S-Serie, z. B. S1155/S1255/S2125) — Registerkarte aus einer aktiv gepflegten, unabhängigen Referenzbibliothek für NIBE-Wärmepumpen.
- **Stiebel Eltron** (ISG-Gateway) — zwei Karten, beide direkt aus der offiziellen Stiebel-Eltron-Dokumentation „ISG Modbus": **Wärmepumpen mit WPMsystem/WPM 3/WPM 3i** und **Lüftungsintegralgeräte LWZ/LWA** (eigene Register, nicht austauschbar). Das ISG zählt 1-basiert: auf der Leitung gilt die Dokumentations-Adresse minus 1 (an einer WPF 15 bestätigt); die Werte selbst sind noch nicht gegen das Display geprüft. Neben den Temperaturen (bei der WPM auch die Quellentemperatur) liefert die Karte Wärmemenge und Stromverbrauch für Heizen und Warmwasser als Summe in kWh (kWh- und MWh-Teil addiert), die Betriebsart und bei der LWZ die Verdichterstarts. **Die Energiewerte sind die eigene Schätzung des ISG** (nur Verdichter und Heizstab, nicht Pumpen, Lüfter und Elektronik; in der Praxis 15 bis 20 % unter einem echten Zähler), keine Zählerablesung. Das Formular warnt, wenn die Reglerkennung des Geräts nicht zur gewählten Karte passt.
- **LG Therma V** — Registerkarte aus einer community-gepflegten Home-Assistant-Konfiguration, keine offizielle LG-Quelle gefunden.
- **Samsung EHS** (über das offizielle Zubehör-Modul MIM-B19N) — Registerkarte aus einer Community-Sammlung, die sich auf Samsungs offizielle MIM-B19N-Installationsanleitung beruft. Gilt **nicht** für die RS485/NASA-Route ohne dieses Modul (dafür wäre ein anderes, eigenes Modul nötig).
- **Waterkotte** (EcoTouch-Regler, eingebaute Modbus/TCP-Schnittstelle) — Registerkarte direkt aus Waterkottes eigenem PDF „Software Technische Information — Modbus/TCP".
- **IDM Energiesysteme** (Navigatorregelung 2.0, z. B. ALM-Serie) — Registerkarte direkt aus IDMs eigenem PDF „Modbus TCP Navigatorregelung 2.0", an einer laufenden ALM vollständig bestätigt (Warmwasser-Ist ist der Speicherfühler oben, zusätzlich Warmwasser unten). Einziger Hersteller dieser Liste mit 32-Bit-Fließkommawerten statt Ganzzahl×Faktor.
- **Proxon T300** (Zimmermann Lüftungs- und Wärmesysteme, Trinkwasser-Wärmepumpe) — vier Register aus Zimmermanns Kunden-Registerliste (Warmwasser Ist/Soll, Behälterfühler unten/mitte), alle an einer echten Anlage gegen das Display geprüft. Spricht nativ Modbus RTU über einen seriellen Anschluss, nicht TCP — braucht ein RS485-zu-Ethernet-Gateway im „Modbus TCP zu RTU"-Modus davor. Die FWT-Lüftungszentrale (Zu-/Abluft, kein Vorlauf/Rücklauf) ist bewusst nicht enthalten.

**Ausgelesen werden Temperaturen** (Außentemperatur, Vorlauf, teils Rücklauf/Warmwasser/Pufferspeicher/Heizzone/Quelle), bei Stiebel Eltron zusätzlich Energiesummen, Betriebsart und Verdichterstarts. Eine Momentanleistung gibt es per Modbus bei Stiebel nicht. Im Vertrag mit EMS und Dashboard bleiben `PowerID` und `EnergyID` vorerst 0.

Wer eine der sieben Wärmepumpen hat und beim ersten echten Test helfen möchte: sehr willkommen, siehe Formular-Hinweis.

## Sprachen

Das Modul ist zweisprachig (Deutsch/Englisch): Formular, Statusmeldungen, Hinweise und die Namen neuer Variablen folgen der Symcon-Sprache. Die Quelltexte sind englisch, die deutsche Fassung steht in `libs/locale.json` (`php .tools/locale-bauen.php` kopiert sie in beide Modulordner).

## Verbund

Teil des **NRG-Stack** — dem Energie-Modulverbund von DG65.

## Lizenz

PolyForm Noncommercial 1.0.0 — siehe [LICENSE](LICENSE). Privat frei, gewerblich lizenzpflichtig.
