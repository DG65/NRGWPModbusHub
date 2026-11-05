<?php

require_once __DIR__ . '/../libs/WPMBHUB_Text.php';
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

    // Verbund-Konvention "NEWS_VERSIONS" (SUITE.md "Einheitliche Formular-Optik" Punkt 1,
    // Dashboard/Dietmar 23.09.2026, EMS-Weitergabe): Schluessel = Version ohne Beta-/Build-
    // Suffix, Wert = Zeilen wie bisher NEWS_ITEMS. newsBanner()/AckNews() zeigen bzw. merken
    // nur die Luecke seit der zuletzt bestaetigten Version (Attribut SeenNews), nicht mehr
    // nur "stimmt die aktuelle Version noch mit dem letzten Eintrag ueberein".
    // Die Zeilen sind englische Quelltexte, die deutsche Fassung steht in locale.json.
    const NEWS_VERSIONS = [
        '0.7.1' => [
            'New status line in the "Heat pump" section: it shows live whether the heat pump answers, how long ago the last update was and which values are arriving -- or what is missing.',
            'IDM: "Hot water" now shows the tank sensor at the top instead of the tap temperature, which only exists with IDM\'s hot water station; new: "Hot water bottom" (tank sensor at the bottom).',
        ],
        '0.7.4' => [
            'Logging in the Symcon archive is no longer switched on automatically. In the "General" section there is the button "Enable logging for the measured values", so you enable it deliberately (Symcon review). Logging that is already enabled stays untouched.',
        ],
        '0.7.6' => [
            'If the heat pump does not answer, the cause is now shown per register in the instance debug output (no connection, timeout or an exception of the device, e.g. "address not available"). Before, every error was just "does not answer".',
        ],
        '0.7.7' => [
            'Stiebel Eltron: the register addresses were wrong (the ISG answered with "address not available"). The addresses from the Stiebel PDF minus 1 now apply; the return temperature is new. Values that do not exist (substitute value 0x8000) are no longer shown as a temperature.',
        ],
        '0.8.0' => [
            'The module is now fully bilingual (German/English): form, status messages, notes and the names of new variables follow the Symcon language.',
        ],
        '0.9.0' => [
            'Stiebel Eltron: new card for integral ventilation units (LWZ/LWA). Their registers differ from the heat pumps with WPM, so the former single card read wrong addresses for them. If you own an LWZ, choose "Stiebel Eltron (ISG gateway, integral ventilation units LWZ/LWA)".',
            'Stiebel Eltron: new values source temperature (brine/water units), heat quantity and electrical energy for heating and hot water as totals in kWh (kWh and MWh part added up), operating mode and, on the LWZ, compressor starts. The energy values are the ISG\'s own estimate, not a meter reading.',
            'The form warns if the controller code of the Stiebel Eltron device does not fit the selected card. Fields that a device does not offer (e.g. the source temperature of an air unit) no longer count as read errors.',
        ],
    ];
    private const LIBRARY_GUID = '{E18F40EA-C12C-4D42-9DF0-BFE4F4120B9F}';

    // Registerkarten je Hersteller stehen in libs/WPMBHUB_Drivers.php (geteilt mit
    // WPModbusHubGateway); Schema-Beschreibung dort.
    const DRIVERS = WPMBHUB_Drivers::DRIVERS;
    const MANUFACTURER_DEFAULT = WPMBHUB_Drivers::MANUFACTURER_DEFAULT;

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Trait, Treiberkarten und Clients uebersetzen ueber WPMBHUB_Text (englische Quelltexte, Deutsch in
        // locale.json); jeder Text fragt Symcon hoechstens einmal je Prozess (SUITE.md Stolperstein 24).
        WPMBHUB_Text::setzen(WPMBHUB_Text::mitMerkliste(function (string $text): string {
            return $this->Translate($text);
        }));
    }

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
        $this->RegisterAttributeString('LastUnavailable', '');
        // Reglerkennung des Geraets (Stiebel: 5002), 0 = unbekannt; fuer den Hinweis bei falsch gewaehlter Karte.
        $this->RegisterAttributeInteger('ControllerCode', 0);
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
        $this->UpdateFormField('ManufacturerConfidence', 'caption', 'ℹ️ ' . WPMBHUB_Text::t($driver['confidence']));
        // Die Statuszeile beschreibt den GESPEICHERTEN Hersteller -- folgt sie der
        // Auswahl nicht, zeigt sie Werte eines Profils, das gar nicht mehr gewählt ist.
        if ($manufacturer !== $this->ReadPropertyString('Manufacturer')) {
            $this->UpdateFormField('ConnectionStatus', 'caption', WPMBHUB_Text::t('ℹ️ Manufacturer changed -- the new profile is only used for reading after "Apply".'));
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
            trim($this->ReadPropertyString('Host')) === '' ? WPMBHUB_Text::t('no IP address entered') : '',
            max(30, $this->ReadPropertyInteger('WPMBHUB_Interval')),
            WPMBHUB_Text::t('Check IP address, port and unit ID.')
        );
    }

    public function GetConfigurationForm()
    {
        // Formulartexte der form.json sind englische Quelltexte; die dynamisch gebauten Teile unten
        // uebersetzen sich selbst (nicht doppelt uebersetzen).
        $form = WPMBHUB_Text::formular(json_decode(file_get_contents(__DIR__ . '/form.json'), true));

        $options = [];
        foreach (self::DRIVERS as $key => $driver) {
            $options[] = ['caption' => WPMBHUB_Text::t($driver['caption']), 'value' => $key];
        }
        $this->updateFormElement($form['elements'], 'Manufacturer', ['options' => $options]);

        $libraryInfo = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        $libraryVersion = (is_array($libraryInfo) && isset($libraryInfo['version'])) ? (string)$libraryInfo['version'] : '?';
        $this->updateFormElement($form['elements'], 'VersionInfo', [
            'caption' => WPMBHUB_Text::t('ℹ️ WPModbusHub version %s -- local Modbus connection for heat pumps from several manufacturers.', $libraryVersion),
        ]);

        $manufacturer = $this->ReadPropertyString('Manufacturer');
        if (isset(self::DRIVERS[$manufacturer])) {
            $this->updateFormElement($form['elements'], 'ManufacturerConfidence', [
                'caption' => 'ℹ️ ' . WPMBHUB_Text::t(self::DRIVERS[$manufacturer]['confidence']),
            ]);
        }

        [$statusText, $statusColor] = $this->statusLine();
        $this->updateFormElement($form['elements'], 'ConnectionStatus', ['caption' => $statusText, 'color' => $statusColor]);

        $purposeIntro = $this->PurposeIntro();
        if ($purposeIntro !== null) {
            array_unshift($form['elements'], $purposeIntro);
        }
        $newsBanner = $this->newsBanner();
        if ($newsBanner !== null) {
            array_unshift($form['elements'], $newsBanner);
        }

        $forumHint = $this->ForumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }

        $form['elements'][] = $this->LicenseHint();

        return json_encode($form);
    }

    /** Reine Versionszahl ohne Beta-/Build-Zusatz ("0.7.1-beta.1" -> "0.7.1") --
     *  Vergleichsbasis fuer NEWS_VERSIONS (SUITE.md "Einheitliche Formular-Optik"
     *  Punkt 1, Referenz NRGDashboard). */
    private function BaseVersion(string $v): string
    {
        return preg_replace('/-.*$/', '', $v) ?? $v;
    }

    /**
     * "Was ist Neu"-Banner: zeigt nur die Versionen, die NEUER sind als die zuletzt
     * bestaetigte (Attribut SeenNews), gruppiert nach Version. NULL, wenn nichts
     * Neues seit der zuletzt gesehenen Version ansteht.
     */
    private function newsBanner(): ?array
    {
        $seen = (string) $this->ReadAttributeString('SeenNews');
        $pending = [];
        foreach (self::NEWS_VERSIONS as $ver => $lines) {
            if ($seen === '' || version_compare($ver, $seen, '>')) {
                $pending[$ver] = $lines;
            }
        }
        if (count($pending) === 0) {
            return null;
        }
        uksort($pending, 'version_compare');
        $items = [];
        $multi = count($pending) > 1;
        foreach ($pending as $ver => $lines) {
            if ($multi) {
                $items[] = ['type' => 'Label', 'caption' => WPMBHUB_Text::t('Version %s:', $ver)];
            }
            foreach ($lines as $line) {
                $items[] = ['type' => 'Label', 'caption' => '• ' . WPMBHUB_Text::t($line)];
            }
        }
        $items[] = ['type' => 'Button', 'caption' => WPMBHUB_Text::t('Understood – do not show again'), 'onClick' => 'WPMBHUB_AckNews($id);'];
        $latest = array_key_last($pending);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => WPMBHUB_Text::t('🆕 New up to version %s', $latest), 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
        // Die tatsaechlich installierte Bibliotheksversion merken, nicht nur den
        // letzten NEWS_VERSIONS-Schluessel -- ein spaeteres Update mit neuem Eintrag
        // zeigt den Banner dann sicher wieder, auch ohne Eintrag dazwischen.
        $lib = @IPS_GetLibrary(self::LIBRARY_GUID);
        $ver = is_array($lib) ? $this->BaseVersion((string) ($lib['Version'] ?? '')) : '';
        if ($ver === '') {
            $ver = (string) array_key_last(self::NEWS_VERSIONS);
        }
        $this->WriteAttributeString('SeenNews', $ver);
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
            'caption'  => WPMBHUB_Text::t('👋  What is this module for?'),
            'items'    => [
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('WPModbusHub reads heat pumps from several manufacturers directly via Modbus TCP on the local network -- without internet, without a manufacturer account. It complements WPHub (manufacturer clouds) and HeishaMon (Panasonic, local) with manufacturers that have a built-in or retrofittable Modbus interface.')],
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('Read-only by design (no control commands). Not every manufacturer is verified on real hardware yet -- the register maps come from official manufacturer documents or actively maintained reference integrations, see the note at the selected manufacturer.')],
                ['type' => 'Button', 'caption' => WPMBHUB_Text::t('Understood – do not show again'), 'onClick' => 'WPMBHUB_AckPurposeIntro($id);'],
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
            'caption'  => WPMBHUB_Text::t('💬  Feedback in the Symcon forum'),
            'items'    => [
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('Questions, bugs or experience reports on NIBE, Stiebel Eltron, LG, Samsung, Waterkotte, IDM or Proxon -- the WPModbusHub forum thread is the place for that.')],
                ['type' => 'Button', 'caption' => WPMBHUB_Text::t('To the forum thread'), 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => WPMBHUB_Text::t('Understood – do not show again'), 'onClick' => 'WPMBHUB_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    // Seit 27.10.2026 ist main der Release-Branch (beta ist identisch, ems-integration abgeschafft).
    private const LICENSE_URL = 'https://github.com/DG65/NRGWPModbusHub/blob/main/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';

    private function LicenseHint(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'expanded' => false,
            'caption'  => WPMBHUB_Text::t('🧡  About this module'),
            'items'    => [
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('Born out of genuine enthusiasm for my own installation — and a few evenings of typing. Still: hobby software or not, this is intellectual property and real work went into it.')],
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('License: PolyForm Noncommercial 1.0.0 — free for private and non-commercial use; commercial use needs a separate license from the rights holder.')],
                ['type' => 'Button', 'caption' => WPMBHUB_Text::t('View license text'), 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('Commercial use or questions about the license? Just get in touch: dietmar@gureth.eu')],
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('Do you like the module and still want to leave something? I would be happy about a small donation — completely voluntary, nothing owed in return.')],
                ['type' => 'Button', 'caption' => WPMBHUB_Text::t('☕  Donate via PayPal'), 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
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
            $this->LogMessage(WPMBHUB_Text::t('Unknown manufacturer "%s".', $manufacturer), KL_WARNING);
            return;
        }
        $client = new WPMBHUB_ModbusTcpClient($host, $this->ReadPropertyInteger('Port'), $this->ReadPropertyInteger('UnitId'));
        $values = $this->readRegisters(self::DRIVERS[$manufacturer]['registers'], $client);
        if ($values !== null) {
            $this->readControllerCode($client, self::DRIVERS[$manufacturer]);
        }
        $client->close();
        $this->recordCycle(self::DRIVERS[$manufacturer]['registers'], $values);

        $reachable = ($values !== null && count($values) > 0);
        $this->maintainDeviceVariables($values ?? [], $reachable);
        if ($reachable) {
            $this->WriteAttributeInteger('LastSeenAt', time());
        }

        $this->SetStatus($reachable ? 102 : 201);
        if (!$reachable) {
            $this->LogMessage(WPMBHUB_Text::t('Heat pump not reachable (%s:%d).', $host, $this->ReadPropertyInteger('Port')), KL_WARNING);
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
        $caption = WPMBHUB_Text::t(isset(self::DRIVERS[$manufacturer]) ? self::DRIVERS[$manufacturer]['caption'] : 'Heat pump');
        return $this->buildFunctions($caption);
    }

}
