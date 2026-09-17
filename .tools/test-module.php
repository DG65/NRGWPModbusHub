<?php

// Pruefstand fuer WPModbusHub (Muster: WPHub/.tools/test-module.php).
// Kein Netzzugriff: der Modbus-Client wird durch eine Attrappe ersetzt, die
// kanonische Registerwerte liefert statt echter TCP-Kommunikation.
//
// Aufruf:  php .tools/test-module.php     (0 = alle Pruefungen bestanden)

error_reporting(E_ALL & ~E_DEPRECATED);

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;
    if ($ok) {
        echo "  ✅ $name\n";
    } else {
        echo "  ❌ $name" . ($detail !== '' ? " — $detail" : '') . "\n";
        $failures++;
    }
}

// ---------------------------------------------------------------------------
// Mini-IPS: nur was WPModbusHub wirklich benutzt.
// ---------------------------------------------------------------------------

const VARIABLETYPE_BOOLEAN = 0;
const VARIABLETYPE_INTEGER = 1;
const VARIABLETYPE_FLOAT   = 2;
const VARIABLETYPE_STRING  = 3;
const KL_WARNING = 10205;
const KL_NOTIFY  = 10204;

$GLOBALS['ips'] = [
    'profiles'   => [],
    'variables'  => [],
    'nextVarId'  => 10000,
    'properties' => [],
    'log'        => [],
];

function IPS_VariableProfileExists(string $name): bool
{
    return isset($GLOBALS['ips']['profiles'][$name]);
}
function IPS_CreateVariableProfile(string $name, int $type): void
{
    $GLOBALS['ips']['profiles'][$name] = ['type' => $type, 'suffix' => '', 'digits' => 0];
}
function IPS_SetVariableProfileText(string $name, string $prefix, string $suffix): void
{
    $GLOBALS['ips']['profiles'][$name]['suffix'] = $suffix;
}
function IPS_SetVariableProfileDigits(string $name, int $digits): void
{
    $GLOBALS['ips']['profiles'][$name]['digits'] = $digits;
}
const TEST_ARCHIVE_INSTANCE_ID = 55555;
function IPS_GetInstanceListByModuleID(string $moduleID): array
{
    if ($moduleID === '{43192F0B-135B-4CE7-A0A7-1475603F3060}') {
        return [TEST_ARCHIVE_INSTANCE_ID];
    }
    return [];
}
function AC_GetLoggingStatus(int $archiveID, int $variableID): bool
{
    return $GLOBALS['ips']['archived'][$variableID] ?? false;
}
function AC_SetLoggingStatus(int $archiveID, int $variableID, bool $active): bool
{
    $GLOBALS['ips']['archived'][$variableID] = $active;
    return true;
}
function IPS_ApplyChanges(int $id): void
{
    $GLOBALS['ips']['applied'] = true;
}
function GetValue(int $id)
{
    foreach ($GLOBALS['ips']['variables'] as $v) {
        if ($v['id'] === $id) {
            return $v['value'];
        }
    }
    return null;
}

class IPSModule
{
    public $InstanceID = 12345;
    protected $attributes = [];
    protected $timers = [];
    public $status = 0;

