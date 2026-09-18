<?php

require_once __DIR__ . '/libs/ModbusTcpClient.php';

// NRG-Stack WPModbusHub -- lokale Modbus-TCP-Anbindung fuer Waermepumpen
// mehrerer Hersteller (NIBE, Stiebel Eltron, LG, Samsung EHS ueber MIM-B19n,
// Waterkotte EcoTouch).
// Dritter Baustein der Waermepumpen-Vertikale im Verbund, neben WPHub
// (Cloud, mehrere Hersteller) und HeishaMon (lokal, nur Panasonic):
//
//   WPHub         -- Herstellercloud (Panasonic Comfort Cloud, Vaillant
//                     myVAILLANT), Internet noetig
//   HeishaMon     -- lokal per MQTT, nur Panasonic mit HeishaMon-Platine
//   WPModbusHub   -- lokal per Modbus TCP, mehrere Hersteller mit
//                     eingebauter oder nachgeruesteter Modbus-Schnittstelle
//
// Aufbau analog zu MeterHub (DG65/NRGMeterHub): eine Instanz = eine
// Waermepumpe, Property "Manufacturer" waehlt das Registerprofil.
// WPMBHUB_ModbusTcpClient ist 1:1 aus MeterHub portiert (siehe dort).
//
// Anders als bei MeterHub (13 Treiberklassen mit individueller Dekodierlogik
// fuer Float32/Double64/Schreibkanaele) sind alle bisherigen Wärmepumpen-
// Register einheitlich vorzeichenbehaftete 16-Bit-Werte mit Faktor 10 --
// ein gemeinsames, datengetriebenes Registerprofil (DRIVERS-Konstante)
// statt einer Klasse je Hersteller. Sollte ein kuenftiger Hersteller eine
// abweichende Kodierung brauchen (Float32, mehrere Register je Wert), ist
// das Interface WPMBHUB_HeatpumpDriverInterface (analog MeterHub) der
// vorgesehene Erweiterungspunkt -- bislang nicht noetig.
//
// Vertrag WPHUB_GetFunctions()-kompatibel: Type=>'heatpump', contractVersion
// 1.15, dieselben Feldnamen/Idents wie WPHub (siehe DG65/NRGWPHub) -- EMS/
// Dashboard behandeln alle drei Waermepumpen-Datenquellen identisch.
//
// Registerkarten-Herkunft (siehe DRIVERS-Kommentare je Hersteller) --
// KEINE davon an echter Hardware verifiziert (kein Testkonto/-geraet
// vorhanden, Stand 17.09.2026). Muster "Registerkarten: erst messen, dann
// glauben" (MeterHub-CLAUDE.md): so weit wie moeglich aus offizieller
// Herstellerdokumentation oder einer aktiv gepflegten, unabhaengigen
// Referenzimplementierung, nie aus einer reinen Forenzusammenfassung.
// Bewusst NUR lesend (keine Steuerbefehle) -- analog zu WPHubs
// Vaillant-Anbindung beim Start.

class WPModbusHub extends IPSModule
{
    const NEWS_VERSION = '0.2.0';

