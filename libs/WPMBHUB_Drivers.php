<?php

// WPMBHUB_Drivers -- gemeinsame Herstellerregisterkarten fuer WPModbusHub
// (Modbus TCP, direkte Socket-Verbindung) UND WPModbusHubGateway (Modbus ueber
// Symcons eingebautes ModBus-Gateway, z. B. fuer RS485/RTU an einem
// seriellen Anschluss). EINE Quelle der Wahrheit fuer alle Registerkarten.

class WPMBHUB_Drivers
{
    const MANUFACTURER_DEFAULT = 'nibe';

    // Anzeigenamen der Felder (Ident => Beschriftung), in der Reihenfolge, in der die
    // Variablen angelegt werden. Genutzt von maintainDeviceVariables() und der
    // Statuszeile im Formular.
    const FIELD_CAPTIONS = [
        'Aussentemperatur'    => 'Außentemperatur',
        'Vorlauftemperatur'   => 'Vorlauftemperatur',
        'Ruecklauftemperatur' => 'Rücklauftemperatur',
        'Warmwasser'          => 'Warmwasser',
        'WarmwasserSoll'      => 'Warmwasser Sollwert',
        'WarmwasserUnten'     => 'Warmwasser unten',
        'WarmwasserMitte'     => 'Warmwasser Mitte',
        'Speichertemperatur'  => 'Pufferspeichertemperatur',
        'Zone1Ist'            => 'Heizzone 1 Isttemperatur',
        'Zone1Soll'           => 'Heizzone 1 Solltemperatur',
    ];

    // Registerprofile je Hersteller. Jedes Feld: [regType('input'|'holding'),
    // addr(0-basierte Modbus-Wire-Adresse), scale(Divisor), signed(bool)] fuer
    // den Standardfall s16×Faktor. Optional 'type'=>'float32' (bislang nur
    // IDM) liest 2 Register statt 1 und dekodiert per floatLE() statt
    // s16()/u16() -- scale/signed werden dann ignoriert. Optional 'offset' (bislang
    // nur Proxon) wird NACH der Division addiert: Wert = Roh/scale + offset.
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
            'caption'      => 'IDM Energiesysteme (Navigatorregelung 2.0, z. B. ALM)',
            'confidence'   => 'Registerkarte direkt aus IDMs eigenem PDF "Modbus TCP Navigatorregelung 2.0" (Dok. 812170_Rev.10). An einer laufenden IDM ALM vollständig bestätigt (alle Felder passen). Warmwasser Ist ist der Speicherfühler oben (B48), zusätzlich Warmwasser unten (B41); die Zapftemperatur gibt es nur mit IDM-Warmwasserstation und ist deshalb nicht enthalten.',
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
            'caption'      => 'Proxon T300 (Zimmermann, Trinkwasser-Wärmepumpe)',
            'confidence'   => 'Vier Register aus Zimmermanns eigener Kunden-Excel für die T300-Warmwasser-Wärmepumpe, am 19.09.2026 an einer laufenden Anlage über WPModbusHubGateway gegen das Display geprüft (Warmwasser Ist/Soll und die beiden Behälterfühler unten/mitte). Proxon spricht nativ Modbus RTU über RS485: mit USB-RS485-Dongle am Symcon-Host das Modul WPModbusHubGateway (über Symcons ModBus-Gateway) verwenden, mit Modbus-TCP-Weg ein RS485-zu-Ethernet-Gateway im „Modbus TCP zu RTU“-Modus. Die FWT-Lüftungszentrale (Zu-/Abluft, kein Vorlauf/Rücklauf) ist bewusst nicht enthalten.',
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