    public function __construct()
    {
    }
    public function Create()
    {
    }
    public function ApplyChanges()
    {
    }
    protected function RegisterPropertyBoolean(string $name, bool $default): void
    {
        if (!isset($GLOBALS['ips']['properties'][$name])) {
            $GLOBALS['ips']['properties'][$name] = $default;
        }
    }
    protected function RegisterPropertyInteger(string $name, int $default): void
    {
        if (!isset($GLOBALS['ips']['properties'][$name])) {
            $GLOBALS['ips']['properties'][$name] = $default;
        }
    }
    protected function RegisterPropertyString(string $name, string $default): void
    {
        if (!isset($GLOBALS['ips']['properties'][$name])) {
            $GLOBALS['ips']['properties'][$name] = $default;
        }
    }
    protected function RegisterAttributeString(string $name, string $default): void
    {
        if (!isset($this->attributes[$name])) {
            $this->attributes[$name] = $default;
        }
    }
    protected function RegisterAttributeInteger(string $name, int $default): void
    {
        if (!isset($this->attributes[$name])) {
            $this->attributes[$name] = $default;
        }
    }
    protected function ReadAttributeInteger(string $name): int
    {
        return (int)($this->attributes[$name] ?? 0);
    }
    protected function WriteAttributeInteger(string $name, int $value): void
    {
        $this->attributes[$name] = $value;
    }
    protected function RegisterAttributeBoolean(string $name, bool $default): void
    {
        if (!isset($this->attributes[$name])) {
            $this->attributes[$name] = $default;
        }
    }
    protected function ReadAttributeBoolean(string $name): bool
    {
        return (bool)($this->attributes[$name] ?? false);
    }
    protected function WriteAttributeBoolean(string $name, bool $value): void
    {
        $this->attributes[$name] = $value;
    }
    protected function RegisterTimer(string $ident, int $interval, string $script): void
    {
        $this->timers[$ident] = $interval;
    }
    protected function SetTimerInterval(string $ident, int $interval): void
    {
        $this->timers[$ident] = $interval;
    }
    public function GetTimerInterval(string $ident): int
    {
        return $this->timers[$ident] ?? -1;
    }
    protected function ReadPropertyBoolean(string $name): bool
    {
        return (bool)$GLOBALS['ips']['properties'][$name];
    }
    protected function ReadPropertyInteger(string $name): int
    {
        return (int)$GLOBALS['ips']['properties'][$name];
    }
    protected function ReadPropertyString(string $name): string
    {
        return (string)$GLOBALS['ips']['properties'][$name];
    }
    protected function ReadAttributeString(string $name): string
    {
        return (string)($this->attributes[$name] ?? '');
    }
    protected function WriteAttributeString(string $name, string $value): void
    {
        $this->attributes[$name] = $value;
    }
    protected function SetStatus(int $status): void
    {
        $this->status = $status;
    }
    protected function GetStatus(): int
    {
        return $this->status;
    }
    protected function SendDebug(string $topic, string $text, int $format): void
    {
    }
    protected function LogMessage(string $text, int $type): void
    {
        $GLOBALS['ips']['log'][] = $text;
    }
    protected function UpdateFormField(string $field, string $key, $value): void
    {
        $GLOBALS['ips']['formFieldUpdates'][$field][$key] = $value;
    }
    protected function MaintainVariable(string $ident, string $name, int $type, string $profile, int $pos, bool $keep): void
    {
        if (!$keep) {
            unset($GLOBALS['ips']['variables'][$ident]);
            return;
        }
        if (!isset($GLOBALS['ips']['variables'][$ident])) {
            $GLOBALS['ips']['variables'][$ident] = [
                'name'    => $name,
                'type'    => $type,
                'profile' => $profile,
                'value'   => null,
                'id'      => $GLOBALS['ips']['nextVarId']++,
            ];
        }
    }
    protected function SetValue(string $ident, $value): void
    {
        if (isset($GLOBALS['ips']['variables'][$ident])) {
            $GLOBALS['ips']['variables'][$ident]['value'] = $value;
        }
    }
    protected function GetIDForIdent(string $ident)
    {
        if (!isset($GLOBALS['ips']['variables'][$ident])) {
            trigger_error("Ident $ident not found", E_USER_WARNING);
            return false;
        }
        return $GLOBALS['ips']['variables'][$ident]['id'];
    }
}

require __DIR__ . '/../WPModbusHub/module.php';

// Modbus-Client-Attrappe: liefert kanonische Registerwerte aus einer
// vorgegebenen Tabelle statt echter TCP-Kommunikation. $regType+$addr als
// Schluessel, damit ein Test gezielt einzelne Register fehlschlagen lassen
// kann (Teilausfall-Pruefung).
class FakeModbusClient extends WPMBHUB_ModbusTcpClient
{
    public array $values = []; // "input:1" => rawRegisterValue (bereits als u16-Bitmuster)
    public array $fail = [];   // Schluessel, die absichtlich null liefern sollen

    public function readInput($startReg, $count)
    {
        return $this->fakeRead('input', $startReg);
    }
    public function readHolding($startReg, $count)
    {
        return $this->fakeRead('holding', $startReg);
    }
    private function fakeRead(string $type, int $addr): ?array
    {
        $key = $type . ':' . $addr;
        if (in_array($key, $this->fail, true) || !array_key_exists($key, $this->values)) {
            return null;
        }
        return [0 => $this->values[$key] & 0xFFFF];
    }
    public function close(): void
    {
    }
}

