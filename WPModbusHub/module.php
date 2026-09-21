<?php

require_once __DIR__ . '/../libs/ModbusTcpClient.php';
require_once __DIR__ . '/../libs/WPMBHUB_Drivers.php';
require_once __DIR__ . '/../libs/WPMBHUB_HeatpumpTrait.php';

// NRG-Stack WPModbusHub -- lokale Modbus-TCP-Anbindung fuer Waermepumpen
// mehrerer Hersteller (NIBE, Stiebel Eltron, LG, Samsung EHS ueber MIM-B19n,
// Waterkotte EcoTouch, IDM Navigatorregelung 2.0).
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
// fuer Float32/Double64/Schreibkanaele) bleibt hier EIN gemeinsames,
// datengetriebenes Registerprofil (DRIVERS-Konstante) statt einer Klasse je
// Hersteller -- die meisten Wärmepumpen-Register sind vorzeichenbehaftete
// 16-Bit-Werte mit Faktor 10, seit IDM (0.3.0) traegt das Schema zusaetzlich
// ein optionales 'type'=>'float32' fuer 32-Bit-IEEE754-Werte ueber 2
// Register (siehe readRegisters()/floatLE()). Sollte ein kuenftiger
// Hersteller eine noch abweichendere Kodierung brauchen (Schreibzugriffe,
// Double64, mehrteilige Strukturen), ist ein Interface nach MeterHub-Vorbild
// weiterhin der vorgesehene naechste Erweiterungspunkt -- ein reiner
// zweiter Datentyp hat dafuer noch nicht ausgereicht.
//
// Vertrag WPHUB_GetFunctions()-kompatibel: Type=>'heatpump', contractVersion
// 1.15, dieselben Feldnamen/Idents wie WPHub (siehe DG65/NRGWPHub) -- EMS/
// Dashboard koennen alle Waermepumpen-Datenquellen identisch behandeln. Stand
// 19.09.2026: Dashboard (WPMonitor/HeatSchema) kennt WPModbusHub,
// WPModbusHubGateway und SamsungEhs per GUID-Liste, EMS ist angefragt (sucht
// bislang nur HeishaMon).
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
    use WPMBHUB_HeatpumpTrait;

    const NEWS_VERSION = '0.7.0';

    // Registerkarten je Hersteller stehen in libs/WPMBHUB_Drivers.php (geteilt mit
    // WPModbusHubGateway); Schema-Beschreibung dort.
    const DRIVERS = WPMBHUB_Drivers::DRIVERS;
    const MANUFACTURER_DEFAULT = WPMBHUB_Drivers::MANUFACTURER_DEFAULT;

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
        // Fuer die Statuszeile im Formular: Zeitpunkt des letzten Lesezyklus (auch
        // erfolglos) und die dabei nicht gelesenen Felder (Idents, Komma-getrennt).
        $this->RegisterAttributeInteger('LastCycleAt', 0);
        $this->RegisterAttributeString('LastMissing', '');
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
        // Die Statuszeile beschreibt den GESPEICHERTEN Hersteller -- folgt sie der
        // Auswahl nicht, zeigt sie Werte eines Profils, das gar nicht mehr gewählt ist.
        if ($manufacturer !== $this->ReadPropertyString('Manufacturer')) {
            $this->UpdateFormField('ConnectionStatus', 'caption', 'ℹ️ Hersteller geändert -- erst nach „Übernehmen“ wird mit dem neuen Profil gelesen.');
            $this->UpdateFormField('ConnectionStatus', 'color', -1);
        } else {
            [$line, $color] = $this->statusLine();
            $this->UpdateFormField('ConnectionStatus', 'caption', $line);
            $this->UpdateFormField('ConnectionStatus', 'color', $color);
        }
    }

    private function statusLine(): array
    {
        return $this->connectionStatusLine(
            $this->ReadPropertyBoolean('WPMBHUB_Active'),
            trim($this->ReadPropertyString('Host')) === '' ? 'keine IP-Adresse eingetragen' : '',
            max(30, $this->ReadPropertyInteger('WPMBHUB_Interval')),
            'IP-Adresse, Port und Unit-ID prüfen.'
        );
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

        [$statusText, $statusColor] = $this->statusLine();
        $this->updateFormElement($form['elements'], 'ConnectionStatus', ['caption' => $statusText, 'color' => $statusColor]);

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
                    ['type' => 'Label', 'caption' => '• Neue Statuszeile im Bereich „Wärmepumpe“: zeigt live, ob die Wärmepumpe antwortet, wie lange die letzte Aktualisierung her ist und welche Werte gerade ankommen -- oder was fehlt.'],
                    ['type' => 'Label', 'caption' => '• IDM: „Warmwasser“ zeigt jetzt den Speicherfühler oben statt der Zapftemperatur, die es nur mit IDMs Warmwasserstation gibt.'],
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
                ['type' => 'Label', 'caption' => 'Fragen, Fehler oder Erfahrungsberichte zu NIBE, Stiebel Eltron, LG, Samsung, Waterkotte, IDM oder Proxon -- dafür gibt es den WPModbusHub-Forumsthread.'],
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
        $this->recordCycle(self::DRIVERS[$manufacturer]['registers'], $values);

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
     * NRG-Stack-Vertrag fuer Waermepumpen, konsistent zu WPHub/HeishaMon
     * (Type=>'heatpump', contractVersion 1.15, dieselben Feldnamen). Eine
     * WPModbusHub-Instanz = eine Waermepumpe, daher immer genau ein Eintrag.
     * PowerID/EnergyID bleiben 0 -- die bisherigen Registerprofile decken
     * nur Temperaturen ab, keine Leistungs-/Energiezaehler (kuenftige
     * Erweiterung, kein WPModbusHub-spezifisches Problem).
     */
    public function GetFunctions()
    {
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        $caption = isset(self::DRIVERS[$manufacturer]) ? self::DRIVERS[$manufacturer]['caption'] : 'Wärmepumpe';
        return $this->buildFunctions($caption);
    }

}
