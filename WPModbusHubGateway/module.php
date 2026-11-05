<?php

require_once __DIR__ . '/../libs/WPMBHUB_Text.php';
require_once __DIR__ . '/../libs/ModbusGatewayClient.php';
require_once __DIR__ . '/../libs/WPMBHUB_Drivers.php';
require_once __DIR__ . '/../libs/WPMBHUB_HeatpumpTrait.php';

// NRG-Stack WPModbusHubGateway -- Waermepumpen ueber Symcons eingebautes
// ModBus-Gateway auslesen, statt ueber eine eigene Modbus-TCP-Socketverbindung
// wie WPModbusHub. Gleiche Herstellerregisterkarten (libs/WPMBHUB_Drivers.php),
// gleicher NRG-Stack-Vertrag, nur der Transport ist ein anderer:
//
//   Serial Port -> ModBus Gateway (Geraete-ID, RTU/TCP) -> WPModbusHubGateway
//
// Damit ist RS485/Modbus RTU direkt an einem seriellen Anschluss moeglich
// (z. B. USB-RS485-Dongle am Symcon-Host), ohne dass dieses Modul selbst einen
// COM-Port oeffnen muss -- das erledigt Symcons Serial-Port-Instanz.
//
// Schnittstelle gegen den Rohcode des offiziellen Symcon-Referenzmoduls
// (symcon/SymconBC, EM24-DIN) verifiziert, siehe libs/ModbusGatewayClient.php.
// module.json: parentRequirements {E310B701-...} (Daten, die wir ans Gateway
// senden), implemented {77B31ABB-...} (Daten, die das Gateway an Kinder
// sendet) -- beides an der Live-IPS aus der Moduldefinition des ModBus
// Gateways ausgelesen und gegen das Referenzmodul gegengeprueft.
//
// ConnectParent() wird bewusst NICHT aufgerufen: es wuerde beim Anlegen
// ungefragt ein neues Gateway erzeugen, obwohl der Nutzer meist schon eines
// betreibt -- der Nutzer waehlt sein Gateway im Instanzformular.
// Bewusst NUR lesend (Function 3/4).

class WPModbusHubGateway extends IPSModule
{
    use WPMBHUB_HeatpumpTrait;

    // Verbund-Konvention "NEWS_VERSIONS" (SUITE.md "Einheitliche Formular-Optik" Punkt 1,
    // Dashboard/Dietmar 23.09.2026, EMS-Weitergabe) -- siehe WPModbusHub/module.php.
    // Die Zeilen sind englische Quelltexte, die deutsche Fassung steht in locale.json.
    const NEWS_VERSIONS = [
        '0.7.1' => [
            'New status line in the "Heat pump" section: it shows live whether a ModBus gateway is connected, whether the heat pump answers, how long ago the last update was and which values are arriving -- or what is missing.',
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
    ];
    private const LIBRARY_GUID = '{E18F40EA-C12C-4D42-9DF0-BFE4F4120B9F}';
    const DRIVERS = WPMBHUB_Drivers::DRIVERS;
    const MANUFACTURER_DEFAULT = WPMBHUB_Drivers::MANUFACTURER_DEFAULT;

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID);
        // Siehe WPModbusHub/module.php: englische Quelltexte, jeder Text fragt Symcon hoechstens einmal je Prozess.
        WPMBHUB_Text::setzen(WPMBHUB_Text::mitMerkliste(function (string $text): string {
            return $this->Translate($text);
        }));
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Manufacturer', self::MANUFACTURER_DEFAULT);
        $this->RegisterPropertyBoolean('WPMBGW_Active', false);
        $this->RegisterPropertyInteger('WPMBGW_Interval', 60);

        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeInteger('LastSeenAt', 0);
        // Fuer die Statuszeile im Formular (siehe WPModbusHub).
        $this->RegisterAttributeInteger('LastCycleAt', 0);
        $this->RegisterAttributeString('LastMissing', '');
        $this->RegisterAttributeString('LastUnavailable', '');
        // Reglerkennung des Geraets (Stiebel: 5002), 0 = unbekannt; fuer den Hinweis bei falsch gewaehlter Karte.
        $this->RegisterAttributeInteger('ControllerCode', 0);
        $this->RegisterAttributeBoolean('ForumHintGone', false);

