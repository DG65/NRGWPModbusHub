<?php

// WPMBHUB_Drivers -- gemeinsame Herstellerregisterkarten fuer WPModbusHub
// (Modbus TCP, direkte Socket-Verbindung) UND WPModbusHubGateway (Modbus ueber
// Symcons eingebautes ModBus-Gateway, z. B. fuer RS485/RTU an einem
// seriellen Anschluss). EINE Quelle der Wahrheit fuer alle Registerkarten.

class WPMBHUB_Drivers
{
    const MANUFACTURER_DEFAULT = 'nibe';

    // Anzeigenamen der Felder (Ident => Beschriftung, englischer Quelltext), in der Reihenfolge, in der
    // die Variablen angelegt werden. Genutzt von maintainDeviceVariables() und der Statuszeile im
    // Formular; uebersetzt wird erst beim Anzeigen (WPMBHUB_Text::t()). Die Idents bleiben deutsch --
    // sie sind der Vertrag mit WPHub/EMS/Dashboard.
    const FIELD_CAPTIONS = [
        'Aussentemperatur'    => 'Outdoor temperature',
        'Vorlauftemperatur'   => 'Flow temperature',
        'Ruecklauftemperatur' => 'Return temperature',
        'Warmwasser'          => 'Hot water',
        'WarmwasserSoll'      => 'Hot water setpoint',
        'WarmwasserUnten'     => 'Hot water bottom',
        'WarmwasserMitte'     => 'Hot water middle',
        'Speichertemperatur'  => 'Buffer tank temperature',
        'Zone1Ist'            => 'Heating circuit 1 actual temperature',
        'Zone1Soll'           => 'Heating circuit 1 target temperature',
        'Quellentemperatur'   => 'Source temperature',
        'WaermemengeHeizen'     => 'Heat energy produced, heating (total)',
        'WaermemengeWarmwasser' => 'Heat energy produced, hot water (total)',
        'StromHeizen'         => 'Electrical energy consumed, heating (total)',
        'StromWarmwasser'     => 'Electrical energy consumed, hot water (total)',
        'Verdichterstarts'    => 'Compressor starts',
        'Betriebsart'         => 'Operating mode',
        'Zone1Komfort'        => 'Heating circuit 1 comfort temperature',
        'Zone1Eco'            => 'Heating circuit 1 ECO temperature',
    ];

