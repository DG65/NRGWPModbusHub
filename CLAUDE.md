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

**Stand 0.5.0: zwei Module in einer Bibliothek** -- `WPModbusHub` (eigener TCP-Socket) und
`WPModbusHubGateway` (Kind des Symcon-ModBus-Gateways, auch RS485/RTU), gemeinsame
Registerkarten und Logik in `libs/` (siehe Abschnitt "Proxon", vierter Nachtrag).

MeterHub hat 13 Treiberklassen mit individueller Dekodierlogik (Float32/Double64/
Schreibkanäle). Alle bisherigen Wärmepumpen-Register sind dagegen einheitlich
vorzeichenbehaftete 16-Bit-Werte mit Faktor 10 — deshalb ein **datengetriebenes**
Registerprofil (`WPModbusHub::DRIVERS`-Konstante in `module.php`) statt einer Klasse je
Hersteller. `readRegisters()` liest jedes Feld einzeln und einheitlich. Sollte ein
künftiger Hersteller eine abweichende Kodierung brauchen (Float32, mehrere Register je
Wert, Schreibzugriffe), ist ein Interface nach MeterHub-Vorbild (`WPMBHUB_MeterDriverInterface`
→ hier `WPMBHUB_HeatpumpDriverInterface`) der vorgesehene Erweiterungspunkt — bislang nicht
nötig, bewusst nicht vorgebaut ("keine Abstraktion vor dem zweiten echten Bedarfsfall").