// ---------------------------------------------------------------------------
echo "Block 1: Lebenszyklus und Status\n";
// ---------------------------------------------------------------------------

$mod = new WPModbusHub();
$mod->Create();
check('Manufacturer-Standard ist NIBE', $GLOBALS['ips']['properties']['Manufacturer'] === 'nibe');
check('Port-Standard passt zu NIBE (502)', $GLOBALS['ips']['properties']['Port'] === 502);
check('WPMBHUB_Active-Standard ist aus', $GLOBALS['ips']['properties']['WPMBHUB_Active'] === false);

$mod->ApplyChanges();
check('Inaktiv: Status 104', $mod->GetTimerInterval('WPMBHUB_UpdateTimer') === 0 && $mod->status === 104);

$GLOBALS['ips']['properties']['WPMBHUB_Active'] = true;
$mod->ApplyChanges();
check('Aktiv ohne Host: Status 201 (kein Timer)', $mod->status === 201 && $mod->GetTimerInterval('WPMBHUB_UpdateTimer') === 0);

$GLOBALS['ips']['properties']['Host'] = '192.168.1.50';
$mod->ApplyChanges();
check('Aktiv mit Host: Status 102, Timer läuft', $mod->status === 102 && $mod->GetTimerInterval('WPMBHUB_UpdateTimer') === 60000);

check('Gemeinsames Profil NRG.Celsius wurde angelegt', IPS_VariableProfileExists('NRG.Celsius'));

// ---------------------------------------------------------------------------
echo "Block 2: readRegisters() -- Registerprofile werden korrekt dekodiert\n";
// ---------------------------------------------------------------------------

$readRegisters = new ReflectionMethod(WPModbusHub::class, 'readRegisters');
$readRegisters->setAccessible(true);

// NIBE: Aussentemperatur reg1=+75 (7.5°C), Vorlauf reg2=+382 (38.2°C, negative
// Probe separat unten), Warmwasser reg8=+485, Zone1Ist reg116=+215.
$fakeNibe = new FakeModbusClient('192.168.1.50', 502, 1);
$fakeNibe->values = [
    'input:1'   => 75,
    'input:2'   => 382,
    'input:8'   => 485,
    'input:116' => 215,
];
$valuesNibe = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['nibe']['registers'], $fakeNibe);
check('NIBE: Aussentemperatur = 7.5', ($valuesNibe['Aussentemperatur'] ?? null) === 7.5);
check('NIBE: Vorlauftemperatur = 38.2', ($valuesNibe['Vorlauftemperatur'] ?? null) === 38.2);
check('NIBE: Warmwasser = 48.5', ($valuesNibe['Warmwasser'] ?? null) === 48.5);
check('NIBE: Zone1Ist = 21.5', ($valuesNibe['Zone1Ist'] ?? null) === 21.5);

// Negative Temperatur (Winter, -35 = -3.5°C) -- s16-Vorzeichenprobe.
$fakeNibeCold = new FakeModbusClient('192.168.1.50', 502, 1);
$fakeNibeCold->values = ['input:1' => 0x10000 - 35]; // -35 als 16-Bit-Zweierkomplement
$valuesCold = $readRegisters->invoke($mod, ['Aussentemperatur' => WPModbusHub::DRIVERS['nibe']['registers']['Aussentemperatur']], $fakeNibeCold);
check('NIBE: negative Aussentemperatur korrekt (-3.5)', ($valuesCold['Aussentemperatur'] ?? null) === -3.5, json_encode($valuesCold));

// Stiebel Eltron: eigene Registeradressen (Block 1 ab 0), inkl. Zonen-Soll/Ist.
$fakeStiebel = new FakeModbusClient('192.168.1.51', 502, 1);
$fakeStiebel->values = [
    'input:6'  => 45,   // Aussentemperatur 4.5°C
    'input:11' => 351,  // Vorlauf 35.1°C
    'input:15' => 470,  // Warmwasser Ist 47.0°C
    'input:16' => 500,  // Warmwasser Soll 50.0°C
    'input:0'  => 213,  // Zone1 Ist 21.3°C
    'input:1'  => 210,  // Zone1 Soll 21.0°C
];
$valuesStiebel = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $fakeStiebel);
check('Stiebel Eltron: alle sechs Felder korrekt dekodiert', $valuesStiebel === [
    'Aussentemperatur' => 4.5, 'Vorlauftemperatur' => 35.1, 'Warmwasser' => 47.0,
    'WarmwasserSoll' => 50.0, 'Zone1Ist' => 21.3, 'Zone1Soll' => 21.0,
], json_encode($valuesStiebel));

