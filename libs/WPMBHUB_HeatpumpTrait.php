<?php

// WPMBHUB_HeatpumpTrait -- gemeinsame, transportunabhaengige Logik von
// WPModbusHub (TCP) und WPModbusHubGateway (Symcon-ModBus-Gateway): Register
// lesen/dekodieren, Variablen pflegen, Archivierung, NRG-Stack-Vertrag.
// Der Client wird von aussen uebergeben (WPMBHUB_ModbusTcpClient bzw.
// WPMBHUB_ModbusGatewayClient) -- die Logik selbst weiss nichts vom Transport.

trait WPMBHUB_HeatpumpTrait
{
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

    /**
     * Liest alle Register eines Herstellerprofils. Liefert ['Ident' => float]
     * fuer jedes erfolgreich gelesene Feld -- ein einzelnes fehlgeschlagenes
     * Register lässt die uebrigen unberuehrt (kein Alles-oder-nichts), NULL
     * nur wenn der gesamte Verbindungsaufbau fehlschlaegt.
     */
    /** Klartext zur letzten Fehlerursache des Modbus-Clients, fuer die Debugausgabe. */
    private function describeModbusError($client): string
    {
        $e = (string)($client->lastError ?? '');
        $map = ['connect' => WPMBHUB_Text::t('no connection (is IP/port reachable? Modbus enabled on the device? Is something else already using the connection?)'),
            'timeout' => WPMBHUB_Text::t('no answer within 3 s'), 'write' => WPMBHUB_Text::t('sending failed'),
            'eof' => WPMBHUB_Text::t('connection closed by the device'), 'frame' => WPMBHUB_Text::t('invalid answer (frame length)')];
        // Die Clients liefern ihre Meldungen schon uebersetzt (WPMBHUB_Text), nur die Kurzcodes werden hier aufgeloest.
        return $map[$e] ?? ($e !== '' ? $e : WPMBHUB_Text::t('unknown'));
    }

    /**
     * Felder, die das Geraet selbst als "nicht vorhanden" meldet (Ersatzwert), aus dem letzten
     * readRegisters()-Lauf. Sie sind kein Fehler: eine Luft-Waermepumpe hat keine Quellentemperatur.
     * @var string[]
     */
    private $unavailableIdents = [];

    /** True, wenn eines der gelesenen Register einen Ersatzwert ("Objekt nicht vorhanden") traegt. */
    private function isInvalidReading($client, array $regs, array $def): bool
    {
        if (!isset($def['invalidRaw'])) {
            return false;
        }
        foreach ($regs as $i => $_) {
            if (in_array($client->u16($regs, $i), (array)$def['invalidRaw'], true)) {
                return true;
            }
        }
        return false;
    }

    private function readRegisterBlock($client, array $def, int $addr, int $count): ?array
    {
        return ($def['regType'] === 'holding') ? $client->readHolding($addr, $count) : $client->readInput($addr, $count);
    }

