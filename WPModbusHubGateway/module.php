<?php

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

    const NEWS_VERSION = '0.5.0';
    const DRIVERS = WPMBHUB_Drivers::DRIVERS;
    const MANUFACTURER_DEFAULT = WPMBHUB_Drivers::MANUFACTURER_DEFAULT;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Manufacturer', self::MANUFACTURER_DEFAULT);
        $this->RegisterPropertyBoolean('WPMBGW_Active', false);
        $this->RegisterPropertyInteger('WPMBGW_Interval', 60);

        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeInteger('LastSeenAt', 0);
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
        $interval = max(30, $this->ReadPropertyInteger('WPMBGW_Interval'));
        $this->SetTimerInterval('WPMBGW_UpdateTimer', $interval * 1000);
        $this->SetStatus($this->hasGatewayParent() ? 102 : 201);
    }

    public function OnChangeManufacturer(string $manufacturer): void
    {
        if (!isset(self::DRIVERS[$manufacturer])) {
            return;
        }
        $this->UpdateFormField('ManufacturerConfidence', 'caption', 'ℹ️ ' . self::DRIVERS[$manufacturer]['confidence']);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $libraryInfo = @json_decode((string)@file_get_contents(__DIR__ . '/../library.json'), true);
        $libraryVersion = (is_array($libraryInfo) && isset($libraryInfo['version'])) ? (string)$libraryInfo['version'] : '?';
        $this->updateFormElement($form['elements'], 'VersionInfo', [
            'caption' => 'ℹ️ WPModbusHubGateway Version ' . $libraryVersion . ' -- Wärmepumpen über Symcons ModBus-Gateway auslesen.',
        ]);

        $options = [];
        foreach (self::DRIVERS as $key => $driver) {
            $options[] = ['caption' => $driver['caption'], 'value' => $key];
        }
        $this->updateFormElement($form['elements'], 'Manufacturer', ['options' => $options]);

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
                    ['type' => 'Label', 'caption' => '• Erste Version: Wärmepumpen über Symcons ModBus-Gateway auslesen -- damit geht auch RS485/Modbus RTU an einem seriellen Anschluss, z. B. Proxon per USB-RS485-Dongle.'],
                    ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'WPMBGW_AckNews($id);'],
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
                ['type' => 'Label', 'caption' => 'WPModbusHubGateway liest Wärmepumpen über Symcons eingebautes ModBus-Gateway aus -- dieselben Herstellerregisterkarten wie WPModbusHub, aber ohne eigene Netzwerkverbindung. Sinnvoll, wenn die Wärmepumpe per RS485 (Modbus RTU) an einem seriellen Anschluss hängt, z. B. einem USB-RS485-Dongle am Symcon-Host.'],
                ['type' => 'Label', 'caption' => 'Bewusst nur lesend (keine Steuerbefehle) und Stand heute an keiner echten Anlage über diesen Weg verifiziert -- Rückmeldungen sind sehr willkommen, siehe Hinweis unten.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'WPMBGW_AckPurposeIntro($id);'],
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
            'caption'  => '💬  Feedback im Symcon-Forum',
            'items'    => [
                ['type' => 'Label', 'caption' => 'Fragen, Fehler oder Erfahrungsberichte -- dafür gibt es den WPModbusHub-Forumsthread (gilt auch für diese Gateway-Variante).'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'WPMBGW_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

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
        if (!$this->ReadPropertyBoolean('WPMBGW_Active')) {
            return;
        }
        if (!$this->hasGatewayParent()) {
            $this->SetStatus(201);
            return;
        }
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        if (!isset(self::DRIVERS[$manufacturer])) {
            $this->LogMessage('Unbekannter Hersteller "' . $manufacturer . '".', KL_WARNING);
            return;
        }
        $client = $this->gatewayClient();
        $values = $this->readRegisters(self::DRIVERS[$manufacturer]['registers'], $client);

        $reachable = ($values !== null && count($values) > 0);
        $this->maintainDeviceVariables($values ?? [], $reachable);
        if ($reachable) {
            $this->WriteAttributeInteger('LastSeenAt', time());
        }

        $this->SetStatus($reachable ? 102 : 202);
        if (!$reachable) {
            $this->LogMessage('Wärmepumpe über das ModBus-Gateway nicht erreichbar (' . $client->lastError . ') -- Geräte-ID, Baudrate/Parität und Verkabelung prüfen.', KL_WARNING);
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
            return "❌ Kein ModBus-Gateway verbunden.\nOben unter „Gateway“ einen ModBus-Gateway wählen (Symcon-Objektbaum: Serial Port → ModBus Gateway).";
        }
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        if (!isset(self::DRIVERS[$manufacturer])) {
            return '❌ Unbekannter Hersteller "' . $manufacturer . '".';
        }
        $registers = self::DRIVERS[$manufacturer]['registers'];
        $ident = isset($registers['Aussentemperatur']) ? 'Aussentemperatur' : array_key_first($registers);
        $def = $registers[$ident];
        $function = ($def['regType'] === 'holding') ? 3 : 4;
        $count = (($def['type'] ?? 'int16') === 'float32') ? 2 : 1;

        $client = $this->gatewayClient();
        $values = $this->readRegisters([$ident => $def], $client);

        $lines = [];
        $lines[] = 'Hersteller: ' . self::DRIVERS[$manufacturer]['caption'];
        $lines[] = 'Testregister: ' . $ident . ', Adresse ' . $def['addr'] . ' (Function ' . $function . ', ' . $count . ' Register)';
        $lines[] = 'Anfrage: ' . $client->lastRequest;
        if ($client->lastResponseLen > 0) {
            $lines[] = 'Antwort: 0x' . $client->lastResponseHex . ' (' . $client->lastResponseLen . ' Byte, Kopf passt zu Function+Bytezahl: ' . ($client->headerLooksLikePdu ? 'ja' : 'nein') . ')';
        } else {
            $lines[] = 'Antwort: keine';
        }
        if ($values !== null && array_key_exists($ident, $values)) {
            $lines[] = '✅ Ergebnis: ' . $ident . ' = ' . round($values[$ident], 3);
            $lines[] = 'Plausibel? Sonst Skalierung/Adresse der Registerkarte prüfen und Rückmeldung im Forum geben.';
        } else {
            $lines[] = '❌ Fehler: ' . $client->lastError;
            $lines[] = 'Prüfen: Geräte-ID im ModBus-Gateway (Gateway-Modus RTU für RS485), Baudrate/Parität/Stoppbits im Serial Port, A/B-Leitungen, Gateway und Serial Port aktiv.';
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
            return 'Nur Function 3 (Holding-Register) und 4 (Input-Register) erlaubt -- dieses Modul liest nur.';
        }
        if (!$this->hasGatewayParent()) {
            return 'Kein ModBus-Gateway verbunden.';
        }
        $Quantity = max(1, min(16, $Quantity));
        $client = $this->gatewayClient();
        $regs = ($Function === 3) ? $client->readHolding($Address, $Quantity) : $client->readInput($Address, $Quantity);
        if ($regs === null) {
            return 'Fehler: ' . $client->lastError . ($client->lastResponseLen > 0 ? ' (Antwort 0x' . $client->lastResponseHex . ')' : '');
        }
        $unsigned = [];
        $signed = [];
        foreach ($regs as $i => $v) {
            $unsigned[] = $client->u16($regs, $i);
            $signed[] = $client->s16($regs, $i);
        }
        return 'Function ' . $Function . ', ab Adresse ' . $Address . ': unsigned [' . implode(', ', $unsigned) . '], signed [' . implode(', ', $signed) . '], Antwort 0x' . $client->lastResponseHex;
    }

    /**
     * NRG-Stack-Vertrag fuer Waermepumpen -- identisch zu WPModbusHub (geteilter
     * Aufbau in WPMBHUB_HeatpumpTrait::buildFunctions()).
     */
    public function GetFunctions()
    {
        $manufacturer = $this->ReadPropertyString('Manufacturer');
        $caption = isset(self::DRIVERS[$manufacturer]) ? self::DRIVERS[$manufacturer]['caption'] : 'Wärmepumpe';
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