// LG: gemischt Input-/Holding-Register.
$fakeLg = new FakeModbusClient('192.168.1.52', 502, 1);
$fakeLg->values = [
    'input:12'   => 82,  // Aussentemperatur 8.2°C
    'input:3'    => 400, // Vorlauf 40.0°C
    'input:5'    => 455, // Warmwasser Ist 45.5°C
    'holding:8'  => 480, // Warmwasser Soll 48.0°C
    'holding:2'  => 220, // Zone1 Soll 22.0°C
];
$valuesLg = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['lg']['registers'], $fakeLg);
check('LG: Input-Register korrekt (Aussentemperatur)', ($valuesLg['Aussentemperatur'] ?? null) === 8.2);
check('LG: Holding-Register korrekt (WarmwasserSoll)', ($valuesLg['WarmwasserSoll'] ?? null) === 48.0);

// Samsung: alles Holding-Register, Unit-ID 2 (Aussengeraet).
$fakeSamsung = new FakeModbusClient('192.168.1.53', 502, 2);
$fakeSamsung->values = [
    'holding:13' => 65,  // Aussentemperatur 6.5°C
    'holding:66' => 390, // Vorlauf (Water OUT) 39.0°C
    'holding:65' => 330, // Ruecklauf (Water IN) 33.0°C
];
$valuesSamsung = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['samsung']['registers'], $fakeSamsung);
check('Samsung: Vorlauf-/Ruecklauftemperatur korrekt', ($valuesSamsung['Vorlauftemperatur'] ?? null) === 39.0 && ($valuesSamsung['Ruecklauftemperatur'] ?? null) === 33.0);

// Teilausfall: ein Register liefert null, die uebrigen bleiben nutzbar.
$fakePartial = new FakeModbusClient('192.168.1.50', 502, 1);
$fakePartial->values = ['input:1' => 75, 'input:8' => 485];
$fakePartial->fail = ['input:2', 'input:116'];
$valuesPartial = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['nibe']['registers'], $fakePartial);
check('Teilausfall: erfolgreiche Register bleiben erhalten', ($valuesPartial['Aussentemperatur'] ?? null) === 7.5 && ($valuesPartial['Warmwasser'] ?? null) === 48.5);
check('Teilausfall: fehlgeschlagene Register fehlen (kein erfundener Wert)', !array_key_exists('Vorlauftemperatur', $valuesPartial) && !array_key_exists('Zone1Ist', $valuesPartial));

// Kompletter Ausfall: kein einziges Register liest sich -> null (nicht erreichbar).
$fakeDead = new FakeModbusClient('192.168.1.50', 502, 1);
$fakeDead->fail = ['input:1', 'input:2', 'input:8', 'input:116'];
$valuesDead = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['nibe']['registers'], $fakeDead);
check('Kompletter Ausfall liefert null (nicht nur ein leeres Array)', $valuesDead === null);

// ---------------------------------------------------------------------------
echo "Block 3: maintainDeviceVariables() + GetFunctions()\n";
// ---------------------------------------------------------------------------

$maintainVars = new ReflectionMethod(WPModbusHub::class, 'maintainDeviceVariables');
$maintainVars->setAccessible(true);
$maintainVars->invoke($mod, $valuesNibe, true);

check('Erreichbar-Variable gesetzt', ($GLOBALS['ips']['variables']['Erreichbar']['value'] ?? null) === true);
check('Aussentemperatur-Variable gesetzt', ($GLOBALS['ips']['variables']['Aussentemperatur']['value'] ?? null) === 7.5);
check('Vorlauftemperatur-Variable gesetzt', ($GLOBALS['ips']['variables']['Vorlauftemperatur']['value'] ?? null) === 38.2);
check('Zone1Soll-Variable NICHT angelegt (NIBE-Profil liefert das Feld nicht)', !isset($GLOBALS['ips']['variables']['Zone1Soll']));
check('Archivierung für Aussentemperatur aktiviert', ($GLOBALS['ips']['archived'][$GLOBALS['ips']['variables']['Aussentemperatur']['id']] ?? false) === true);

