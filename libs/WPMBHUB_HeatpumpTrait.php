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
    private function readRegisters(array $registerMap, WPMBHUB_ModbusTcpClient $client): ?array
    {
        $out = [];
        $anySuccess = false;
        $anyAttempt = false;
        foreach ($registerMap as $ident => $def) {
            $anyAttempt = true;
            $isFloat32 = ($def['type'] ?? 'int16') === 'float32';
            $count = $isFloat32 ? 2 : 1;
            $regs = ($def['regType'] === 'holding')
                ? $client->readHolding($def['addr'], $count)
                : $client->readInput($def['addr'], $count);
            if ($regs === null) {
                continue;
            }
            $anySuccess = true;
            if ($isFloat32) {
                // Kein Skalierungsfaktor -- IEEE754 traegt den Wert schon
                // direkt in Grad Celsius (siehe floatLE()-Kommentar zur
                // Wortreihenfolge).
                $out[$ident] = $client->floatLE($regs, 0);
            } else {
                $raw = !empty($def['signed']) ? $client->s16($regs, 0) : $client->u16($regs, 0);
                $scale = $def['scale'] ?? 1;
                // (float) vor der Division: PHP liefert bei glatt teilbaren
                // int/int-Werten sonst wieder einen int zurueck (z.B. 470/10 =
                // int 47 statt float 47.0) -- MaintainVariable() erwartet einen
                // durchgehend gleichartigen Typ je Ident.
                $out[$ident] = (float)$raw / $scale;
            }
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