    private function readRegisters(array $registerMap, WPMBHUB_ModbusTcpClient $client): ?array
    {
        $out = [];
        $anySuccess = false;
        $anyAttempt = false;
        $this->unavailableIdents = [];
        foreach ($registerMap as $ident => $def) {
            $anyAttempt = true;
            $type = $def['type'] ?? 'int16';
            $isFloat32 = $type === 'float32';
            $count = ($isFloat32 || $type === 'kwhmwh') ? 2 : 1;
            $regs = $this->readRegisterBlock($client, $def, $def['addr'], $count);
            // Manche Geraete melden "Objekt nicht vorhanden" mit einem festen Ersatzwert (Stiebel ISG:
            // 0x8000, LWZ-Sensoren -60/-50). Der darf nie als Messwert erscheinen; eine Ausweichadresse
            // (z. B. WPM 3 gegen WPM 3i) wird versucht, sonst bleibt das Feld leer.
            if ($regs !== null && $this->isInvalidReading($client, $regs, $def)) {
                $badRaw = $client->u16($regs, 0);
                $regs = null;
                if (isset($def['altAddr'])) {
                    $alt = $this->readRegisterBlock($client, $def, $def['altAddr'], $count);
                    if ($alt !== null && !$this->isInvalidReading($client, $alt, $def)) {
                        $regs = $alt;
                    }
                }
                if ($regs === null) {
                    $this->SendDebug('Modbus', WPMBHUB_Text::t('%s %s register %d: object does not exist on the device (substitute value 0x%04X)', $ident, $def['regType'], $def['addr'], $badRaw), 0);
                    $this->unavailableIdents[] = $ident;
                    continue;
                }
            }
            if ($regs === null) {
                // Ursache sichtbar machen: ohne diese Zeile wirkt jeder Fehler (Verbindung,
                // Zeitueberschreitung, Ausnahme des Geraets) gleich als "antwortet nicht".
                $this->SendDebug('Modbus', WPMBHUB_Text::t('%s %s register %d: %s', $ident, $def['regType'], $def['addr'], $this->describeModbusError($client)), 0);
                continue;
            }
            $anySuccess = true;
            if ($isFloat32) {
                // Kein Skalierungsfaktor -- IEEE754 traegt den Wert schon
                // direkt in Grad Celsius (siehe floatLE()-Kommentar zur
                // Wortreihenfolge).
                $out[$ident] = $client->floatLE($regs, 0);
            } elseif ($type === 'kwhmwh') {
                // Stiebel-Zaehler: Register 1 = kWh-Teil (0..999), Register 2 = MWh-Teil.
                $out[$ident] = (float)$client->u16($regs, 1) * 1000.0 + (float)$client->u16($regs, 0);
            } else {
                $raw = !empty($def['signed']) ? $client->s16($regs, 0) : $client->u16($regs, 0);
                $scale = $def['scale'] ?? 1;
                // (float) vor der Division: PHP liefert bei glatt teilbaren
                // int/int-Werten sonst wieder einen int zurueck (z.B. 470/10 =
                // int 47 statt float 47.0) -- MaintainVariable() erwartet einen
                // durchgehend gleichartigen Typ je Ident.
                $out[$ident] = (float)$raw / $scale + (float)($def['offset'] ?? 0);
            }
        }
        if ($anyAttempt && !$anySuccess) {
            return null;
        }
        return $out;
    }

    /**
     * Liest die Reglerkennung des Geraets (Stiebel: WPM 3 = 390, WPM 3i = 391, WPMsystem = 449, LWZ = 103/104)
     * und merkt sie, damit das Formular eine falsch gewaehlte Karte meldet. 0 = unbekannt/nicht gelesen.
     */
    private function readControllerCode($client, array $driver): void
    {
        $code = 0;
        if (isset($driver['controller'])) {
            $regs = $client->readInput($driver['controller']['addr'], 1);
            if ($regs !== null && $client->u16($regs, 0) !== 0x8000) {
                $code = $client->u16($regs, 0);
            }
            if ($code > 0 && !in_array($code, $driver['controller']['expect'], true)) {
                $this->SendDebug('Modbus', WPMBHUB_Text::t('controller code %d does not fit the selected card', $code), 0);
            }
        }
        $this->WriteAttributeInteger('ControllerCode', $code);
    }

    /** Hinweis, wenn die Reglerkennung des Geraets nicht zur gewaehlten Karte passt; sonst leer. */
    private function controllerNote(array $driver): string
    {
        $code = $this->ReadAttributeInteger('ControllerCode');
        if ($code > 0 && isset($driver['controller']) && !in_array($code, $driver['controller']['expect'], true)) {
            return ' ' . WPMBHUB_Text::t('⚠️ The device reports controller code %d, which does not fit the selected card -- another Stiebel Eltron card (heat pump with WPM or integral ventilation unit LWZ) is probably the right one.', $code);
        }
        return '';
    }

