<?php

// WPMBHUB_Drivers -- gemeinsame Herstellerregisterkarten fuer WPModbusHub
// (Modbus TCP, direkte Socket-Verbindung) UND WPModbusHubGateway (Modbus ueber
// Symcons eingebautes ModBus-Gateway, z. B. fuer RS485/RTU an einem
// seriellen Anschluss). EINE Quelle der Wahrheit fuer alle Registerkarten.

class WPMBHUB_Drivers
{
    const MANUFACTURER_DEFAULT = 'nibe';

    // Registerprofile je Hersteller. Jedes Feld: [regType('input'|'holding'),
    // addr(0-basierte Modbus-Wire-Adresse), scale(Divisor), signed(bool)] fuer
    // den Standardfall s16×Faktor. Optional 'type'=>'float32' (bislang nur
    // IDM) liest 2 Register statt 1 und dekodiert per floatLE() statt
    // s16()/u16() -- scale/signed werden dann ignoriert.
    // Ident-Namen bewusst identisch zu WPHub (Aussentemperatur/Warmwasser/…)
    // -- GetFunctions() loest sie genau wie dort ueber contractFieldID() auf.
    const DRIVERS = [
        // NIBE S-Serie (S1155/S1255 u.ae., TCP eingebaut) -- Registerkarte
        // aus einer aktiv gepflegten, unabhaengigen Referenzbibliothek fuer
        // NIBE-Waermepumpen. Hoechste Vertrauensstufe dieser Liste.
        'nibe' => [
            'caption'      => 'NIBE (S-Serie, z. B. S1155/S1255/S2125)',
            'confidence'   => 'Registerkarte aus einer aktiv gepflegten, unabhängigen Referenzbibliothek für NIBE-Wärmepumpen -- nicht an echter Hardware verifiziert.',
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
        // offiziellen Stiebel-Eltron-PDF "ISG Modbus_Stiebel_Bedienungs-
        // anleitung" (Adressen einer unabhaengigen Referenzbibliothek
        // gegengeprueft, die sie direkt daraus uebernimmt). Typ "2" der
        // Herstellerdoku = s16, Faktor 0,1.
        'stiebeleltron' => [
            'caption'      => 'Stiebel Eltron (ISG-Gateway, WPMsystem/WPM3/WPM3i/LWZ)',
            'confidence'   => 'Registerkarte direkt aus dem offiziellen Stiebel-Eltron-PDF "ISG Modbus"-Bedienungsanleitung (Block 1, Systemwerte) -- nicht an echter Hardware verifiziert.',
            'defaultPort'  => 502,
            'defaultUnitId' => 1,
            'registers'    => [
                'Aussentemperatur'  => ['regType' => 'input', 'addr' => 6,  'scale' => 10, 'signed' => true],
                'Vorlauftemperatur' => ['regType' => 'input', 'addr' => 11, 'scale' => 10, 'signed' => true],
                'Warmwasser'        => ['regType' => 'input', 'addr' => 15, 'scale' => 10, 'signed' => true],
                'WarmwasserSoll'    => ['regType' => 'input', 'addr' => 16, 'scale' => 10, 'signed' => true],
                'Zone1Ist'          => ['regType' => 'input', 'addr' => 0,  'scale' => 10, 'signed' => true],
                'Zone1Soll'         => ['regType' => 'input', 'addr' => 1,  'scale' => 10, 'signed' => true],
            ],
        ],
        // LG Therma V -- Registerkarte aus einer community-gepflegten
        // Modbus-Konfiguration, rege genutzter Forumsthread. Geringere
        // Vertrauensstufe als NIBE/Stiebel Eltron (keine offizielle
        // LG-Quelle gefunden).
        'lg' => [
            'caption'      => 'LG Therma V',
            'confidence'   => 'Registerkarte aus einer community-gepflegten Konfiguration, keine offizielle LG-Quelle gefunden -- nicht an echter Hardware verifiziert. Bitte Rueckmeldung im Forum, falls Werte nicht passen.',
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
            'caption'      => 'Samsung EHS (über MIM-B19N-Zubehörmodul)',
            'confidence'   => 'Registerkarte aus einer Community-Sammlung, die sich auf Samsungs offizielle Installationsanleitung des MIM-B19N-Moduls beruft -- nicht an echter Hardware verifiziert. Gilt NUR für das offizielle Modbus-Zubehörmodul, nicht für die RS485/NASA-Route ohne dieses Modul.',
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
            'caption'      => 'Waterkotte (EcoTouch-Regler)',
            'confidence'   => 'Registerkarte direkt aus Waterkottes eigenem PDF "Software Technische Information -- Modbus/TCP" (Firmware 01.07.xx). Außen-, Vorlauf-, Rücklauf-, Speicher- und Heizkreistemperatur sind zusätzlich an einer laufenden Anlage gegengeprüft; Warmwasser Ist/Soll und Heizzone Soll stammen nur aus dem PDF.',
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
        // Warmwasser-Ist bewusst auf die Zapftemperatur (B42) gelegt, nicht
        // auf die beiden Speicherfuehler oben/unten (B48/B41) -- naeher am
        // "was kommt aus dem Hahn"-Sinn der anderen Hersteller-Warmwasser-
        // Felder. WarmwasserSoll (UCHAR, FW030) ist die einzige direkte
        // BMS-Vorgabe dieser Liste, die zugleich auch rueckgelesen werden
        // kann (RW, kein separates Ist/Soll-Registerpaar wie bei Waterkotte).
        'idm' => [
            'caption'      => 'IDM Energiesysteme (Navigatorregelung 2.0, z. B. ALM)',
            'confidence'   => 'Registerkarte direkt aus IDMs eigenem PDF "Modbus TCP Navigatorregelung 2.0" (Dok. 812170_Rev.10) -- nicht an echter Hardware verifiziert.',
            'defaultPort'  => 502,
            'defaultUnitId' => 1,
            'registers'    => [
                'Aussentemperatur'    => ['regType' => 'input',   'addr' => 1000, 'type' => 'float32'],
                'Ruecklauftemperatur' => ['regType' => 'input',   'addr' => 1052, 'type' => 'float32'],
                'Vorlauftemperatur'   => ['regType' => 'input',   'addr' => 1050, 'type' => 'float32'],
                'Speichertemperatur'  => ['regType' => 'input',   'addr' => 1008, 'type' => 'float32'],
                'Warmwasser'          => ['regType' => 'input',   'addr' => 1030, 'type' => 'float32'],
                'WarmwasserSoll'      => ['regType' => 'holding', 'addr' => 1032, 'scale' => 1, 'signed' => false],
                'Zone1Ist'            => ['regType' => 'input',   'addr' => 1350, 'type' => 'float32'],
                'Zone1Soll'           => ['regType' => 'input',   'addr' => 1378, 'type' => 'float32'],
            ],
        ],
        // Proxon (Zimmermann Luftungs- und Waermesysteme, T300-Trinkwasser-
        // Waermepumpe -- NICHT die FWT-Lueftungszentrale, siehe unten).
        // Registerkarte aus Zimmermanns eigener Kunden-Excel "Modbus Liste
        // FWT2.0 ver2 T300.xlsx", die der Proxon-Nutzer "Ghostraider" per PN
        // geschickt hat (18.09.2026) -- bewusst NUR die
        // zwei Register uebernommen, deren Skalierung eindeutig aus der
        // Tabelle selbst hervorgeht (Roh-Min/Max passt exakt zu IST-Min/Max):
        //   - WarmwasserSoll (3x2000 "Normal Wassertemperatur", Roh 200..550
        //     -> IST 20..55 -> Faktor 10, kein Offset).
        //   - Warmwasser (4x0882 "BehaelterAvg", Format /100, Offset-Spalte 0
        //     -> Faktor 100, kein Offset).
        // BEWUSST NICHT uebernommen: die Tank-Einzelfuehler T20/T21 (4x0813/
        // 4x0814) und der externe Fuehler T9 (4x0817) -- die haben in der
        // Tabelle eine separate "Offset"-Spalte = -100 neben Format "/10",
        // ohne dass irgendwo eine Formel ausgeschrieben ist. Arbeitshypothese
        // waere °C = Roh/10 - 100 (Bias-Kodierung fuer negative Kaeltekreis-
        // werte in einem uint16), aber NICHT bestaetigt -- erst mit einem
        // echten Roh/Ist-Wertepaar von einem Nutzer gegenpruefen, dann erst
        // aufnehmen. Genauso bewusst NICHT uebernommen: die FWT-Lueftungs-
        // zentrale (Aussenluft-basierte Zu-/Abluft-Waermepumpe ohne Vorlauf/
        // Ruecklauf -- passt konzeptionell nicht auf das hydraulische
        // Feldschema dieser Liste) und jede Form von Steuerung (~250 weitere
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
            'caption'      => 'Proxon T300 (Zimmermann, Trinkwasser-Wärmepumpe)',
            'confidence'   => 'Nur zwei Register aus Zimmermanns eigener Kunden-Excel für die T300-Warmwasser-Wärmepumpe übernommen, deren Skalierung eindeutig ist -- Warmwasser Ist (4x0882) wurde über WPModbusHubGateway an einer laufenden Proxon abgelesen (plausibler Wert, Gegenprüfung mit dem Display steht aus), Warmwasser Soll noch nicht. Proxon spricht nativ Modbus RTU über RS485: mit USB-RS485-Dongle am Symcon-Host das Modul WPModbusHubGateway (über Symcons ModBus-Gateway) verwenden, mit Modbus-TCP-Weg ein RS485-zu-Ethernet-Gateway im „Modbus TCP zu RTU“-Modus. Die FWT-Lüftungszentrale (Zu-/Abluft, kein Vorlauf/Rücklauf) ist bewusst nicht enthalten.',
            'defaultPort'  => 502,
            'defaultUnitId' => 41,
            'registers'    => [
                'Warmwasser'     => ['regType' => 'input',   'addr' => 882,  'scale' => 100, 'signed' => false],
                'WarmwasserSoll' => ['regType' => 'holding', 'addr' => 2000, 'scale' => 10,  'signed' => false],
            ],
        ],
    ];
}