$functions = $mod->GetFunctions();
check('GetFunctions() liefert genau einen Eintrag', is_array($functions) && count($functions) === 1);
check('GetFunctions(): Type=heatpump, contractVersion 1.15', ($functions[0]['Type'] ?? '') === 'heatpump' && ($functions[0]['contractVersion'] ?? '') === '1.15');
check('GetFunctions(): outsideTempID zeigt auf die echte Variable', ($functions[0]['outsideTempID'] ?? 0) === $GLOBALS['ips']['variables']['Aussentemperatur']['id']);
check('GetFunctions(): mainOutletTempID zeigt auf Vorlauftemperatur', ($functions[0]['mainOutletTempID'] ?? 0) === $GLOBALS['ips']['variables']['Vorlauftemperatur']['id']);
check('GetFunctions(): z1WaterTargetTempID = 0 (NIBE liefert kein Zone1Soll)', ($functions[0]['z1WaterTargetTempID'] ?? -1) === 0);
check('GetFunctions(): reachable folgt der Erreichbar-Variable', ($functions[0]['reachable'] ?? null) === true);
check('GetFunctions(): PowerID/EnergyID bleiben 0 (nur Temperaturen v1)', ($functions[0]['PowerID'] ?? -1) === 0 && ($functions[0]['EnergyID'] ?? -1) === 0);

// Nicht erreichbar -> reachable folgt korrekt, Werte bleiben (letzter bekannter Stand).
$maintainVars->invoke($mod, [], false);
check('Nicht erreichbar: Erreichbar-Variable false', ($GLOBALS['ips']['variables']['Erreichbar']['value'] ?? null) === false);
check('Nicht erreichbar: alter Temperaturwert bleibt stehen (kein Reset)', ($GLOBALS['ips']['variables']['Aussentemperatur']['value'] ?? null) === 7.5);
$functionsUnreachable = $mod->GetFunctions();
check('GetFunctions() nach Ausfall: reachable=false', ($functionsUnreachable[0]['reachable'] ?? null) === false);

// ---------------------------------------------------------------------------
echo "Block 4: Update() -- Zusammenspiel Modbus-Client + Status\n";
// ---------------------------------------------------------------------------
// Update() baut selbst einen echten WPMBHUB_ModbusTcpClient auf Basis von
// Host/Port/UnitId -- ohne Netz schlaegt die Verbindung fehl (connect()
// scheitert an einer nicht erreichbaren Adresse), das ist hier bewusst der
// Pruefgegenstand: Update() muss diesen Fall sauber als "nicht erreichbar"
// behandeln, nicht mit einem Fehler abbrechen.

$GLOBALS['ips']['properties']['Host'] = '203.0.113.1'; // TEST-NET-3, garantiert nicht erreichbar
$GLOBALS['ips']['properties']['WPMBHUB_Active'] = true;
$mod->Update();
check('Update() an nicht erreichbarer Adresse setzt Status 201', $mod->status === 201);
check('Update() protokolliert die Nichterreichbarkeit', count($GLOBALS['ips']['log']) > 0);

$GLOBALS['ips']['properties']['WPMBHUB_Active'] = false;
$logCountBefore = count($GLOBALS['ips']['log']);
$mod->Update();
check('Update() bei inaktivem Modul tut nichts', count($GLOBALS['ips']['log']) === $logCountBefore);

// ---------------------------------------------------------------------------
echo "Block 5: Formular -- Hersteller-Auswahl + Doku/Über dieses Modul\n";
// ---------------------------------------------------------------------------

function findFormElement(array $items, string $name): ?array
{
    foreach ($items as $item) {
        if (($item['name'] ?? null) === $name) {
            return $item;
        }
        if (isset($item['items']) && is_array($item['items'])) {
            $found = findFormElement($item['items'], $name);
            if ($found !== null) {
                return $found;
            }
        }
    }
    return null;
}

$GLOBALS['ips']['properties']['Manufacturer'] = 'nibe';
$form = json_decode($mod->GetConfigurationForm(), true);
$manufacturerSelect = findFormElement($form['elements'], 'Manufacturer');
check('Formular hat ein Hersteller-Select mit vier Optionen', $manufacturerSelect !== null && count($manufacturerSelect['options']) === 4);