`WPMBHUB_ModbusTcpClient` (`libs/ModbusTcpClient.php`) ist 1:1 aus MeterHub
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
| IDM Energiesysteme | Direkt aus IDMs eigenem PDF „Modbus TCP Navigatorregelung 2.0" (Dok. 812170_Rev.10, 20.04.2022), selbst gelesen inkl. Datentypen-Kapitel, gilt fuer alle IDM-WP mit Navigator-2.0-Regelung (inkl. ALM) | hoch |
| Proxon T300 | Direkt aus Zimmermanns eigener Kunden-Excel, von Nutzer "Ghostraider" per PN erhalten (18.09.2026) -- NUR die zwei Register mit eindeutiger Skalierung uebernommen | hoch (fuer die zwei Felder), NICHT der Rest der Tabelle |

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
- **Proxon** (Zimmermann Lüftungs- und Wärmesysteme GmbH & Co. KG, eigenständiger
  Hersteller, weit verbreitet in deutschen Fertighäusern über Einbaupartner wie
  WeberHaus/Fingerhaus — KEIN Rebrand eines bereits unterstuetzten Herstellers,
  18.09.2026 recherchiert nach Forumsanfrage "Ghostraider"). Modbus sitzt an der
  FWT-Lüftungs-/Wärmepumpenzentrale (Modelle 1.x/2.0), dazu separat eine
  Trinkwasser-Wärmepumpe T300. Zimmermanns offizielle Modbus-Doku ("Kurzbeschreibung
  GLT-Schnittstelle FWT2.0" + Registerliste als Excel) ist NICHT oeffentlich online,
  nur auf Kundenanfrage -- bewusst NICHT aus einer informell im Netz kursierenden
  Kopie uebernommen (unklare Weitergabeberechtigung).
  **Update 18.09.2026:** Ghostraider IST Proxon-Kunde und hat die offizielle Excel
  bereits selbst (legitim von Zimmermann) -- deutlich ergiebiger als erwartet, ~250
  Variablen (Kompressor, Ventile, Druecke, Diagnose, Raumtemperaturen je Zimmer,
  volle Steuerung, nicht nur Temperaturen). Er wurde um einen kleinen Ausschnitt
  (nur die Standardfelder: Aussentemp, Vorlauf/Ruecklauf, Warmwasser Ist/Soll)
  gebeten statt der ganzen Datei -- passt besser zur v1-Linie "erst Temperaturen,
  keine Steuerbefehle" und respektiert die Kundenexklusivitaet der Vollversion.
  **Update 18.09.2026 (zweiter Nachtrag) -- Excel gelesen, DREI Bloecker, bewusst
  NICHT als Treiber gebaut:**

  1. **Transport bestaetigt seriell, kein Gateway.** Ghostraider: "läuft über RS485 an
     einem USB Dongel" -- also ein lokaler USB-RS485-Adapter direkt am Symcon-Host,
     KEIN Netzwerk-Gateway. `WPMBHUB_ModbusTcpClient` spricht nur TCP-Sockets
     (`fsockopen`) -- das bestehende Modul kann diese Verbindung technisch nicht
     herstellen, unabhaengig von der Registerkarte. Eine serielle Transportart waere
     ein eigener Baustein (IP-Symcons Serial-Port-Instanz als Parent, kein simpler
     Socket-Client mehr) -- deutlich groesserer Umbau als eine neue Registerkarte,
     noch nicht begonnen.
  2. **FWT ist eine Zu-/Abluft-Waermepumpe, kein Hydronik-System.** Die Excel
     ("Modbus Liste FWT2.0 ver2.xlsx", Sheets "Holding Register"/"Input Register",
     lokal unter `/Users/dietmar/Downloads/Proxon/` gelesen) kennt kein
     Vorlauf/Ruecklauf -- stattdessen Luft-Sensoren T1 Zuluft, T3 Frischluft
     (naeheste Entsprechung zu "Aussentemperatur"), T4 Fortluft, T7 Abluft, dazu
     Kaeltekreis-Sensoren T5/T6/T8/T10/T11/T13/T14. Passt NICHT ins bestehende
     Vorlauftemperatur/Ruecklauftemperatur-Feldschema der anderen fuenf Hersteller --
     bräuchte eigene Idents statt der bestehenden, kein Fall von "Adresse eintragen,
     fertig".
  3. **T300-Temperaturskalierung nur ABGELEITET, nicht explizit dokumentiert.** Die
     T300-Excel ("...ver2 T300.xlsx") hat bei Input-Register-Temperaturen (z. B.
     4x0813 "T20 Behälter Unten", 4x0814 "T21 Behälter Mitte") eine separate
     "Offset"-Spalte = -100 neben "Format" = "/10", waehrend das Holding-Register
     "Normal Wassertemperatur" (3x2000, Roh-Min/Max 200/550 -> IST-Min/Max 20/55)
     OHNE Offset einfach raw/10 ist. Daraus abgeleitete Arbeitshypothese: bei
     Input-Registern mit Offset=-100 gilt `°C = raw/10 - 100` (Bias-Kodierung fuer
     negative Kaeltekreistemperaturen in einem uint16) -- intern konsistent, aber
     NIRGENDS als Formel ausgeschrieben, nur aus zwei Spalten kombiniert. Vor einer
     Implementierung durch einen echten Raw/Ist-Wertevergleich von Ghostraider
     bestaetigen lassen, nicht auf die Ableitung allein bauen.

  Forumsantwort hat beide offenen Punkte (Transport-Detail, ein konkretes
  Raw/Ist-Wertepaar) adressiert -- Antwort steht aus. Excel-Dateien lokal, NICHT ins
  Repo committen (Kundenexklusiv von Zimmermann, nur fuer die eigene Recherche).

  **Update 18.09.2026 (dritter Nachtrag, ÜBERHOLT durch den vierten unten) -- Dietmar
  wollte es trotzdem bauen, TEIL davon geht.** Recherche zu IP-Symcons eingebautem Serial-Port/Modbus-Configurator-
  Splitter (mehrere Websuchen: offizielle Symcon-Doku, Community-Threads,
  Open-Source-Referenzmodule wie daschaefer/SymconPluggit) ergab: die konkrete
  Splitter-GUID und das SendDataToParent-Pufferformat (Function/Address/Quantity/
  Data) sind oeffentlich NICHT verlaesslich dokumentiert -- SymconPluggit umgeht das
  Problem sogar selbst, indem es fuer sein (TCP-basiertes) Geraet eine eigene
  Phpmodbus-Verbindung aufbaut statt durch den Symcon-Splitter zu gehen. Fuer echten
  seriellen Zugriff (Windows-COM-Port wie bei Ghostraider) ist reines PHP ohne
  Symcons Kern-Unterstuetzung nicht zuverlaessig plattformuebergreifend moeglich --
  bewusst NICHT geraten implementiert (Verbund-Regel: Symcon-SDK-Methoden verifizieren,
  nicht aus Analogie annehmen).

  Stattdessen umgesetzt: **`proxon` als siebter DRIVERS-Eintrag** (0.4.0), aber NUR
  die zwei Register mit eindeutiger Skalierung (`Warmwasser` via 4x0882 BehaelterAvg,
  Faktor 100, kein Offset; `WarmwasserSoll` via 3x2000 Normal Wassertemperatur,
  Faktor 10, kein Offset) -- beide passen unveraendert ins bestehende s16xFaktor-
  Schema, kein neuer Datentyp noetig. Empfehlung an Nutzer mit reinem USB-RS485-
  Dongle (wie Ghostraider): ein RS485-zu-Ethernet-Gateway im "Modbus TCP zu
  RTU"-Gatewaymodus davorsetzen (gleiches Geraeteschema wie SamsungEhs/Waveshare,
  NICHT nur rohes Byte-Tunneling -- das waere ein anderer Modus). Damit funktioniert
  der bestehende `WPMBHUB_ModbusTcpClient` unveraendert. T20/T21-Tankfuehler und die
  FWT-Lueftungszentrale bleiben bewusst aussen vor (siehe oben, Bloecker 2+3
  weiterhin ungeloest -- Bloecker 1 nur per Hardware-Workaround umgangen, nicht im
  Modul geloest).
  **Update 19.09.2026 (vierter Nachtrag) -- Blocker 1 (serieller Transport) GELÖST, der
  Satz "SDK-Details nicht verifizierbar" im dritten Nachtrag war falsch.** Dietmar bestand
  darauf, es zu bauen ("bei mir wissen", Test über Ghostraider). Der Denkfehler der ersten
  Recherche: nach Doku gesucht statt nach Open-Source-Referenzmodulen -- das offizielle
  Symcon-Modul `symcon/SymconBC` (EM24-DIN) zeigt das Gateway-Schema im Klartext, und
  MeterHub/InverterHub/ChargerHub hatten es im Verbund (SUITE.md 9j) schon gegengelesen und
  live bestätigt. Umsetzung als **eigenes Schwestermodul `WPModbusHubGateway`** (Prefix
  `WPMBGW`, GUID {70FBAC61-...}), Kind des nativen ModBus Gateways: `module.json`
  `parentRequirements {E310B701-...}`, `implemented {77B31ABB-...}` (beide an einer
  Live-IPS aus der Moduldefinition des ModBus Gateways ausgelesen, Serial Port ist
  {6DC3D946-0D31-450F-A8C6-C42DB8D7D4F1}, ModBus Gateway {A5F663AB-...}). Eigenes Modul statt
  Property im TCP-Modul, weil `parentRequirements` sonst jede bestehende TCP-Instanz
  betroffen hätte. `ConnectParent()` bewusst NICHT (würde ungefragt ein neues Gateway
  anlegen). Repo umgebaut: `libs/` am Library-Root (`WPMBHUB_Drivers` = geteilte
  Registerkarten, `WPMBHUB_HeatpumpTrait` = transportunabhängige Logik,
  `ModbusTcpClient.php`, `ModbusGatewayClient.php` -- Muster Pluggit-Modul, `require_once
  __DIR__.'/../libs/..'`). Gateway-Client erbt (wie ChargerHub) nur die Dekodierhilfen vom
  TCP-Client; `SendDataToParent()` ist protected, daher Closure vom Modul. Unit-ID sitzt am
  Gateway (`DeviceID`), nicht im Puffer. **Offen: an einer echten Anlage über diesen Weg
  ungetestet** -- Ghostraider soll mit "Verbindung testen" und
  `WPMBGW_ReadRaw($id, 4, 882, 1)` prüfen (ReadRaw liefert auch die Rohwerte der
  T20/T21-Tankfühler für die noch offene Offset-Formel). Unverändert offen: FWT-Zentrale,
  T20/T21-Skalierung.

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