    private function maintainDeviceVariables(array $values, bool $reachable): void
    {
        $pos = 0;
        $this->MaintainVariable('Erreichbar', WPMBHUB_Text::t('Reachable'), VARIABLETYPE_BOOLEAN, '~Alert.Reversed', $pos++, true);
        $this->SetValue('Erreichbar', $reachable);

        $registers = WPMBHUB_Drivers::DRIVERS[$this->ReadPropertyString('Manufacturer')]['registers'] ?? [];
        foreach (WPMBHUB_Drivers::FIELD_CAPTIONS as $ident => $caption) {
            if (!array_key_exists($ident, $values)) {
                continue;
            }
            $def = $registers[$ident] ?? [];
            $kind = $def['kind'] ?? 'temperature';
            $name = WPMBHUB_Text::t($caption);
            switch ($kind) {
                case 'energy':
                    $this->MaintainVariable($ident, $name, VARIABLETYPE_FLOAT, '~Electricity', $pos++, true);
                    $this->SetValue($ident, (float)$values[$ident]);
                    break;
                case 'counter':
                    $this->MaintainVariable($ident, $name, VARIABLETYPE_INTEGER, '', $pos++, true);
                    $this->SetValue($ident, (int)round($values[$ident]));
                    break;
                case 'enum':
                    $this->MaintainVariable($ident, $name, VARIABLETYPE_INTEGER, (string)($def['profile'] ?? ''), $pos++, true);
                    $this->SetValue($ident, (int)round($values[$ident]));
                    break;
                default:
                    $this->MaintainVariable($ident, $name, VARIABLETYPE_FLOAT, 'NRG.Celsius', $pos++, true);
                    $this->SetValue($ident, (float)$values[$ident]);
            }
        }
    }

    /**
     * Merkt sich, wann der letzte Lesezyklus lief und welche Felder des
     * Herstellerprofils dabei NICHT gelesen wurden -- Grundlage der Statuszeile
     * im Formular.
     */
    private function recordCycle(array $registerMap, ?array $values): void
    {
        $this->WriteAttributeInteger('LastCycleAt', time());
        $missing = array_diff(array_keys($registerMap), array_keys($values ?? []));
        $this->WriteAttributeString('LastMissing', implode(',', $missing));
        // Vom Geraet selbst als "nicht vorhanden" gemeldete Felder (Ersatzwert) sind kein Lesefehler.
        $this->WriteAttributeString('LastUnavailable', implode(',', array_intersect($this->unavailableIdents, $missing)));
    }

    /**
     * "42 s ago" / "5 min ago" / "3 h ago" / "2 days ago" (deutsch "vor 42 s" ...).
     */
    private function ageText(int $timestamp): string
    {
        $sec = max(0, time() - $timestamp);
        if ($sec < 120) {
            return WPMBHUB_Text::t('%d s ago', $sec);
        }
        if ($sec < 7200) {
            return WPMBHUB_Text::t('%d min ago', intdiv($sec, 60));
        }
        if ($sec < 172800) {
            return WPMBHUB_Text::t('%d h ago', intdiv($sec, 3600));
        }
        return WPMBHUB_Text::t('%d days ago', intdiv($sec, 86400));
    }

    /**
     * Die zuletzt gelesenen Werte des gewaehlten Herstellerprofils als Text,
     * z. B. "Außentemperatur 7,5 °C, Vorlauftemperatur 38,2 °C" (englisch mit Punkt). Nur Felder, die
     * das Profil kennt UND zu denen eine Variable existiert.
     */
    private function lastValuesText(string $manufacturer): string
    {
        $parts = [];
        $registers = WPMBHUB_Drivers::DRIVERS[$manufacturer]['registers'] ?? [];
        foreach (WPMBHUB_Drivers::FIELD_CAPTIONS as $ident => $caption) {
            // Nur Temperaturen: Zaehler und Betriebsart machen die Statuszeile unleserlich lang.
            if (!isset($registers[$ident]) || ($registers[$ident]['kind'] ?? 'temperature') !== 'temperature') {
                continue;
            }
            $id = $this->contractFieldID($ident);
            if ($id === 0) {
                continue;
            }
            $parts[] = WPMBHUB_Text::t($caption) . ' ' . WPMBHUB_Text::zahl((float)GetValue($id)) . ' °C';
        }
        return implode(', ', $parts);
    }

