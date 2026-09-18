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
| Waterkotte | Direkt aus Waterkottes eigenem PDF „Software Technische Information -- Modbus/TCP" (Firmware 01.07.xx, 07.2017), von Dietmar besorgt | hoch |

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
- ~~Kein Forum-Hinweis-Panel~~ — erledigt 18.09.2026: Thread ist live
  (https://community.symcon.de/t/modul-nrg-stack-wpmodbushub-lokale-modbus-anbindung-fuer-waermepumpen-mehrerer-hersteller-nibe-stiebel-eltron-lg-samsung/144421),
  Panel `ForumHint()`/`AckForumHint()` verlinkt (0.1.2).
- ~~Kein News-Panel-Inhalt über die Erstversion hinaus~~ — erster echter Inhalt seit
  0.2.0 (Waterkotte-Ergänzung, siehe unten).

## Waterkotte-Ergänzung (18.09.2026)

Fünfter Hersteller, direkt aus Dietmars Auftrag heraus: Dietmar hat das offizielle
Waterkotte-PDF „Software Technische Information — Modbus/TCP" (Firmware 01.07.xx,
07.2017) sowie einen E-Mail-Austausch mit einem Waterkotte-Servicetechniker besorgt.
Damit ist Waterkotte die **einzige Registerkarte dieser Liste, die von Dietmar selbst
und nicht nur aus einer Online-Recherche stammt** — höchste erreichbare
Vertrauensstufe, siehe DRIVERS-Kommentar in `module.php`.

Übernommen (Basis-PDF, alles Holding-Register FC03, BMS-Analogadresse = Modbus/TCP-
Adresse 1:1, Faktor 10 wie alle anderen Hersteller): Außentemperatur (A1),
Rücklauf-/Vorlauftemperatur (A11/A12), Pufferspeichertemperatur (A16, **neues
generisches Feld** `Speichertemperatur` → `bufferTempID`, nicht Waterkotte-
spezifisch), Warmwasser Ist/Soll (A19/A37 — A37 statt der BMS-Schreibregister
A32/A38, da dieses Modul nicht schreibt) und Heizzone 1 Ist/Soll (A30/A31).

**Noch nicht eingearbeitet:** Der Techniker-E-Mail-Screenshot zeigte zusätzlich
Mischkreis-spezifische Register (`T_SP_norm`, `T_Os`, `T_SP_Os`, `T_SP_max`,
`C_T_Os`, `C_T_SP_Os`, `Flow_Limit` — vermutlich zweiter Heizkreis/Mischerkreis-
Sollwertlogik), die im Basis-PDF nicht enthalten sind. Diese Adressen lagen nur als
Screenshot vor und waren zum Zeitpunkt dieser Ergänzung nicht mehr im Kontext
verfügbar — bewusst nicht geraten übernommen. Bei Bedarf (zweiter Heizkreis/Mischer
als eigenes Feld) den Screenshot erneut vorlegen lassen und gegen das Basis-PDF
plausibilisieren, bevor Adressen ins Registerprofil wandern.

## Heizkurven-Recherche für Dashboard (18.09.2026)

Dashboard-Sitzung wollte einen einheitlichen `*_GetHeatingCurve`/`*_SetHeatingCurve`-Vertrag
fuer WPHub/WPModbusHub/SamsungEhs klaeren (Belege siehe Chat-Transkript). Ergebnis fuer
WPModbusHub: **NIBE** hat eine eigene 7-Punkt-Kurve (Reg. 39-45, R/W) UND ein einfaches
Steigung+Offset-Paar je Klimasystem 1-3 (Reg. 24/28 usw., R/W) -- beste Quellenlage dieser
Liste. **Stiebel Eltron** hat ein Steigungsregister (41504, Faktor 0.01, Holding R/W), aber
HK2-Aequivalent und Wertebereich nicht sauber belegt (PDFs nicht textextrahierbar).
**LG Therma V:** nichts gefunden, die aktiv gepflegte Community-Doku kennt keine
Kurvenregister. **Samsung (MIM-B19N Modbus):** unklar, vermutete Register 89-91 nicht
verlaesslich belegt.

**Dietmars Entscheidung (Dashboard, 18.09.2026):** WPMonitor-Heizkurven-Reiter v1 wird NUR
gegen HeishaMon gebaut. WPModbusHub bekommt **keinen Zeitdruck** -- Dashboard-Vertrag
erhaelt Kapazitaetsfelder (`curveModel`/`curveWritable`) fuer spaeteres Andocken ohne
UI-Umbau. Vor einem `SetHeatingCurve()`-Bau: Register-Schreibbarkeit erst an echter
NIBE/Stiebel-Eltron-Hardware verifizieren (KEINER der vier WPModbusHub-Hersteller ist Stand
heute an echter Hardware getestet, siehe Registerkarten-Tabelle oben) -- dann von uns aus bei
Dashboard melden.

## Branch-Modell

`ems-integration` bleibt der aktive Entwicklungsbranch. Seit 18.09.2026 existiert zusätzlich
`beta` (erster Store-Release-Branch). **Seit 18.09.2026 (Dietmars Entscheidung, verbundweit):
beide Branches laufen automatisch gleich** — jeder Push nach `ems-integration` geht im
selben Zug auch nach `beta`, kein manuelles Nachziehen mehr nötig. `main` existiert für
dieses Repo noch nicht.

## Verbund-Manifest SUITE.md

Lokal unter `/Users/dietmar/Nextcloud/Claude/SUITE.md`, kein GitHub-Remote (Dietmars
Entscheidung 31.08.2026, siehe andere Modul-CLAUDE.md-Dateien für die volle Begründung).