**Gegenprobe an einer laufenden Anlage (18.09.2026, EMS-Sitzung):** Die EMS-Sitzung hat
aus der Symcon-Konfiguration von Dietmars Geschäftsanlage (Waterkotte produktiv per
Modbus TCP, nur lesend ausgelesen, keine zusätzlichen Abfragen) eine 72-Register-Tabelle
erstellt: `/Users/dietmar/Nextcloud/Claude/Waterkotte-Registertabelle.md` (Praxis-Zuordnung
von Hand in Symcon gebaut, KEIN Herstellerdokument; Datentypen aus den Werten abgeleitet;
"schreibbar" = nur ein Schreib-FC eingetragen, nie geprüft; keine Netzwerkadressen im Repo
übernehmen). Ergebnis für unsere Registerkarte: **Adresse 1, 11, 12, 16, 30**
(Außen-/Rücklauf-/Vorlauf-/Speicher-/Heizkreistemperatur) laufen dort produktiv als
int16 mit Faktor 0,1 auf den gleichen Adressen wie im PDF -- damit ist Adressierung
(BMS-Adresse = Wire-Adresse, Symcon reicht sie 1:1 durch), Datentyp und Faktor für diese
fünf Register an echter Hardware bestätigt. **Nicht abgedeckt** (dort nicht konfiguriert,
bleiben PDF-only): 19 (Warmwasser Ist) und 31 (Heizzone Soll). Register 37 ist dort als
"Speichertemperatur Soll" beschriftet (Wert 0,0, die Anlage hat vermutlich kein
Warmwasser) -- PDF nennt es "geforderte Warmwassertemperatur", Bedeutung Speicher-Soll
vs. Warmwasser-Soll bleibt also mehrdeutig.

