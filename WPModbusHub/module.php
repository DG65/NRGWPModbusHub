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
            'Stiebel Eltron: new values source temperature (brine/water units), heat energy produced and electrical energy consumed for heating and hot water as totals in kWh (kWh and MWh part added up), operating mode and, on the LWZ, compressor starts. The energy values are the ISG\'s own estimate, not a meter reading.',
            'The form warns if the controller code of the Stiebel Eltron device does not fit the selected card. Fields that a device does not offer (e.g. the source temperature of an air unit) no longer count as read errors.',
        ],
        '0.9.1' => [
            'The update interval can now be set down to 5 seconds. A device that cannot be reached no longer holds up the read cycle: it ends after the first connection error instead of waiting 3 seconds per field.',
            '"Heating zone" is now called "Heating circuit" (for newly created variables).',
            'Stiebel Eltron (heat pumps with WPM): the room setpoints comfort and ECO temperature of heating circuit 1 can be read and changed. They stay locked until you have read and confirmed the notice in the form under "Change settings on the device". Writing is not yet tested on real hardware.',
        ],
        '0.9.2' => [
            'Stiebel Eltron (heat pumps with WPM): the setpoints can now be set in steps of 0.5 °C (before: whole degrees only, so 21.5 °C became 22 °C). New: the comfort and ECO temperature of hot water can be read and changed as well, after the same notice. Writing the room setpoints was confirmed on a real heat pump, the hot water values are new.',
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
        // Belehrung zum Schreiben (Stiebel-Raumsollwerte) vom Nutzer im Formular bestaetigt; Attribut, nie Property.
        $this->RegisterAttributeBoolean('WriteNoticeAccepted', false);
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
        $interval = max(5, $this->ReadPropertyInteger('WPMBHUB_Interval'));
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

    /** Dieses Modul kann schreibbare Sollwerte anbieten (nur der eigene TCP-Weg, nicht das ModBus-Gateway). */
    private function writeSupported(): bool
    {
        return true;
    }

    private const WRITE_MIN_INTERVAL_S = 30;

    /** Gibt es beim gewaehlten Hersteller schreibbare Felder? Nur dann zeigt das Formular die Belehrung. */
    private function hasWritableFields(string $manufacturer): bool
    {
        foreach ((self::DRIVERS[$manufacturer]['registers'] ?? []) as $def) {
            if (isset($def['write'])) {
                return true;
            }
        }
        return false;
    }

    /** Panel "Einstellungen am Geraet aendern" mit der Belehrung; nur bei Herstellern mit schreibbaren Feldern. */
    private function writeNoticePanel(): ?array
    {
        if (!$this->hasWritableFields($this->ReadPropertyString('Manufacturer'))) {
            return null;
        }
        $accepted = $this->ReadAttributeBoolean('WriteNoticeAccepted');
        return [
            'type'     => 'ExpansionPanel',
            'name'     => 'WriteNoticePanel',
            'expanded' => !$accepted,
            'caption'  => WPMBHUB_Text::t('⚙️ Change settings on the device'),
            'items'    => [
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('⚠️ Reading is harmless, writing changes the settings of your heat pump controller. After you confirm this notice, the four setpoints (comfort and ECO temperature of heating circuit 1 and of hot water) are available and can be changed in Symcon in steps of 0.5 °C.')],
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('Please note: A wrong or constantly changing value affects comfort and energy consumption, and the controller stores settings permanently. Do not write them at short intervals or from automations that run every few seconds. The module writes at most once every 30 seconds per value, checks the range (5 to 30 °C) and reads the value back. You use this function at your own risk; the notes of the manufacturer apply. Writing is not yet tested on real hardware.')],
                ['type' => 'Label', 'name' => 'WriteNoticeState', 'caption' => $accepted ? WPMBHUB_Text::t('✅ Writing is enabled.') : WPMBHUB_Text::t('🔒 Writing is locked.')],
                ['type' => 'Button', 'caption' => WPMBHUB_Text::t('I have read and understood this -- enable writing'), 'confirm' => WPMBHUB_Text::t('Really enable writing of the setpoints on this device?'), 'onClick' => 'WPMBHUB_AcceptWriteNotice($id);'],
                ['type' => 'Button', 'caption' => WPMBHUB_Text::t('Lock writing again'), 'onClick' => 'WPMBHUB_RevokeWriteNotice($id);'],
            ],
        ];
    }

    public function AcceptWriteNotice(): void
    {
        $this->WriteAttributeBoolean('WriteNoticeAccepted', true);
        $this->LogMessage(WPMBHUB_Text::t('Writing of the setpoints was enabled by the user in the form.'), KL_NOTIFY);
        $this->UpdateFormField('WriteNoticeState', 'caption', WPMBHUB_Text::t('✅ Writing is enabled.'));
        // Die beiden Variablen sofort anlegen, nicht erst mit dem naechsten Intervall.
        $this->Update();
    }

    public function RevokeWriteNotice(): void
    {
        $this->WriteAttributeBoolean('WriteNoticeAccepted', false);
        $this->LogMessage(WPMBHUB_Text::t('Writing of the setpoints was locked again by the user.'), KL_NOTIFY);
        $this->UpdateFormField('WriteNoticeState', 'caption', WPMBHUB_Text::t('🔒 Writing is locked.'));
        // Die vorhandenen Variablen bleiben (gehoeren dem Nutzer), verlieren aber ihre Bedienbarkeit und werden nicht mehr gelesen.
        foreach ((self::DRIVERS[$this->ReadPropertyString('Manufacturer')]['registers'] ?? []) as $ident => $def) {
            if (isset($def['write']) && @$this->GetIDForIdent($ident) !== false) {
                $this->DisableAction($ident);
            }
        }
    }

    /**
     * Aenderung eines schreibbaren Sollwerts (Variable mit Aktion). Nur mit bestaetigter Belehrung, nur Felder mit
     * 'write' in der Registerkarte, Bereich und Schrittweite aus der Karte, hoechstens alle 30 s je Wert, danach
     * Zuruecklesen: angezeigt wird, was das Geraet wirklich meldet.
     */
    public function RequestAction($Ident, $Value)
    {
        $ident = (string)$Ident;
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        $def = self::DRIVERS[$manufacturer]['registers'][$ident] ?? null;
        if ($def === null || !isset($def['write'])) {
            throw new Exception(WPMBHUB_Text::t('"%s" cannot be changed.', $ident));
        }
        if (!$this->ReadAttributeBoolean('WriteNoticeAccepted')) {
            throw new Exception(WPMBHUB_Text::t('Writing is locked. Confirm the notice first in the form under "Change settings on the device".'));
        }
        if (!is_numeric($Value)) {
            throw new Exception(WPMBHUB_Text::t('"%s" is not a number.', (string)$Value));
        }
        $w = $def['write'];
        $target = (float)$Value;
        if ($target < $w['min'] || $target > $w['max']) {
            throw new Exception(WPMBHUB_Text::t('The value %s is outside the allowed range %s to %s.', $target, $w['min'], $w['max']));
        }
        $target = round($target / $w['step']) * $w['step'];
        if (time() - (int)$this->GetBuffer('LastWrite_' . $ident) < self::WRITE_MIN_INTERVAL_S) {
            throw new Exception(WPMBHUB_Text::t('Please wait: at most one change every %d seconds per value.', self::WRITE_MIN_INTERVAL_S));
        }
        $host = trim($this->ReadPropertyString('Host'));
        if ($host === '') {
            throw new Exception(WPMBHUB_Text::t('no IP address entered'));
        }
        $client = new WPMBHUB_ModbusTcpClient($host, $this->ReadPropertyInteger('Port'), $this->ReadPropertyInteger('UnitId'));
        $scale = $def['scale'] ?? 1;
        $written = $client->writeHolding($def['addr'], (int)round($target * $scale));
        $error = $written ? '' : $this->describeModbusError($client);
        $back = $written ? $client->readHolding($def['addr'], 1) : null;
        $client->close();
        if (!$written) {
            $this->SendDebug('Modbus', WPMBHUB_Text::t('%s %s register %d: %s', $ident, $def['regType'], $def['addr'], $error), 0);
            throw new Exception(WPMBHUB_Text::t('Writing failed: %s', $error));
        }
        $this->SetBuffer('LastWrite_' . $ident, (string)time());
        if ($back === null) {
            throw new Exception(WPMBHUB_Text::t('The value was written, but reading it back failed. The displayed value is updated with the next read cycle.'));
        }
        $actual = $client->s16($back, 0) / $scale;
        $this->SetValue($ident, (float)$actual);
        $this->LogMessage(WPMBHUB_Text::t('Setpoint "%s" changed to %s °C by user action (the device reports %s °C).', WPMBHUB_Text::t(WPMBHUB_Drivers::FIELD_CAPTIONS[$ident] ?? $ident), $target, $actual), KL_NOTIFY);
        if (abs($actual - $target) > 0.05) {
            throw new Exception(WPMBHUB_Text::t('The device did not take over the value (it reports %s °C).', $actual));
        }
    }

    private function statusLine(): array
    {
        return $this->connectionStatusLine(
            $this->ReadPropertyBoolean('WPMBHUB_Active'),
            trim($this->ReadPropertyString('Host')) === '' ? WPMBHUB_Text::t('no IP address entered') : '',
            max(5, $this->ReadPropertyInteger('WPMBHUB_Interval')),
            WPMBHUB_Text::t('Check IP address, port and unit ID.')
        );
    }

    public function GetConfigurationForm()
    {
        // Formulartexte der form.json sind englische Quelltexte; die dynamisch gebauten Teile unten
        // uebersetzen sich selbst (nicht doppelt uebersetzen).
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

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

        $writeNotice = $this->writeNoticePanel();
        if ($writeNotice !== null) {
            $form['elements'][] = $writeNotice;
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
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('Read-only by default. Only the setpoints of Stiebel Eltron heat pumps (room temperature and hot water, comfort and ECO) can be changed, and only after you confirm a notice in the form. Not every manufacturer is verified on real hardware yet -- the register maps come from official manufacturer documents or actively maintained reference integrations, see the note at the selected manufacturer.')],
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
        $values = $this->readRegisters($this->activeRegisters($manufacturer), $client);
        if ($values !== null) {
            $this->readControllerCode($client, self::DRIVERS[$manufacturer]);
        }
        $client->close();
        $this->recordCycle($this->activeRegisters($manufacturer), $values);

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