    /**
     * Statuszeile fuer das Formular (SUITE.md "Verbund-Verbindungen im
     * Formular sichtbar machen"): live berechnet, sagt was tatsaechlich
     * ankommt. Liefert [Text, Farbe] -- Farbe -1 = Standard, 0xFF0000 = rot.
     *
     *   $active   Schalter "aktiv"
     *   $missing  Text der fehlenden Pflichtangabe ('' = nichts fehlt)
     *   $interval Aktualisierungsintervall in Sekunden
     *   $hint     Pruefhinweis, wenn die Waermepumpe nicht antwortet
     */
    private function connectionStatusLine(bool $active, string $missing, int $interval, string $hint): array
    {
        if (!$active) {
            if ($missing !== '') {
                return [WPMBHUB_Text::t('ℹ️ Not set up yet: %s. Then switch "active" on and apply.', $missing), -1];
            }
            return [WPMBHUB_Text::t('ℹ️ Switched off -- switch "active" on and apply, then the heat pump is read.'), -1];
        }
        if ($missing !== '') {
            return [WPMBHUB_Text::t('⛔ Required entry missing: %s.', $missing), 0xFF0000];
        }

        $manufacturer = $this->ReadPropertyString('Manufacturer');
        $driver = WPMBHUB_Drivers::DRIVERS[$manufacturer] ?? null;
        if ($driver === null) {
            return [WPMBHUB_Text::t('⛔ Unknown manufacturer "%s" -- please choose again.', $manufacturer), 0xFF0000];
        }
        $name = WPMBHUB_Text::t($driver['caption']);
        $lastCycle = $this->ReadAttributeInteger('LastCycleAt');
        if ($lastCycle === 0) {
            return [WPMBHUB_Text::t('ℹ️ No read cycle has run yet -- the first follows within %d s after applying.', $interval), -1];
        }

        $reachableID = $this->contractFieldID('Erreichbar');
        $reachable = ($reachableID !== 0) && (bool)GetValue($reachableID);
        $lastSeen = $this->ReadAttributeInteger('LastSeenAt');
        $values = $this->lastValuesText($manufacturer);

        if (!$reachable) {
            $text = WPMBHUB_Text::t('⚠️ %s does not answer (last answer: %s).', $name, $lastSeen > 0 ? $this->ageText($lastSeen) : WPMBHUB_Text::t('never')) . ' ' . $hint;
            if ($values !== '') {
                $text .= ' ' . WPMBHUB_Text::t('Last known values: %s.', $values);
            }
            return [$text, -1];
        }
        if (time() - $lastCycle > 3 * $interval + 10) {
            return [WPMBHUB_Text::t('⚠️ The last update was %s, one is expected every %d s -- check the timer and the instance status. Last values: %s.', $this->ageText($lastCycle), $interval, $values), -1];
        }
        $unavailable = array_filter(explode(',', $this->ReadAttributeString('LastUnavailable')));
        $missingIdents = array_diff(array_filter(explode(',', $this->ReadAttributeString('LastMissing'))), $unavailable);
        $note = $this->controllerNote($driver);
        if (count($missingIdents) > 0) {
            $names = [];
            foreach ($missingIdents as $ident) {
                $names[] = isset(WPMBHUB_Drivers::FIELD_CAPTIONS[$ident]) ? WPMBHUB_Text::t(WPMBHUB_Drivers::FIELD_CAPTIONS[$ident]) : $ident;
            }
            return [WPMBHUB_Text::t('⚠️ %s answers, but %d of %d fields were not read (%s). Read %s: %s.', $name, count($names), count($driver['registers']), implode(', ', $names), $this->ageText($lastCycle), $values) . $note, -1];
        }
        $line = WPMBHUB_Text::t('✅ %s answers, read %s: %s.', $name, $this->ageText($lastCycle), $values);
        if (count($unavailable) > 0) {
            $line .= ' ' . WPMBHUB_Text::t('(%d fields are not offered by this device.)', count($unavailable));
        }
        return [$line . $note, -1];
    }