**Mischkreis-Rätsel aus dem Techniker-Screenshot gelöst:** Die Datenpunkt-Nummern in der
Techniker-Mail (275/276/277/278/286/287/288 für Mixer1, +46 je Kreis) sind exakt die
Wire-Adressen der laufenden Anlage (Techniker-"Adresse" = Datenpunkt+1 ist die
1-basierte Registernummer). Und `T_Os` ist NICHT die Außentemperatur (eigene frühere
Vermutung, falsch), sondern die **Heizgrenze**: Es ist eine native **Zwei-Punkt-Heizkurve
je Mischerkreis (3 Kreise)** -- 274/320/366 T Norm-Außen (-15 °C), 275/321/367 Vorlauf bei
Norm-Außen (`T_SP_norm`), 276/322/368 Heizgrenze (`T_Os`, 16 °C), 277/323/369 Vorlauf bei
Heizgrenze (`T_SP_Os`), 278/324/372 max. Vorlauf (`T_SP_max`); Kühlen: 286/332/378 Außen-
Einsatzgrenze, 287/333/379 Kühltemperatur, 288/334/380 Min-Vorlauf (`Flow_Limit`). Dass
alle drei Mischer im Techniker-Screenshot identische Werte zeigten, waren schlicht
Werkseinstellungen. Lesen läuft produktiv, Schreib-FC 6 ist in Symcon eingetragen ("teils
Schreiben" laut Anlage), aber NICHT als funktionierend geprüft. Weitere Praxis-Register
ohne PDF-Beleg: 44-49 Vorlauf Ist/Soll Kreis 1-3, 510/512/514 Mischerventile, 58/703-705
Verdichterleistung, 5011 Betriebsstunden, Coils 796-799 = SG 1..4 (EVU-Sperre,
Normalbetrieb, Sollwerterhöhung, Zwangslauf; SG-Ready-artiger Einstieg, Polarität von
"An" ungeklärt).

**Noch nicht eingearbeitet, bewusst:** Zone 2/3 (44-49; unklar, ob Mischerkreis 1 =
Zone 2 im Vertragssinn), Heizkurven-Register (kein Vertragsfeld dafür -- siehe Abschnitt
"Heizkurven-Recherche für Dashboard"), SG-Coils (Steuerung, dieses Modul ist nur lesend).
Waterkotte ist damit ein NEUER, bislang nicht an Dashboard gemeldeter Heizkurven-Kandidat
mit dem gewünschten Zwei-Punkt-Modell -- Meldung erst, wenn Schreiben an echter Hardware
geprüft ist (Dashboard-Vorgabe), vorher nur mit Dietmars Rücksprache.

## IDM-Ergänzung (18.09.2026)

Sechster Hersteller, aus einer echten Forumsanfrage heraus: "kollaps"/Christian (IDM ALM,
bereits selbst per Modbus in IP-Symcon angebunden) hat im WPModbusHub-Forumsthread gefragt,
ob IDM unterstützt werden könnte. Statt Christians eigene Konfiguration abzuwarten, wurde die
offizielle IDM-PDF gefunden und selbst gelesen (siehe Registerkarten-Tabelle oben) -- ein
Forumsbeitrag mit Wartezeit war nicht der Flaschenhals, ein gutes offizielles Dokument war
sofort verfügbar. Forumsantwort trotzdem abgeschickt: Christians eigene, bereits laufende
Konfiguration wäre eine wertvolle Zweitquelle/Live-Verifikation, sobald er antwortet.

**Architektur-Neuerung:** IDM ist der erste Hersteller dieser Liste mit 32-Bit-IEEE754-
Werten (2 Register) statt vorzeichenbehaftetem 16-Bit×Faktor-10 -- der im Klassenkopf-
Kommentar seit 17.09.2026 angekündigte "künftige Hersteller mit abweichender Kodierung" ist
damit eingetreten. Bewusst NICHT auf die im selben Kommentar angedachte volle
Treiber-Interface-Abstraktion (`WPMBHUB_HeatpumpDriverInterface`) gewechselt -- ein einzelnes
optionales `'type'=>'float32'`-Flag im bestehenden datengetriebenen Schema reicht für EINEN
zusätzlichen Datentyp, die Interface-Abstraktion bleibt der Erweiterungspunkt für einen noch
grösseren Sprung (Schreibzugriffe, mehrteilige Strukturen). Wichtige Eigenheit, die beim
ersten Verifikationstest zuerst geprüft werden sollte: IDMs Wortreihenfolge ist VERTAUSCHT
(Low-Word zuerst laut PDF-Kapitel 4.2 "Datentypen"), nicht big-endian wie die bestehenden
`u32()`/`s32()`-Methoden -- deshalb eine eigene `floatLE()`-Methode statt Wiederverwendung.

Warmwasser-Ist bewusst auf die Zapftemperatur (Adresse 1030, "Warmwasserzapftemperatur B42")
gelegt statt auf einen der beiden Speicherfühler (1012 unten/1014 oben) -- näher am
"was kommt aus dem Hahn"-Sinn der Warmwasser-Felder bei den anderen Herstellern, aber nicht
hart durch die PDF vorgegeben; bei einer echten Verifikation gegenprüfen, ob das dem
Nutzererwartung entspricht.

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
