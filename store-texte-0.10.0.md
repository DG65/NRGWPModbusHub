# Store-Texte WPModbusHub 0.10.0 (Entwurf, nicht eingereicht)

Kanal: Beta zuerst. Dietmar-Vorgabe 13.11.2026: die Symcon-Mindestversion kommt in die Store-Beschreibung des nächsten Releases.

## Deutsch

**Beschreibung**

WPModbusHub verbindet Symcon direkt per Modbus mit einer Wärmepumpe im lokalen Netz - ohne Internet und ohne Herstellerkonto. Dritter Baustein der Wärmepumpen-Vertikale im NRG-Stack, neben WPHub (Herstellerclouds) und HeishaMon (lokal, nur Panasonic).

Benötigt Symcon 9.0 oder neuer.

Unterstützte Hersteller: NIBE (S-Serie), Stiebel Eltron (ISG-Gateway, Wärmepumpen mit WPM und Lüftungsintegralgeräte LWZ/LWA), LG Therma V, Samsung EHS (über das Modbus-Zubehörmodul MIM-B19N), Waterkotte (EcoTouch), IDM (Navigatorregelung 2.0), Proxon T300 und Kronoterm (BMS-Modbus über RS485). Eine Instanz entspricht einer Wärmepumpe, eine Hersteller-Auswahl schaltet das passende Registerprofil um.

Ausgelesen werden Außentemperatur, Vorlauf-/Rücklauftemperatur sowie teils Warmwasser-, Speicher- und Heizkreis-Werte. Bei Stiebel Eltron kommen Quellentemperatur, Wärmemenge und Stromverbrauch (Summen in kWh, Schätzung des ISG), Betriebsart und bei der LWZ die Verdichterstarts dazu. Bei Kronoterm kommen die aktuelle Leistungsaufnahme (sie geht an den NRG-Stack weiter), thermische Leistung, Auslastung, COP, Betriebsstunden, Betriebsfunktion, Störungsstatus und die Energiezähler dazu. Über denselben Vertrag wie WPHub/HeishaMon stehen die Werte automatisch im ganzen NRG-Stack-Verbund zur Verfügung.

Zwei Module in einer Bibliothek: WPModbusHub baut die TCP-Verbindung selbst auf, WPModbusHubGateway hängt als Kind-Instanz am Symcon-ModBus-Gateway (Client Socket oder Serial Port, damit auch RS485/RTU) und nutzt dieselben Registerkarten. Das Kronoterm-BMS spricht Modbus RTU über RS485 und braucht den Gateway-Weg (USB-RS485 am Symcon-Host) oder einen RS485-zu-Ethernet-Adapter im Modus „Modbus TCP zu RTU".

Wichtig: Bestätigt an einer echten Anlage sind bisher IDM und die vier Register der Proxon T300, bei Stiebel Eltron die Adressierung, die Messwerte und das Schreiben der Raumsollwerte an einer WPF 15. Für die übrigen Hersteller, auch Kronoterm, zeigt das Formular einen eigenen Vertrauenshinweis. Standardmäßig nur lesend: Nur die Sollwerte (Komfort- und ECO-Temperatur von Heizkreis 1 und Warmwasser, in Schritten von 0,5 °C) von Stiebel-Eltron-Wärmepumpen lassen sich ändern, und erst nachdem du im Formular eine Belehrung bestätigt hast (nur im WPModbusHub; die Warmwasser-Sollwerte sind an echter Hardware noch nicht getestet). Das Logging im Archiv schaltet das Modul nie von selbst ein, dafür gibt es einen Knopf im Formular. Die Oberfläche ist zweisprachig (Deutsch/Englisch). Tester sind im Forumsthread willkommen.

**Versionsinformation**

- Neu: Kronoterm (BMS-Modbus, RS485), nur lesend: Temperaturen, aktuelle Leistungsaufnahme (geht an den NRG-Stack), thermische Leistung, Auslastung, COP, Betriebsstunden, Betriebsfunktion, Störungsstatus und Energiezähler. Zwei Karten: wie im Handbuch und mit um 1 verschobenen Adressen, falls das BMS so zählt. An echter Hardware noch nicht geprüft.
- Hinweis: Die Energiezähler werden bewusst noch nicht als Energie an den NRG-Stack gegeben, weil das Handbuch ihre Einheit nicht eindeutig nennt.

## English

**Description**

WPModbusHub connects Symcon directly to a heat pump on the local network via Modbus - no internet and no manufacturer account needed. Third building block of the heat pump line in the NRG-Stack, next to WPHub (manufacturer clouds) and HeishaMon (local, Panasonic only).

Requires Symcon 9.0 or newer.

Supported manufacturers: NIBE (S series), Stiebel Eltron (ISG gateway, heat pumps with WPM and integral ventilation units LWZ/LWA), LG Therma V, Samsung EHS (via the MIM-B19N Modbus accessory module), Waterkotte (EcoTouch), IDM (Navigator control 2.0), Proxon T300 and Kronoterm (BMS Modbus over RS485). One instance is one heat pump, a manufacturer selection switches the matching register profile.

It reads outdoor temperature, flow/return temperature and, depending on the model, hot water, buffer and heating circuit values. For Stiebel Eltron it adds source temperature, heat energy and electricity consumption (totals in kWh, the ISG's own estimate), operating mode and, on the LWZ, compressor starts. For Kronoterm it adds the current power consumption (passed on to the NRG-Stack), thermal output, load, COP, operating hours, working function, fault status and the energy counters. Through the same contract as WPHub/HeishaMon the values are available across the whole NRG-Stack automatically.

Two modules in one library: WPModbusHub opens the TCP connection itself, WPModbusHubGateway is a child instance of the Symcon ModBus Gateway (Client Socket or Serial Port, so RS485/RTU works too) and uses the same register profiles. The Kronoterm BMS speaks Modbus RTU over RS485 and needs the gateway route (USB-RS485 on the Symcon host) or an RS485-to-Ethernet adapter in "Modbus TCP to RTU" mode.

Important: so far IDM and the four registers of the Proxon T300 are confirmed on a real system, for Stiebel Eltron the addressing, the measured values and writing the room setpoints on a WPF 15. For the other manufacturers, Kronoterm included, the form shows a separate confidence note. Read-only by default: only the setpoints (comfort and ECO temperature of heating circuit 1 and of hot water, in steps of 0.5 °C) of Stiebel Eltron heat pumps can be changed, and only after you confirm a notice in the form (WPModbusHub only; the hot water setpoints are not yet tested on real hardware). The module never switches on archive logging by itself, there is a button in the form for that. The interface is bilingual (German/English). Testers are welcome in the forum thread.

**Release notes**

- New: Kronoterm (BMS Modbus, RS485), read-only: temperatures, current power consumption (passed on to the NRG-Stack), thermal output, load, COP, operating hours, working function, fault status and energy counters. Two cards: as in the manual and with addresses shifted by 1 in case the BMS counts that way. Not yet verified on real hardware.
- Note: The energy counters are deliberately not passed on to the NRG-Stack as energy yet, because the manual does not state their unit clearly.