    // Registerprofile je Hersteller. Jedes Feld: [regType('input'|'holding'),
    // addr(0-basierte Modbus-Wire-Adresse), scale(Divisor), signed(bool)].
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
            'confidence'   => 'Registerkarte direkt aus Waterkottes eigenem PDF "Software Technische Information -- Modbus/TCP" (Firmware 01.07.xx) -- nicht an echter Hardware verifiziert.',
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
    ];
    const MANUFACTURER_DEFAULT = 'nibe';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Manufacturer', self::MANUFACTURER_DEFAULT);
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', self::DRIVERS[self::MANUFACTURER_DEFAULT]['defaultPort']);
        $this->RegisterPropertyInteger('UnitId', self::DRIVERS[self::MANUFACTURER_DEFAULT]['defaultUnitId']);
        $this->RegisterPropertyBoolean('WPMBHUB_Active', false);
        $this->RegisterPropertyInteger('WPMBHUB_Interval', 60);

        // Einmalig dismissible "Wozu dieses Modul?"-Panel (SUITE.md
        // "Einheitliche Formular-Optik" Punkt 0).
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeInteger('LastSeenAt', 0);
        // Einmalig dismissible Forum-Hinweis (SUITE.md "Einheitliche Formular-
        // Optik", Forumsthread seit 18.09.2026 live), siehe ForumHint().
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        $this->RegisterTimer('WPMBHUB_UpdateTimer', 0, 'WPMBHUB_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ensureSharedProfiles();

        $active   = $this->ReadPropertyBoolean('WPMBHUB_Active');
        $interval = max(30, $this->ReadPropertyInteger('WPMBHUB_Interval'));
        $hasHost  = trim($this->ReadPropertyString('Host')) !== '';

        if (!$active) {
            $this->SetTimerInterval('WPMBHUB_UpdateTimer', 0);
            $this->SetStatus(104);
        } elseif (!$hasHost) {
            $this->SetTimerInterval('WPMBHUB_UpdateTimer', 0);
            $this->SetStatus(201);
        } else {
            $this->SetTimerInterval('WPMBHUB_UpdateTimer', $interval * 1000);
            $this->SetStatus(102);
        }
    }

    /**
     * Hersteller-Auswahl umgeschaltet -- Port/Unit-ID im OFFENEN Formular auf
     * den herstellertypischen Vorschlag setzen, aber nur, solange der
     * Nutzer sie noch nicht selbst abweichend gesetzt hat. Gleiches Muster
     * wie MeterHubs OnChangeMeter() (dokumentierte Alternative zu
     * PropertyCondition, siehe MeterHub-CLAUDE.md).
     */
    public function OnChangeManufacturer(string $manufacturer): void
    {
        if (!isset(self::DRIVERS[$manufacturer])) {
            return;
        }
        $driver = self::DRIVERS[$manufacturer];
        $this->UpdateFormField('Port', 'value', $driver['defaultPort']);
        $this->UpdateFormField('UnitId', 'value', $driver['defaultUnitId']);
        $this->UpdateFormField('ManufacturerConfidence', 'caption', 'ℹ️ ' . $driver['confidence']);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $libraryInfo = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        $libraryVersion = (is_array($libraryInfo) && isset($libraryInfo['version'])) ? (string)$libraryInfo['version'] : '?';
        $this->updateFormElement($form['elements'], 'VersionInfo', [
            'caption' => 'ℹ️ WPModbusHub Version ' . $libraryVersion . ' -- lokale Modbus-Anbindung fuer Waermepumpen mehrerer Hersteller.',
        ]);

        $manufacturer = $this->ReadPropertyString('Manufacturer');
        if (isset(self::DRIVERS[$manufacturer])) {
            $this->updateFormElement($form['elements'], 'ManufacturerConfidence', [
                'caption' => 'ℹ️ ' . self::DRIVERS[$manufacturer]['confidence'],
            ]);
        }

        $purposeIntro = $this->PurposeIntro();
        if ($purposeIntro !== null) {
            array_unshift($form['elements'], $purposeIntro);
        }
        if ($this->ReadAttributeString('SeenNews') !== self::NEWS_VERSION) {
            array_unshift($form['elements'], [
                'type'     => 'ExpansionPanel',
                'name'     => 'NewsPanel',
                'caption'  => '🆕 Neu in Version ' . self::NEWS_VERSION,
                'expanded' => true,
                'items'    => [
                    ['type' => 'Label', 'caption' => '• Fünfter Hersteller: Waterkotte (EcoTouch-Regler) -- Registerkarte direkt aus Waterkottes eigenem Modbus/TCP-PDF, inklusive Pufferspeichertemperatur.'],
                    ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'WPMBHUB_AckNews($id);'],
                ],
            ]);
        }

        $forumHint = $this->ForumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }

        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
    }

    private function updateFormElement(array &$items, string $name, array $patch): bool
    {
        foreach ($items as &$item) {
            if (($item['name'] ?? null) === $name) {
                $item = array_merge($item, $patch);
                return true;
            }
            if (isset($item['items']) && is_array($item['items'])) {
                if ($this->updateFormElement($item['items'], $name, $patch)) {
                    return true;
                }
            }
        }
        return false;
    }

    public function AckNews(): void
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'PurposeIntroPanel',
            'expanded' => true,
            'caption'  => '👋  Wozu dieses Modul?',
            'items'    => [
                ['type' => 'Label', 'caption' => 'WPModbusHub liest Wärmepumpen mehrerer Hersteller direkt per Modbus TCP im lokalen Netz aus -- ohne Internet, ohne Herstellerkonto. Ergänzt WPHub (Herstellerclouds) und HeishaMon (Panasonic lokal) um Hersteller mit eingebauter oder nachrüstbarer Modbus-Schnittstelle.'],
                ['type' => 'Label', 'caption' => 'Bewusst nur lesend (keine Steuerbefehle) und Stand heute ungeprüft an echter Hardware -- die Registerkarten stammen aus offiziellen Herstellerdokumenten bzw. aktiv gepflegten Referenzintegrationen, siehe Hinweis beim gewählten Hersteller.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'WPMBHUB_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    // Forumsthread seit 18.09.2026 live (Dietmar).
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-wpmodbushub-lokale-modbus-anbindung-fuer-waermepumpen-mehrerer-hersteller-nibe-stiebel-eltron-lg-samsung/144421';

    /**
     * Symcon-Forum-Hinweis -- SUITE.md "Einheitliche Formular-Optik", nach den
     * Fachpanels, vor "Über dieses Modul". Einmalig dismissible, kein
     * Versionsbezug (Muster WPHub ForumHint()/AckForumHint()).
     */
    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'ForumHintPanel',
            'expanded' => true,
            'caption'  => '💬  Feedback im Symcon-Forum',
            'items'    => [
                ['type' => 'Label', 'caption' => 'Fragen, Fehler oder Erfahrungsberichte zu NIBE, Stiebel Eltron, LG, Samsung oder Waterkotte -- dafür gibt es den WPModbusHub-Forumsthread.'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'WPMBHUB_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    // Zeigt auf beta (erster Store-Release-Branch, siehe SUITE.md-Stolperfalle
    // 01.09.2026: nicht blind auf main verlinken -- main existiert fuer dieses
    // Repo noch nicht). Aktive Entwicklung bleibt auf ems-integration.
    private const LICENSE_URL = 'https://github.com/DG65/NRGWPModbusHub/blob/beta/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    private function LicenseHint(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'caption'  => '🧡  Über dieses Modul',
            'items'    => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    public function Update(): void
    {
        if (!$this->ReadPropertyBoolean('WPMBHUB_Active')) {
            return;
        }
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            $this->SetStatus(201);
            return;
        }
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        if (!isset(self::DRIVERS[$manufacturer])) {
            $this->LogMessage('Unbekannter Hersteller "' . $manufacturer . '".', KL_WARNING);
            return;
        }
        $client = new WPMBHUB_ModbusTcpClient($host, $this->ReadPropertyInteger('Port'), $this->ReadPropertyInteger('UnitId'));
        $values = $this->readRegisters(self::DRIVERS[$manufacturer]['registers'], $client);
        $client->close();

        $reachable = ($values !== null && count($values) > 0);
        $this->maintainDeviceVariables($values ?? [], $reachable);
        if ($reachable) {
            $this->WriteAttributeInteger('LastSeenAt', time());
        }

        $this->SetStatus($reachable ? 102 : 201);
        if (!$reachable) {
            $this->LogMessage('Wärmepumpe nicht erreichbar (' . $host . ':' . $this->ReadPropertyInteger('Port') . ').', KL_WARNING);
        }
    }

    /**
     * Liest alle Register eines Herstellerprofils. Liefert ['Ident' => float]
     * fuer jedes erfolgreich gelesene Feld -- ein einzelnes fehlgeschlagenes
     * Register lässt die uebrigen unberuehrt (kein Alles-oder-nichts), NULL
     * nur wenn der gesamte Verbindungsaufbau fehlschlaegt.
     */
    private function readRegisters(array $registerMap, WPMBHUB_ModbusTcpClient $client): ?array
    {
        $out = [];
        $anySuccess = false;
        $anyAttempt = false;
        foreach ($registerMap as $ident => $def) {
            $anyAttempt = true;
            $regs = ($def['regType'] === 'holding')
                ? $client->readHolding($def['addr'], 1)
                : $client->readInput($def['addr'], 1);
            if ($regs === null) {
                continue;
            }
            $anySuccess = true;
            $raw = !empty($def['signed']) ? $client->s16($regs, 0) : $client->u16($regs, 0);
            $scale = $def['scale'] ?? 1;
            // (float) vor der Division: PHP liefert bei glatt teilbaren
            // int/int-Werten sonst wieder einen int zurueck (z.B. 470/10 =
            // int 47 statt float 47.0) -- MaintainVariable() erwartet einen
            // durchgehend gleichartigen Typ je Ident.
            $out[$ident] = (float)$raw / $scale;
        }
        if ($anyAttempt && !$anySuccess) {
            return null;
        }
        return $out;
    }

    private function maintainDeviceVariables(array $values, bool $reachable): void
    {
        $pos = 0;
        $this->MaintainVariable('Erreichbar', 'Erreichbar', VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $pos++, true);
        $this->SetValue('Erreichbar', $reachable);

        foreach ([
            'Aussentemperatur'    => 'Außentemperatur',
            'Vorlauftemperatur'   => 'Vorlauftemperatur',
            'Ruecklauftemperatur' => 'Rücklauftemperatur',
            'Warmwasser'          => 'Warmwasser',
            'WarmwasserSoll'      => 'Warmwasser Sollwert',
            'Speichertemperatur'  => 'Pufferspeichertemperatur',
            'Zone1Ist'            => 'Heizzone 1 Isttemperatur',
            'Zone1Soll'           => 'Heizzone 1 Solltemperatur',
        ] as $ident => $caption) {
            if (!array_key_exists($ident, $values)) {
                continue;
            }
            $this->MaintainVariable($ident, $caption, VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
            $this->ensureArchived($ident);
            $this->SetValue($ident, (float)$values[$ident]);
        }
    }

    private function ensureArchived(string $ident): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id === false) {
            return;
        }
        $archiveIDs = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
        if (!is_array($archiveIDs) || count($archiveIDs) === 0) {
            return;
        }
        try {
            if (!AC_GetLoggingStatus($archiveIDs[0], $id)) {
                AC_SetLoggingStatus($archiveIDs[0], $id, true);
                IPS_ApplyChanges($archiveIDs[0]);
            }
        } catch (\Throwable $e) {
            // Archivierung ist ein Komfortfeature, kein Zyklus-Abbruch wert.
        }
    }

    /**
     * NRG-Stack-Vertrag fuer Waermepumpen, konsistent zu WPHub/HeishaMon
     * (Type=>'heatpump', contractVersion 1.15, dieselben Feldnamen). Eine
     * WPModbusHub-Instanz = eine Waermepumpe, daher immer genau ein Eintrag.
     * PowerID/EnergyID bleiben 0 -- die bisherigen Registerprofile decken
     * nur Temperaturen ab, keine Leistungs-/Energiezaehler (kuenftige
     * Erweiterung, kein WPModbusHub-spezifisches Problem).
     */
    public function GetFunctions()
    {
        $reachableID = @$this->GetIDForIdent('Erreichbar');
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        $caption = isset(self::DRIVERS[$manufacturer]) ? self::DRIVERS[$manufacturer]['caption'] : 'Wärmepumpe';
        return [[
            'contractVersion'      => '1.15',
            'Type'                 => 'heatpump',
            'Caption'              => $caption,
            'PowerID'              => 0,
            'EnergyID'             => 0,
            'Measured'             => false,
            'unit'                 => 'W',
            'reachable'            => ($reachableID === false) ? false : (bool)GetValue($reachableID),
            'outsideTempID'        => $this->contractFieldID('Aussentemperatur'),
            'outdoorTemperatureID' => $this->contractFieldID('Aussentemperatur'),
            'z1WaterTempID'        => $this->contractFieldID('Zone1Ist'),
            'z1WaterTargetTempID'  => $this->contractFieldID('Zone1Soll'),
            'z2WaterTempID'        => 0,
            'z2WaterTargetTempID'  => 0,
            'dhwTempID'            => $this->contractFieldID('Warmwasser'),
            'dhwTargetTempID'      => $this->contractFieldID('WarmwasserSoll'),
            'mainInletTempID'      => $this->contractFieldID('Ruecklauftemperatur'),
            'mainOutletTempID'     => $this->contractFieldID('Vorlauftemperatur'),
            'bufferTempID'         => $this->contractFieldID('Speichertemperatur'),
            'quietModeID'          => 0,
            'ecoComfortModeID'     => 0,
            'holidayTimerID'       => 0,
            'operatingModeNormID'  => 0,
            'operatingModeID'      => 0,
            'managedBy'            => 'wpmbhub',
            'lastSeenAt'           => $this->ReadAttributeInteger('LastSeenAt'),
        ]];
    }

    private function contractFieldID(string $ident): int
    {
        $id = @$this->GetIDForIdent($ident);
        return ($id === false) ? 0 : (int)$id;
    }

    private function ensureSharedProfiles(): void
    {
        if (!IPS_VariableProfileExists('NRG.Celsius')) {
            IPS_CreateVariableProfile('NRG.Celsius', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.Celsius', '', ' °C');
            IPS_SetVariableProfileDigits('NRG.Celsius', 1);
        }
    }
}
