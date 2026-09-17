# WPModbusHub — Übergabe-Kontext für die neue Sitzung

Angelegt am 17.09.2026 auf Dietmars Auftrag ("Hop oder Top?" → "Top." → "Ja fang an, mit
den vorgeschlagenen Maschinen und der angefragten Samsung WP", Name "WPModbusHub" von
Dietmar bestätigt). Primärquelle für alle Verbund-Konventionen ist die lokale SUITE.md
(`/Users/dietmar/Nextcloud/Claude/SUITE.md`) — bei Zweifeln dort zuerst grep'en.

## Rolle im NRG-Stack

Dritter Baustein der Wärmepumpen-Vertikale, neben:

- **WPHub** (`DG65/NRGWPHub`) — Herstellerclouds (Panasonic Comfort Cloud, Vaillant myVAILLANT)
- **HeishaMon** (`DG65/NRGHeishaMon`) — lokal per MQTT, nur Panasonic mit HeishaMon-Platine
- **WPModbusHub** (dieses Repo) — lokal per Modbus TCP, mehrere Hersteller

Alle drei liefern denselben Vertrag (`*_GetFunctions()`, `Type=>'heatpump'`, contractVersion
1.15, identische Feldnamen) — EMS/Dashboard behandeln sie identisch, siehe WPHubs
`GetFunctions()` als ausführlichstes Vorbild.

## Architektur (bewusst anders als MeterHub)

MeterHub hat 13 Treiberklassen mit individueller Dekodierlogik (Float32/Double64/
Schreibkanäle). Alle bisherigen Wärmepumpen-Register sind dagegen einheitlich
vorzeichenbehaftete 16-Bit-Werte mit Faktor 10 — deshalb ein **datengetriebenes**
Registerprofil (`WPModbusHub::DRIVERS`-Konstante in `module.php`) statt einer Klasse je
Hersteller. `readRegisters()` liest jedes Feld einzeln und einheitlich. Sollte ein
künftiger Hersteller eine abweichende Kodierung brauchen (Float32, mehrere Register je
Wert, Schreibzugriffe), ist ein Interface nach MeterHub-Vorbild (`WPMBHUB_MeterDriverInterface`
→ hier `WPMBHUB_HeatpumpDriverInterface`) der vorgesehene Erweiterungspunkt — bislang nicht
nötig, bewusst nicht vorgebaut ("keine Abstraktion vor dem zweiten echten Bedarfsfall").

`WPMBHUB_ModbusTcpClient` (`WPModbusHub/libs/ModbusTcpClient.php`) ist 1:1 aus MeterHub
(`MHUB_ModbusTcpClient`, DG65/NRGMeterHub) portiert — bewährter Kern (eine TCP-Verbindung je
Lesezyklus, siehe MeterHub-CLAUDE.md "Modbus: eine Verbindung je Zyklus"), nur der
Präfix hat sich geändert (globale Klassennamen brauchen einen Modul-Präfix, Verbund-
Konvention 25.07.2026 — sonst `Cannot redeclare class`, sobald ein Konsument mehrere
Module gleichzeitig lädt).

## Registerkarten — Herkunft und Vertrauensstufe (wichtig für jede Erweiterung)

**Keine der vier Registerkarten ist an echter Hardware verifiziert** (Stand 17.09.2026,
kein Testkonto/-gerät vorhanden). Muster "Registerkarten: erst messen, dann glauben"
(MeterHub-CLAUDE.md) — trotzdem so weit wie möglich aus verlässlicher Quelle statt aus
einer reinen Forenzusammenfassung:

| Hersteller | Quelle | Vertrauensstufe |
|---|---|---|
| NIBE | Aktiv gepflegte, unabhängige Referenzbibliothek für NIBE-Wärmepumpen | hoch |
| Stiebel Eltron | Direkt aus dem offiziellen Stiebel-Eltron-PDF „ISG Modbus"-Bedienungsanleitung | hoch |
| LG Therma V | Community-gepflegte Modbus-Konfiguration, keine offizielle LG-Quelle gefunden | mittel |
| Samsung EHS | Community-Sammlung, beruft sich auf Samsungs offizielle MIM-B19N-Anleitung (DB68-07538A) | mittel |

Bewusst **nicht** übernommen: Register, deren Ist/Soll-Richtung in der Quelle selbst
unklar/auskommentiert war (z. B. ein Samsung-Warmwasser-Register) — lieber weniger Felder
als ein falsch beschriftetes.

Vor dem ersten echten Live-Test eines Herstellers: `confidence`-Text in `DRIVERS` nach
Bestätigung/Korrektur aktualisieren, dieselbe Registerkarte in `.tools/test-module.php`
(Block 2) gegenprüfen.

## Was bewusst NICHT Teil von v1 ist

- **Keine Steuerbefehle** (nur lesend) — analog zu WPHubs Vaillant-Anbindung beim Start.
- **Keine Leistungs-/Energiezähler** — die bisherigen Registerkarten decken nur
  Temperaturen ab. `PowerID`/`EnergyID` bleiben bewusst 0 im Vertrag.
- **Mitsubishi Ecodan, Daikin, Bosch/Buderus, Viessmann** — beim Research (16./17.09.2026)
  geprüft, aber keine ausreichend konkrete/verlässliche Registerkarte gefunden bzw. Zeit
  gefehlt, sie zu verifizieren. Bewusst nicht mit geratenen Adressen aufgenommen. Nächste
  Kandidaten, sobald sich dafür Zeit/ein Tester findet.
- **Kein Forum-Hinweis-Panel** — noch kein Thread (Muster WPHub: folgt, sobald es einen
  gibt, nicht mit einer Phantasie-URL vorgezogen).
- **Kein News-Panel-Inhalt über die Erstversion hinaus** — analog zu MeterHubDiscoverys
  Regel "kein News-Panel ohne echten Inhalt".

## Branch-Modell

`ems-integration` als aktiver Entwicklungsbranch (verbundweit identischer Name), analog zu
allen anderen NRG-Stack-Modulen. Noch kein `beta`/`main`.

## Verbund-Manifest SUITE.md

Lokal unter `/Users/dietmar/Nextcloud/Claude/SUITE.md`, kein GitHub-Remote (Dietmars
Entscheidung 31.08.2026, siehe andere Modul-CLAUDE.md-Dateien für die volle Begründung).