$licenseHint = end($form['elements']);
check('"Über dieses Modul" steht ganz unten', ($licenseHint['caption'] ?? '') === '🧡  Über dieses Modul');
check('"Über dieses Modul" ist eingeklappt', ($licenseHint['expanded'] ?? true) === false);

$onChangeManufacturer = new ReflectionMethod(WPModbusHub::class, 'OnChangeManufacturer');
$onChangeManufacturer->setAccessible(true);
$onChangeManufacturer->invoke($mod, 'samsung');
check('OnChangeManufacturer() setzt Port auf den Samsung-Standard (502)', ($GLOBALS['ips']['formFieldUpdates']['Port']['value'] ?? null) === 502);
check('OnChangeManufacturer() setzt Unit-ID auf den Samsung-Standard (2)', ($GLOBALS['ips']['formFieldUpdates']['UnitId']['value'] ?? null) === 2);
check('OnChangeManufacturer() aktualisiert den Vertrauenshinweis', strpos($GLOBALS['ips']['formFieldUpdates']['ManufacturerConfidence']['caption'] ?? '', 'MIM-B19N') !== false);

// "Wozu dieses Modul?" + "Neu in Version" -- einmalig dismissible.
$purposePanel = findFormElement($form['elements'], 'PurposeIntroPanel');
check('"Wozu dieses Modul?"-Panel vorhanden', $purposePanel !== null);
$mod->AckPurposeIntro();
$formAfterAck = json_decode($mod->GetConfigurationForm(), true);
check('Panel verschwindet nach Bestätigen', findFormElement($formAfterAck['elements'], 'PurposeIntroPanel') === null);

// Forum-Hinweis -- echter Thread-Link, einmalig dismissible, steht vor
// "Über dieses Modul" (letztes Element).
$forumPanel = findFormElement($form['elements'], 'ForumHintPanel');
check('Forum-Hinweis-Panel vorhanden', $forumPanel !== null);
check('Forum-Hinweis-Caption korrekt', ($forumPanel['caption'] ?? '') === '💬  Feedback im Symcon-Forum');
$forumIndex = array_search('ForumHintPanel', array_column($form['elements'], 'name'), true);
check('Forum-Hinweis steht vor "Über dieses Modul"', $forumIndex !== false && $forumIndex < count($form['elements']) - 1);
$forumButton = null;
foreach (($forumPanel['items'] ?? []) as $item) {
    if (($item['caption'] ?? '') === 'Zum Forums-Thread') {
        $forumButton = $item;
    }
}
check('Forum-Knopf vorhanden mit link=true', $forumButton !== null && ($forumButton['link'] ?? false) === true);
check('Forum-Knopf-onClick ist ein echo auf den echten Thread-Link', strpos($forumButton['onClick'] ?? '', "echo 'https://community.symcon.de/t/modul-nrg-stack-wpmodbushub-") === 0, $forumButton['onClick'] ?? 'null');
$mod->AckForumHint();
$formAfterForumAck = json_decode($mod->GetConfigurationForm(), true);
check('Forum-Hinweis-Panel verschwindet nach Bestätigen', findFormElement($formAfterForumAck['elements'], 'ForumHintPanel') === null);

// ---------------------------------------------------------------------------
echo "Block 6: Vollstaendigkeit der Methodenaufrufe\n";
// ---------------------------------------------------------------------------

foreach ([
    ['WPModbusHub/libs/ModbusTcpClient.php', WPMBHUB_ModbusTcpClient::class],
    ['WPModbusHub/module.php', WPModbusHub::class],
] as [$file, $class]) {
    $src = file_get_contents(__DIR__ . '/../' . $file);
    preg_match_all('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $src, $m);
    $missing = [];
    foreach (array_unique($m[1]) as $method) {
        if (!method_exists($class, $method)) {
            $missing[] = $method;
        }
    }
    check("Alle \$this->…()-Aufrufe in $file definiert", count($missing) === 0, 'fehlt: ' . implode(', ', $missing));
}

// ---------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "Alle Pruefungen bestanden.\n";
    exit(0);
}
echo "$failures Pruefung(en) FEHLGESCHLAGEN.\n";
exit(1);