    /**
     * Schaltet das Logging im Symcon-Archiv fuer die Messwerte (Fliesskomma-
     * Variablen) dieser Instanz ein -- AUSSCHLIESSLICH auf Klick im Formular.
     * Symcon-Review 30.10.2026: ein Modul darf Variablen nicht von selbst
     * archivieren, der Nutzer muss das bewusst einschalten. Schaltet nie etwas
     * aus, was schon laeuft.
     */
    public function EnableArchiving(): string
    {
        try {
            $archiveIDs = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            if (!is_array($archiveIDs) || count($archiveIDs) === 0) {
                return WPMBHUB_Text::t('❌ No archive instance found -- logging cannot be enabled.');
            }
            $archiveID = (int)$archiveIDs[0];
            $new = 0;
            $already = 0;
            foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
                if (IPS_GetObject($id)['ObjectType'] !== OBJECTTYPE_VARIABLE || IPS_GetVariable($id)['VariableType'] !== VARIABLETYPE_FLOAT) {
                    continue;
                }
                if (AC_GetLoggingStatus($archiveID, $id)) {
                    $already++;
                    continue;
                }
                AC_SetLoggingStatus($archiveID, $id, true);
                $new++;
            }
            if ($new > 0) {
                IPS_ApplyChanges($archiveID);
            }
            if ($new === 0 && $already === 0) {
                return WPMBHUB_Text::t('ℹ️ No measured values yet -- wait for a poll first, then click again.');
            }
            return $already > 0
                ? WPMBHUB_Text::t('✅ Logging enabled for %d measured value(s) (%d were already enabled).', $new, $already)
                : WPMBHUB_Text::t('✅ Logging enabled for %d measured value(s).', $new);
        } catch (\Throwable $e) {
            return WPMBHUB_Text::t('❌ Logging could not be enabled: %s', $e->getMessage());
        }
    }

    /**
     * Baut den NRG-Stack-Waermepumpenvertrag (Type=>'heatpump') -- geteilt
     * zwischen WPModbusHub und WPModbusHubGateway, damit beide exakt denselben
     * Vertrag liefern.
     */
    private function buildFunctions(string $caption): array
    {
        $reachableID = @$this->GetIDForIdent('Erreichbar');
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
            'compressorStartsID'   => $this->contractFieldID('Verdichterstarts'),
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
        // Betriebsart der Stiebel-Karten (nur lesend angezeigt). Die Namen entstehen einmal bei der Anlage des
        // Profils in der Sprache, die Symcon dann hat.
        $modes = [
            'WPMBHUB.StiebelModeWPM' => [0 => WPMBHUB_Text::t('Emergency operation'), 1 => WPMBHUB_Text::t('Standby mode'), 2 => WPMBHUB_Text::t('Programmed operation'),
                3 => WPMBHUB_Text::t('Comfort mode'), 4 => WPMBHUB_Text::t('ECO mode'), 5 => WPMBHUB_Text::t('Hot water mode')],
            'WPMBHUB.StiebelModeLWZ' => [0 => WPMBHUB_Text::t('Emergency operation'), 1 => WPMBHUB_Text::t('Standby mode'), 3 => WPMBHUB_Text::t('Day mode'),
                4 => WPMBHUB_Text::t('Setback mode'), 5 => WPMBHUB_Text::t('Hot water mode'), 11 => WPMBHUB_Text::t('Automatic'), 14 => WPMBHUB_Text::t('Manual mode')],
        ];
        foreach ($modes as $profile => $associations) {
            if (!IPS_VariableProfileExists($profile)) {
                IPS_CreateVariableProfile($profile, VARIABLETYPE_INTEGER);
                foreach ($associations as $value => $text) {
                    IPS_SetVariableProfileAssociation($profile, $value, $text, '', -1);
                }
            }
        }
        if (!IPS_VariableProfileExists('NRG.Celsius')) {
            IPS_CreateVariableProfile('NRG.Celsius', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileText('NRG.Celsius', '', ' °C');
            IPS_SetVariableProfileDigits('NRG.Celsius', 1);
        }
    }
}