        $this->RegisterTimer('WPMBGW_UpdateTimer', 0, 'WPMBGW_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->ensureSharedProfiles();

        // Timer laeuft, sobald aktiv -- ein fehlendes Gateway meldet Update()
        // je Zyklus selbst (Status 201), so haengt nichts davon ab, dass IPS
        // beim spaeteren Verbinden des Gateways ApplyChanges nochmal aufruft.
        if (!$this->ReadPropertyBoolean('WPMBGW_Active')) {
            $this->SetTimerInterval('WPMBGW_UpdateTimer', 0);
            $this->SetStatus(104);
            return;
        }
        $interval = max(5, $this->ReadPropertyInteger('WPMBGW_Interval'));
        $this->SetTimerInterval('WPMBGW_UpdateTimer', $interval * 1000);
        $this->SetStatus($this->hasGatewayParent() ? 102 : 201);
    }

    public function OnChangeManufacturer(string $manufacturer): void
    {
        if (!isset(self::DRIVERS[$manufacturer])) {
            return;
        }
        $this->UpdateFormField('ManufacturerConfidence', 'caption', 'ℹ️ ' . WPMBHUB_Text::t(self::DRIVERS[$manufacturer]['confidence']));
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
            $this->ReadPropertyBoolean('WPMBGW_Active'),
            $this->hasGatewayParent() ? '' : WPMBHUB_Text::t('no ModBus gateway connected (choose one at the top under "Gateway")'),
            max(5, $this->ReadPropertyInteger('WPMBGW_Interval')),
            WPMBHUB_Text::t('Check the device ID at the ModBus gateway, baud rate/parity at the Serial Port and the wiring ("Test connection").')
        );
    }

    public function GetConfigurationForm()
    {
        // Formulartexte der form.json sind englische Quelltexte; die dynamisch gebauten Teile unten
        // uebersetzen sich selbst (nicht doppelt uebersetzen).
        $form = WPMBHUB_Text::formular(json_decode(file_get_contents(__DIR__ . '/form.json'), true));

        $libraryInfo = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        $libraryVersion = (is_array($libraryInfo) && isset($libraryInfo['version'])) ? (string)$libraryInfo['version'] : '?';
        $this->updateFormElement($form['elements'], 'VersionInfo', [
            'caption' => WPMBHUB_Text::t('ℹ️ WPModbusHubGateway version %s -- read heat pumps via Symcon\'s ModBus gateway.', $libraryVersion),
        ]);

        $options = [];
        foreach (self::DRIVERS as $key => $driver) {
            $options[] = ['caption' => WPMBHUB_Text::t($driver['caption']), 'value' => $key];
        }
        $this->updateFormElement($form['elements'], 'Manufacturer', ['options' => $options]);

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

    /** Siehe WPModbusHub/module.php::BaseVersion(). */
    private function BaseVersion(string $v): string
    {
        return preg_replace('/-.*$/', '', $v) ?? $v;
    }

    /** Siehe WPModbusHub/module.php::newsBanner(). */
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
        $items[] = ['type' => 'Button', 'caption' => WPMBHUB_Text::t('Understood – do not show again'), 'onClick' => 'WPMBGW_AckNews($id);'];
        $latest = array_key_last($pending);
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => WPMBHUB_Text::t('🆕 New up to version %s', $latest), 'expanded' => true, 'items' => $items];
    }

    public function AckNews(): void
    {
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
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('WPModbusHubGateway reads heat pumps via Symcon\'s built-in ModBus gateway -- the same manufacturer register maps as WPModbusHub, but without its own network connection. Useful when the heat pump is connected via RS485 (Modbus RTU) to a serial port, e.g. a USB-RS485 dongle on the Symcon host.')],
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('Read-only by design (no control commands). The route via the ModBus gateway is so far confirmed on a real system (Proxon T300 via USB-RS485), not yet for the other manufacturers via this route -- feedback is very welcome, see the note below.')],
                ['type' => 'Button', 'caption' => WPMBHUB_Text::t('Understood – do not show again'), 'onClick' => 'WPMBGW_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro(): void
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-wpmodbushub-lokale-modbus-anbindung-fuer-waermepumpen-mehrerer-hersteller-nibe-stiebel-eltron-lg-samsung/144421';

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
                ['type' => 'Label', 'caption' => WPMBHUB_Text::t('Questions, bugs or experience reports -- the WPModbusHub forum thread is the place for that (this also applies to this gateway variant).')],
                ['type' => 'Button', 'caption' => WPMBHUB_Text::t('To the forum thread'), 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => WPMBHUB_Text::t('Understood – do not show again'), 'onClick' => 'WPMBGW_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

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
        if (!$this->ReadPropertyBoolean('WPMBGW_Active')) {
            return;
        }
        if (!$this->hasGatewayParent()) {
            $this->SetStatus(201);
            return;
        }
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        if (!isset(self::DRIVERS[$manufacturer])) {
            $this->LogMessage(WPMBHUB_Text::t('Unknown manufacturer "%s".', $manufacturer), KL_WARNING);
            return;
        }
        $client = $this->gatewayClient();
        $values = $this->readRegisters(self::DRIVERS[$manufacturer]['registers'], $client);
        if ($values !== null) {
            $this->readControllerCode($client, self::DRIVERS[$manufacturer]);
        }
        $this->recordCycle(self::DRIVERS[$manufacturer]['registers'], $values);

        $reachable = ($values !== null && count($values) > 0);
        $this->maintainDeviceVariables($values ?? [], $reachable);
        if ($reachable) {
            $this->WriteAttributeInteger('LastSeenAt', time());
        }

        $this->SetStatus($reachable ? 102 : 202);
        if (!$reachable) {
            $this->LogMessage(WPMBHUB_Text::t('Heat pump not reachable via the ModBus gateway (%s) -- check device ID, baud rate/parity and wiring.', $client->lastError), KL_WARNING);
        }
    }

    /**
     * Ein einzelnes Testregister des gewaehlten Herstellers lesen und den
     * kompletten Ablauf (Anfrage, rohe Antwort, Ergebnis) als Text zurueckgeben
     * -- fuer den Knopf "Verbindung testen" und als Diagnose bei der ersten
     * Inbetriebnahme. Rein lesend.
     */
    public function TestConnection(): string
    {
        if (!$this->hasGatewayParent()) {
            return WPMBHUB_Text::t('❌ No ModBus gateway connected.') . "\n" . WPMBHUB_Text::t('Choose a ModBus gateway at the top under "Gateway" (Symcon object tree: Serial Port → ModBus Gateway).');
        }
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        if (!isset(self::DRIVERS[$manufacturer])) {
            return WPMBHUB_Text::t('❌ Unknown manufacturer "%s".', $manufacturer);
        }
        $registers = self::DRIVERS[$manufacturer]['registers'];
        $ident = isset($registers['Aussentemperatur']) ? 'Aussentemperatur' : array_key_first($registers);
        $def = $registers[$ident];
        $function = ($def['regType'] === 'holding') ? 3 : 4;
        $count = (($def['type'] ?? 'int16') === 'float32') ? 2 : 1;

        $client = $this->gatewayClient();
        $values = $this->readRegisters([$ident => $def], $client);

        $lines = [];
        $lines[] = WPMBHUB_Text::t('Manufacturer: %s', WPMBHUB_Text::t(self::DRIVERS[$manufacturer]['caption']));
        $lines[] = WPMBHUB_Text::t('Test register: %s, address %d (function %d, %d register(s))', $ident, $def['addr'], $function, $count);
        $lines[] = WPMBHUB_Text::t('Request: %s', $client->lastRequest);
        if ($client->lastResponseLen > 0) {
            $lines[] = WPMBHUB_Text::t('Answer: 0x%s (%d bytes, header matches function and byte count: %s)', $client->lastResponseHex, $client->lastResponseLen, $client->headerLooksLikePdu ? WPMBHUB_Text::t('yes') : WPMBHUB_Text::t('no'));
        } else {
            $lines[] = WPMBHUB_Text::t('Answer: none');
        }
        if ($values !== null && array_key_exists($ident, $values)) {
            $lines[] = WPMBHUB_Text::t('✅ Result: %s = %s', $ident, round($values[$ident], 3));
            $lines[] = WPMBHUB_Text::t('Plausible? If not, check scaling/address of the register map and give feedback in the forum.');
        } else {
            $lines[] = WPMBHUB_Text::t('❌ Error: %s', $client->lastError);
            $lines[] = WPMBHUB_Text::t('Check: device ID in the ModBus gateway (gateway mode RTU for RS485), baud rate/parity/stop bits in the Serial Port, A/B wires, gateway and Serial Port active.');
        }
        $report = implode("\n", $lines);
        $this->SendDebug('TestConnection', $report, 0);
        return $report;
    }

    /**
     * Rohe Registerabfrage fuer Tests und Skripte, z. B.
     *   echo WPMBGW_ReadRaw($id, 4, 882, 1);
     * Liefert Anfrage-Ergebnis als Text (unsigned und signed je Register,
     * dazu die rohe Antwort in Hex). NUR Function 3 (Holding) und 4 (Input),
     * rein lesend, hoechstens 16 Register.
     */
    public function ReadRaw(int $Function, int $Address, int $Quantity = 1): string
    {
        if (!in_array($Function, [3, 4], true)) {
            return WPMBHUB_Text::t('Only function 3 (holding registers) and 4 (input registers) are allowed -- this module only reads.');
        }
        if (!$this->hasGatewayParent()) {
            return WPMBHUB_Text::t('No ModBus gateway connected.');
        }
        $Quantity = max(1, min(16, $Quantity));
        $client = $this->gatewayClient();
        $regs = ($Function === 3) ? $client->readHolding($Address, $Quantity) : $client->readInput($Address, $Quantity);
        if ($regs === null) {
            return WPMBHUB_Text::t('Error: %s', $client->lastError) . ($client->lastResponseLen > 0 ? ' ' . WPMBHUB_Text::t('(answer 0x%s)', $client->lastResponseHex) : '');
        }
        $unsigned = [];
        $signed = [];
        foreach ($regs as $i => $v) {
            $unsigned[] = $client->u16($regs, $i);
            $signed[] = $client->s16($regs, $i);
        }
        return WPMBHUB_Text::t('Function %d, from address %d: unsigned [%s], signed [%s], answer 0x%s', $Function, $Address, implode(', ', $unsigned), implode(', ', $signed), $client->lastResponseHex);
    }

    /**
     * NRG-Stack-Vertrag fuer Waermepumpen -- identisch zu WPModbusHub (geteilter
     * Aufbau in WPMBHUB_HeatpumpTrait::buildFunctions()).
     */
    public function GetFunctions()
    {
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        $caption = WPMBHUB_Text::t(isset(self::DRIVERS[$manufacturer]) ? self::DRIVERS[$manufacturer]['caption'] : 'Heat pump');
        return $this->buildFunctions($caption);
    }

    private function hasGatewayParent(): bool
    {
        $inst = @IPS_GetInstance($this->InstanceID);
        $parent = is_array($inst) ? (int)($inst['ConnectionID'] ?? 0) : 0;
        return $parent > 0 && @IPS_InstanceExists($parent);
    }

    /**
     * SendDataToParent() ist protected -- der Client bekommt deshalb eine
     * Closure (Muster MeterHub). Wirft/warnt das Gateway (Timeout, inaktiv),
     * liefert die Closure false statt den Zyklus abzubrechen.
     */
    private function gatewayClient(): WPMBHUB_ModbusGatewayClient
    {
        return new WPMBHUB_ModbusGatewayClient(function (string $json) {
            try {
                return @$this->SendDataToParent($json);
            } catch (\Throwable $e) {
                return false;
            }
        });
    }
}