    // Registerprofile je Hersteller ('caption' und 'confidence' sind englische Quelltexte, uebersetzt
    // wird beim Anzeigen). Jedes Feld: [regType('input'|'holding'),
    // addr(0-basierte Modbus-Wire-Adresse), scale(Divisor), signed(bool)] fuer
    // den Standardfall s16×Faktor. Optional 'type'=>'float32' (bislang nur
    // IDM) liest 2 Register statt 1 und dekodiert per floatLE() statt
    // s16()/u16() -- scale/signed werden dann ignoriert. Optional 'offset' (bislang
    // nur Proxon) wird NACH der Division addiert: Wert = Roh/scale + offset.
    // Optional 'type'=>'kwhmwh' (Stiebel-Energiezaehler): liest zwei aufeinanderfolgende
    // Register, das erste ist der kWh-Teil (0..999), das zweite der MWh-Teil; Wert in kWh =
    // MWh * 1000 + kWh. Optional 'kind' bestimmt die Variable: 'temperature' (Standard,
    // Float, NRG.Celsius), 'energy' (Float, ~Electricity), 'counter' (Ganzzahl),
    // 'enum' (Ganzzahl mit Profil 'profile'). 'invalidRaw' (ein Rohwert oder eine Liste):
    // Ersatzwerte fuer "Objekt nicht vorhanden". Auf Herstellerebene optional
    // 'controller' => ['addr' => Register, 'expect' => [Kennungen]]: die Reglerkennung des
    // Geraets, damit eine falsch gewaehlte Karte auffaellt. 'kind' => 'setpoint' mit
    // 'write' => ['min','max','step'] ist ein SCHREIBBARER Sollwert (Holding-Register, Function 6,
    // Wert * scale): er wird nur nach der Belehrung im Formular gelesen und angeboten, und nur im
    // WPModbusHub (TCP), nicht ueber das ModBus-Gateway.
    // Ident-Namen bewusst identisch zu WPHub (Aussentemperatur/Warmwasser/…)
    // -- GetFunctions() loest sie genau wie dort ueber contractFieldID() auf.
    const DRIVERS = [
        // NIBE S-Serie (S1155/S1255 u.ae., TCP eingebaut) -- Registerkarte
        // aus einer aktiv gepflegten, unabhaengigen Referenzbibliothek fuer
        // NIBE-Waermepumpen. Hoechste Vertrauensstufe dieser Liste.
        'nibe' => [
            'caption'      => 'NIBE (S series, e.g. S1155/S1255/S2125)',
            'confidence'   => 'Register map from an actively maintained, independent reference library for NIBE heat pumps -- not verified on real hardware.',
            'defaultPort'  => 502,
            'defaultUnitId' => 1,
            'registers'    => [
                'Aussentemperatur'  => ['regType' => 'input', 'addr' => 1,   'scale' => 10, 'signed' => true],
                'Vorlauftemperatur' => ['regType' => 'input', 'addr' => 2,   'scale' => 10, 'signed' => true],
                'Warmwasser'        => ['regType' => 'input', 'addr' => 8,   'scale' => 10, 'signed' => true],
                'Zone1Ist'          => ['regType' => 'input', 'addr' => 116, 'scale' => 10, 'signed' => true],
            ],
        ],
        // Stiebel Eltron (WPMsystem/WPM3/WPM3i/LWZ ueber ISG-Gateway,
        // "Modbus TCP/IP"-Softwareerweiterung). Registerkarte aus dem
        // offiziellen Stiebel-Eltron-PDF "ISG Modbus", Block 1 (Systemwerte,
        // Read Input Register, FC 04). Typ "2" der Herstellerdoku = s16, Faktor 0,1.
        // ADRESSEN: Das PDF nennt 1-basierte Adressen (Aussentemperatur = 507),
        // auf der Leitung steht Dokumentation MINUS 1 (506). Bestaetigt am Direktzugang
        // eines Nutzers (Forum thorsten1, 05.11.2026: Warmwasser-Ist im PDF 522,
        // dort Input-Register 521). Fruehere Versionen (bis 0.7.6) fragten Adressen
        // ohne den Block-Offset von 500 ab (z. B. 6 statt 506) und bekamen von der ISG
        // nur "Adresse nicht vorhanden". Nicht vorhandene Objekte meldet die ISG mit dem
        // Ersatzwert 0x8000 ('invalidRaw'), der nicht als Temperatur gezeigt wird.
        'stiebeleltron' => [
            'caption'      => 'Stiebel Eltron (ISG gateway, heat pumps with WPMsystem/WPM 3/WPM 3i)',
            'confidence'   => 'Register map taken directly from the official Stiebel Eltron "ISG Modbus" documentation, section heat pumps with WPM (blocks 1, 2 and 4). The addressing (documentation address minus 1) is confirmed on a WPF 15, the values themselves are not yet checked on real hardware. Energy values are the ISG\'s own estimate (compressor and heating element only, not pumps, fans or electronics), not a meter reading. Please give feedback in the forum.',
            'defaultPort'  => 502,
            'defaultUnitId' => 1,
            // Reglerkennung (Doku 5002): WPM 3 = 390, WPM 3i = 391, WPMsystem = 449.
            'controller'   => ['addr' => 5001, 'expect' => [390, 391, 449]],
            'registers'    => [
                'Aussentemperatur'    => ['regType' => 'input', 'addr' => 506, 'scale' => 10, 'signed' => true, 'invalidRaw' => 0x8000],
                'Vorlauftemperatur'   => ['regType' => 'input', 'addr' => 514, 'scale' => 10, 'signed' => true, 'invalidRaw' => 0x8000],
                'Ruecklauftemperatur' => ['regType' => 'input', 'addr' => 515, 'scale' => 10, 'signed' => true, 'invalidRaw' => 0x8000],
                'Warmwasser'          => ['regType' => 'input', 'addr' => 521, 'scale' => 10, 'signed' => true, 'invalidRaw' => 0x8000],
                'WarmwasserSoll'      => ['regType' => 'input', 'addr' => 522, 'scale' => 10, 'signed' => true, 'invalidRaw' => 0x8000],
                'Zone1Ist'            => ['regType' => 'input', 'addr' => 507, 'scale' => 10, 'signed' => true, 'invalidRaw' => 0x8000],
                // Heizkreis-1-Soll: PDF 510 (WPMsystem/WPM3), bei der WPM 3i stattdessen 509.
                'Zone1Soll'           => ['regType' => 'input', 'addr' => 509, 'scale' => 10, 'signed' => true, 'invalidRaw' => 0x8000, 'altAddr' => 508],
                // Quellentemperatur (Doku 536), nur Sole/Wasser-Geraete; Luftgeraete melden 0x8000.
                'Quellentemperatur'   => ['regType' => 'input', 'addr' => 535, 'scale' => 10, 'signed' => true, 'invalidRaw' => 0x8000],
                // Block 4 "Energetische Daten": Summe = kWh-Teil (Doku 3502 usw.) + MWh-Teil.
                'WaermemengeHeizen'     => ['regType' => 'input', 'addr' => 3501, 'type' => 'kwhmwh', 'kind' => 'energy', 'invalidRaw' => 0x8000],
                'WaermemengeWarmwasser' => ['regType' => 'input', 'addr' => 3504, 'type' => 'kwhmwh', 'kind' => 'energy', 'invalidRaw' => 0x8000],
                'StromHeizen'           => ['regType' => 'input', 'addr' => 3511, 'type' => 'kwhmwh', 'kind' => 'energy', 'invalidRaw' => 0x8000],
                'StromWarmwasser'       => ['regType' => 'input', 'addr' => 3514, 'type' => 'kwhmwh', 'kind' => 'energy', 'invalidRaw' => 0x8000],
                // Block 2 (Holding, nur gelesen): Betriebsart 0 Notbetrieb, 1 Bereitschaft, 2 Programm, 3 Komfort, 4 ECO, 5 Warmwasser.
                'Betriebsart'           => ['regType' => 'holding', 'addr' => 1500, 'scale' => 1, 'signed' => false, 'kind' => 'enum', 'profile' => 'WPMBHUB.StiebelModeWPM', 'invalidRaw' => 0x8000],
                // Raumsollwerte Heizkreis 1 (Doku 1502/1503, Holding r/w, 5..30 Grad, Schritt 1): schreibbar, siehe oben.
                'Zone1Komfort'          => ['regType' => 'holding', 'addr' => 1501, 'scale' => 10, 'signed' => true, 'kind' => 'setpoint', 'invalidRaw' => 0x8000, 'write' => ['min' => 5, 'max' => 30, 'step' => 1]],
                'Zone1Eco'              => ['regType' => 'holding', 'addr' => 1502, 'scale' => 10, 'signed' => true, 'kind' => 'setpoint', 'invalidRaw' => 0x8000, 'write' => ['min' => 5, 'max' => 30, 'step' => 1]],
            ],
        ],
        // Stiebel Eltron Lueftungsintegralgeraete (LWZ/LWA ueber ISG): EIGENE Registerkarte, Doku Abschnitt 7.
        // Adressen beginnen bei 1 (Block 1), 1001 (Block 2), 3001 (Block 4); auf der Leitung gilt wie bei den
        // WPM-Geraeten Doku minus 1. Sensorfehler meldet die LWZ-Reihe mit -60 (Unterbrechung) und -50 (Kurzschluss),
        // das sind die Rohwerte 0xFDA8 und 0xFE0C. Nicht an echter Hardware geprueft.
        'stiebeleltron_lwz' => [
            'caption'      => 'Stiebel Eltron (ISG gateway, integral ventilation units LWZ/LWA)',
            'confidence'   => 'Register map taken directly from the official Stiebel Eltron "ISG Modbus" documentation, section integral ventilation units LWZ/LWA (blocks 1, 2 and 4), not verified on real hardware. Addressing as for the WPM card: documentation address minus 1. Sensor errors (-60/-50) are not shown as a temperature. Energy values are the ISG\'s own estimate (heat generators only, not pumps, fans or electronics), not a meter reading. Please give feedback in the forum.',
            'defaultPort'  => 502,
            'defaultUnitId' => 1,
            // Reglerkennung (Doku 5002): LWZ/LWA = 103 oder 104.
            'controller'   => ['addr' => 5001, 'expect' => [103, 104]],
            'registers'    => [
                'Aussentemperatur'    => ['regType' => 'input', 'addr' => 6,  'scale' => 10, 'signed' => true, 'invalidRaw' => [0x8000, 0xFDA8, 0xFE0C]],
                'Zone1Ist'            => ['regType' => 'input', 'addr' => 7,  'scale' => 10, 'signed' => true, 'invalidRaw' => [0x8000, 0xFDA8, 0xFE0C]],
                'Zone1Soll'           => ['regType' => 'input', 'addr' => 8,  'scale' => 10, 'signed' => true, 'invalidRaw' => [0x8000, 0xFDA8, 0xFE0C]],
                'Vorlauftemperatur'   => ['regType' => 'input', 'addr' => 11, 'scale' => 10, 'signed' => true, 'invalidRaw' => [0x8000, 0xFDA8, 0xFE0C]],
                'Ruecklauftemperatur' => ['regType' => 'input', 'addr' => 12, 'scale' => 10, 'signed' => true, 'invalidRaw' => [0x8000, 0xFDA8, 0xFE0C]],
                'Warmwasser'          => ['regType' => 'input', 'addr' => 15, 'scale' => 10, 'signed' => true, 'invalidRaw' => [0x8000, 0xFDA8, 0xFE0C]],
                'WarmwasserSoll'      => ['regType' => 'input', 'addr' => 16, 'scale' => 10, 'signed' => true, 'invalidRaw' => [0x8000, 0xFDA8, 0xFE0C]],
                // Verdichterstarts (Doku 31), nur die LWZ-Reihe liefert diesen Zaehler.
                'Verdichterstarts'      => ['regType' => 'input', 'addr' => 30, 'scale' => 1, 'signed' => false, 'kind' => 'counter', 'invalidRaw' => 0x8000],
                'WaermemengeHeizen'     => ['regType' => 'input', 'addr' => 3001, 'type' => 'kwhmwh', 'kind' => 'energy', 'invalidRaw' => 0x8000],
                'WaermemengeWarmwasser' => ['regType' => 'input', 'addr' => 3004, 'type' => 'kwhmwh', 'kind' => 'energy', 'invalidRaw' => 0x8000],
                'StromHeizen'           => ['regType' => 'input', 'addr' => 3022, 'type' => 'kwhmwh', 'kind' => 'energy', 'invalidRaw' => 0x8000],
                'StromWarmwasser'       => ['regType' => 'input', 'addr' => 3025, 'type' => 'kwhmwh', 'kind' => 'energy', 'invalidRaw' => 0x8000],
                // Block 2 (Holding, nur gelesen): 0 Notbetrieb, 1 Bereitschaft, 3 Tagbetrieb, 4 Absenkbetrieb, 5 Warmwasser, 11 Automatik, 14 Handbetrieb.
                'Betriebsart'           => ['regType' => 'holding', 'addr' => 1000, 'scale' => 1, 'signed' => false, 'kind' => 'enum', 'profile' => 'WPMBHUB.StiebelModeLWZ', 'invalidRaw' => 0x8000],
            ],
        ],
        // LG Therma V -- Registerkarte aus einer community-gepflegten
        // Modbus-Konfiguration, rege genutzter Forumsthread. Geringere
        // Vertrauensstufe als NIBE/Stiebel Eltron (keine offizielle
        // LG-Quelle gefunden).
        'lg' => [
            'caption'      => 'LG Therma V',
            'confidence'   => 'Register map from a community-maintained configuration, no official LG source found -- not verified on real hardware. Please give feedback in the forum if values do not match.',
            'defaultPort'  => 502,
            'defaultUnitId' => 1,
            'registers'    => [
                'Aussentemperatur'  => ['regType' => 'input',   'addr' => 12, 'scale' => 10, 'signed' => true],
                'Vorlauftemperatur' => ['regType' => 'input',   'addr' => 3,  'scale' => 10, 'signed' => true],
                'Warmwasser'        => ['regType' => 'input',   'addr' => 5,  'scale' => 10, 'signed' => true],
                'WarmwasserSoll'    => ['regType' => 'holding', 'addr' => 8,  'scale' => 10, 'signed' => true],
                'Zone1Soll'         => ['regType' => 'holding', 'addr' => 2,  'scale' => 10, 'signed' => true],
            ],
        ],
        // Samsung EHS ueber das offizielle Zubehoer-Modul MIM-B19N (RS485-
        // Modbus-Gateway, NICHT das proprietaere NASA-Protokoll direkt am
        // F1/F2-Bus). Registerkarte aus einer Community-Sammlung, die sich
        // ihrerseits auf Samsungs offizielle Installationsanleitung
        // (DB68-07538A) beruft. Unit-ID 2 = Aussengeraet (Konvention dieses
        // Gateways). Bewusst schmal: nur Register, deren Bedeutung eindeutig
        // war -- ein Warmwasser-Register war in der Quelle selbst
        // auskommentiert/unsicher und wurde deshalb nicht uebernommen.
        'samsung' => [
            'caption'      => 'Samsung EHS (via MIM-B19N accessory module)',
            'confidence'   => 'Register map from a community collection that refers to Samsung\'s official installation manual of the MIM-B19N module -- not verified on real hardware. Applies ONLY to the official Modbus accessory module, not to the RS485/NASA route without that module.',
            'defaultPort'  => 502,
            'defaultUnitId' => 2,
            'registers'    => [
                'Aussentemperatur'    => ['regType' => 'holding', 'addr' => 13, 'scale' => 10, 'signed' => true],
                'Vorlauftemperatur'   => ['regType' => 'holding', 'addr' => 66, 'scale' => 10, 'signed' => true],
                'Ruecklauftemperatur' => ['regType' => 'holding', 'addr' => 65, 'scale' => 10, 'signed' => true],
            ],
        ],
        // Waterkotte (EcoTouch-Regler, eingebaute Modbus/TCP-Schnittstelle,
        // Port 502 fest). Registerkarte direkt aus Waterkottes eigenem PDF
        // "Software Technische Information -- Modbus/TCP" (Firmware 01.07.xx,
        // 07.2017): hoechste Vertrauensstufe dieser Liste, gleichauf mit
        // Stiebel Eltron. BMS-Analogadresse 1-5000 = Modbus/TCP-Registeradresse
        // 1:1 (Holding Registers, FC03), Werte vorzeichenbehaftet mit Faktor
        // 10 -- passt ohne Anpassung ins bestehende Registerprofil-Schema.
        // "Soll"-Felder bewusst auf die vom Regler selbst berechneten,
        // read-only Zielwerte gelegt (A31/A37 "geforderte Temperatur"), nicht
        // auf die BMS-Vorgabe-Register A32/A38 -- dieses Modul schreibt nicht.
        'waterkotte' => [
            'caption'      => 'Waterkotte (EcoTouch controller)',
            'confidence'   => 'Register map taken directly from Waterkotte\'s own PDF "Software Technische Information -- Modbus/TCP" (firmware 01.07.xx). Outdoor, flow, return, buffer and heating circuit temperature are additionally checked on a running system; hot water actual/target and heating zone target come from the PDF only.',
            'defaultPort'  => 502,
            'defaultUnitId' => 1,
            'registers'    => [
                'Aussentemperatur'    => ['regType' => 'holding', 'addr' => 1,  'scale' => 10, 'signed' => true],
                'Ruecklauftemperatur' => ['regType' => 'holding', 'addr' => 11, 'scale' => 10, 'signed' => true],
                'Vorlauftemperatur'   => ['regType' => 'holding', 'addr' => 12, 'scale' => 10, 'signed' => true],
                'Speichertemperatur'  => ['regType' => 'holding', 'addr' => 16, 'scale' => 10, 'signed' => true],
                'Warmwasser'          => ['regType' => 'holding', 'addr' => 19, 'scale' => 10, 'signed' => true],
                'WarmwasserSoll'      => ['regType' => 'holding', 'addr' => 37, 'scale' => 10, 'signed' => true],
                'Zone1Ist'            => ['regType' => 'holding', 'addr' => 30, 'scale' => 10, 'signed' => true],
                'Zone1Soll'           => ['regType' => 'holding', 'addr' => 31, 'scale' => 10, 'signed' => true],
            ],
        ],
        // IDM Energiesysteme (Navigatorregelung 2.0, z. B. ALM-Serie -- die
        // Schnittstelle haengt an der Regelung, nicht am Waermepumpenmodell).
        // Registerkarte direkt aus IDMs eigenem PDF "Modbus TCP
        // Navigatorregelung 2.0" (Dok. 812170_Rev.10, Stand 20.04.2022,
        // selbst gelesen inkl. Kapitel 4.2 "Datentypen") -- Vertrauensstufe
        // wie Waterkotte/Stiebel Eltron. EINZIGER Hersteller dieser Liste mit
        // 32-Bit-IEEE754-Float statt s16×10 (Access "RO" -> Input-Register/
        // FC04, "RW" -> Holding-Register/FC03) -- siehe 'type'=>'float32' in
        // readRegisters() und floatLE() in ModbusTcpClient.php: IDM ueberträgt
        // die Wortreihenfolge VERTAUSCHT (Low-Word zuerst), nicht big-endian.
        // WarmwasserUnten = Trinkwassererwaermer unten (B41, Adresse 1012), auf
        // Christians Wunsch (Forum 21.09.2026) zusaetzlich zum Fuehler oben; eigene
        // Variable, nicht im NRG-Stack-Vertrag (wie bei Proxon).
        // Warmwasser-Ist = Trinkwassererwaermer oben (B48, Adresse 1014).
        // Urspruenglich auf die Zapftemperatur (B42, 1030) gelegt -- die gibt es
        // aber nur mit IDMs Warmwasserstation; Christian (IDM ALM, Forum
        // 21.09.2026) hat keine verbaut, dort fehlte der Wert, und er schlug
        // den Speicherfuehler oben vor. Der ist bei jeder Anlage mit
        // Trinkwasserspeicher da und passt zu den Warmwasser-Feldern der
        // anderen Hersteller. WarmwasserSoll (UCHAR, FW030) ist die einzige
        // direkte BMS-Vorgabe dieser Liste, die zugleich auch rueckgelesen
        // werden kann (RW, kein separates Ist/Soll-Registerpaar wie bei
        // Waterkotte). Live bestaetigt von Christian (21.09.2026): Aussen,
        // Vorlauf, Ruecklauf, Speicher, Warmwasser Soll, Heizkreis A Ist/Soll
        // "passen", damit auch die vertauschte Wortreihenfolge der Floats.
        'idm' => [
            'caption'      => 'IDM Energiesysteme (Navigator control 2.0, e.g. ALM)',
            'confidence'   => 'Register map taken directly from IDM\'s own PDF "Modbus TCP Navigatorregelung 2.0" (doc. 812170_Rev.10). Fully confirmed on a running IDM ALM (all fields match). Hot water actual is the tank sensor at the top (B48), plus hot water bottom (B41); the tap temperature only exists with an IDM hot water station and is therefore not included.',
            'defaultPort'  => 502,
            'defaultUnitId' => 1,
            'registers'    => [
                'Aussentemperatur'    => ['regType' => 'input',   'addr' => 1000, 'type' => 'float32'],
                'Ruecklauftemperatur' => ['regType' => 'input',   'addr' => 1052, 'type' => 'float32'],
                'Vorlauftemperatur'   => ['regType' => 'input',   'addr' => 1050, 'type' => 'float32'],
                'Speichertemperatur'  => ['regType' => 'input',   'addr' => 1008, 'type' => 'float32'],
                'Warmwasser'          => ['regType' => 'input',   'addr' => 1014, 'type' => 'float32'],
                'WarmwasserUnten'     => ['regType' => 'input',   'addr' => 1012, 'type' => 'float32'],
                'WarmwasserSoll'      => ['regType' => 'holding', 'addr' => 1032, 'scale' => 1, 'signed' => false],
                'Zone1Ist'            => ['regType' => 'input',   'addr' => 1350, 'type' => 'float32'],
                'Zone1Soll'           => ['regType' => 'input',   'addr' => 1378, 'type' => 'float32'],
            ],
        ],
        // Proxon (Zimmermann Luftungs- und Waermesysteme, T300-Trinkwasser-
        // Waermepumpe -- NICHT die FWT-Lueftungszentrale, siehe unten).
        // Registerkarte aus Zimmermanns eigener Kunden-Excel "Modbus Liste
        // FWT2.0 ver2 T300.xlsx", die der Proxon-Nutzer "Ghostraider" per PN
        // geschickt hat (18.09.2026). Alle vier Register am 19.09.2026 ueber
        // WPModbusHubGateway an einer laufenden T300 gegen das Display geprueft:
        //   - Warmwasser (4x0882 "BehaelterAvg", Format /100): Register = 48,8
        //     = Display 48,8 -> Faktor 100, kein Offset.
        //   - WarmwasserSoll (3x2000 "Normal Wassertemperatur"): Roh 250 =
        //     Sollwert 25 Grad am Regler -> Faktor 10, kein Offset.
        //   - WarmwasserUnten/-Mitte (4x0813 T20 / 4x0814 T21, Excel-Spalte
        //     "Offset" = -100 neben Format "/10"): T21 Roh 1454 = Display 45,4 °C
        //     (exakt), T20 Roh 1412 = 41,2 gegen Display 41,3 (0,1 Zeitversatz)
        //     -> °C = Roh/10 - 100, Schluessel 'offset' => -100 im Schema. Fuer
        //     NEGATIVE Werte nur extrapoliert (uint16 kann sie nur ueber diese
        //     Vorspannung tragen), dort nicht gemessen -- deshalb nur die beiden
        //     Tankfuehler, nicht die Kaeltekreisfuehler (T5/T6/T9 ...), die
        //     im Winter unter null gehen.
        // BEWUSST NICHT uebernommen: die FWT-Lueftungszentrale (Aussenluft-
        // basierte Zu-/Abluft-Waermepumpe ohne Vorlauf/Ruecklauf -- passt
        // konzeptionell nicht auf das hydraulische Feldschema dieser Liste),
        // die Kaeltekreisfuehler und jede Form von Steuerung (~250 weitere
        // Variablen in Zimmermanns Excel, weit ueber Temperaturen hinaus).
        //
        // TRANSPORT: Proxon spricht nativ Modbus RTU ueber RS485 (Werks-Slave-ID
        // 41, 19200 Baud, 8E1), NICHT Modbus TCP. Zwei Wege:
        //   a) WPModbusHubGateway (empfohlen bei USB-RS485-Dongle am Symcon-
        //      Host): Serial Port -> ModBus Gateway (Geraete-ID 41, Modus RTU)
        //      -> Instanz. Schema gegen den Rohcode des offiziellen Symcon-
        //      Referenzmoduls (SymconBC) verifiziert UND am 19.09.2026 an einer
        //      echten Proxon T300 (USB-RS485, Nutzer Ghostraider) bestaetigt:
        //      Antwort 0x04021310 = Function 4, 2 Byte, 4880 -> 48,8 °C.
        //   b) WPModbusHub (TCP) mit einem RS485-zu-Ethernet-Gateway im
        //      "Modbus TCP zu RTU"-Gatewaymodus (echte Protokollumsetzung inkl.
        //      MBAP-Header, NICHT nur rohes Byte-Tunneling).
        'proxon' => [
            'caption'      => 'Proxon T300 (Zimmermann, domestic hot water heat pump)',
            'confidence'   => 'Four registers from Zimmermann\'s own customer Excel for the T300 hot water heat pump, checked against the display on a running system on 19.09.2026 via WPModbusHubGateway (hot water actual/target and the two tank sensors bottom/middle). Proxon natively speaks Modbus RTU over RS485: with a USB-RS485 dongle on the Symcon host use the WPModbusHubGateway module (via Symcon\'s ModBus Gateway), on the Modbus TCP route use an RS485-to-Ethernet gateway in "Modbus TCP to RTU" mode. The FWT ventilation unit (supply/exhaust air, no flow/return) is deliberately not included.',
            'defaultPort'  => 502,
            'defaultUnitId' => 41,
            'registers'    => [
                'Warmwasser'      => ['regType' => 'input',   'addr' => 882,  'scale' => 100, 'signed' => false],
                'WarmwasserSoll'  => ['regType' => 'holding', 'addr' => 2000, 'scale' => 10,  'signed' => false],
                'WarmwasserUnten' => ['regType' => 'input',   'addr' => 813,  'scale' => 10,  'signed' => false, 'offset' => -100],
                'WarmwasserMitte' => ['regType' => 'input',   'addr' => 814,  'scale' => 10,  'signed' => false, 'offset' => -100],
            ],
        ],
    ];
}
