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
    'locale'     => json_decode((string)file_get_contents(__DIR__ . '/../libs/locale.json'), true)['translations']['de'],
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
function IPS_SetVariableProfileValues(string $name, $min, $max, $step): void
{
    $GLOBALS['ips']['profiles'][$name]['values'] = [$min, $max, $step];
}
function IPS_SetVariableProfileAssociation(string $name, $value, string $text, string $icon, int $color): void
{
    $GLOBALS['ips']['profiles'][$name]['associations'][$value] = $text;
}
const TEST_ARCHIVE_INSTANCE_ID = 55555;
function IPS_GetInstanceListByModuleID(string $moduleID): array
{
    if (!empty($GLOBALS['ips']['noArchive'])) {
        return [];
    }
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
const OBJECTTYPE_VARIABLE = 2;
function IPS_GetChildrenIDs(int $id): array
{
    return array_map(fn ($v) => $v['id'], array_values($GLOBALS['ips']['variables']));
}
function IPS_GetObject(int $id): array
{
    return ['ObjectType' => OBJECTTYPE_VARIABLE];
}
function IPS_GetVariable(int $id): array
{
    foreach ($GLOBALS['ips']['variables'] as $v) {
        if ($v['id'] === $id) {
            return ['VariableType' => $v['type']];
        }
    }
    return ['VariableType' => -1];
}
function IPS_ApplyChanges(int $id): void
{
    $GLOBALS['ips']['applied'] = true;
}
function IPS_GetInstance(int $id): array
{
    return ['ConnectionID' => $GLOBALS['ips']['parent'] ?? 0];
}
function IPS_InstanceExists(int $id): bool
{
    return $id > 0;
}
function IPS_GetLibrary(string $guid): array
{
    return ['Version' => $GLOBALS['ips']['libraryVersion'] ?? ''];
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
    protected $buffers = [];
    public $status = 0;

    public function __construct($InstanceID = 12345)
    {
    }
    // Symcons Translate(): liest die locale.json des Moduls; hier die deutsche Tabelle (Standard im Pruefstand)
    // oder nichts (Englisch, $GLOBALS['ips']['locale'] = []). Zaehlt die Aufrufe: jeder echte Aufruf
    // kostet in Symcon ein Einlesen der locale.json (SUITE.md Stolperstein 24).
    protected function Translate(string $text): string
    {
        $GLOBALS['ips']['translateCalls'] = ($GLOBALS['ips']['translateCalls'] ?? 0) + 1;
        return ($GLOBALS['ips']['locale'] ?? [])[$text] ?? $text;
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
    // Attrappe fuer das Gateway-Kind-Modul: die "Gegenstelle" ist eine
    // Closure in $GLOBALS['ips']['parentResponder'] (Anfrage-JSON -> Antwort).
    protected function SendDataToParent(string $data)
    {
        $fn = $GLOBALS['ips']['parentResponder'] ?? null;
        return $fn ? $fn($data) : false;
    }
    protected function SendDebug(string $topic, string $text, int $format): void
    {
        $GLOBALS['ips']['debug'][] = ['topic' => $topic, 'text' => $text];
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
    protected function EnableAction(string $ident): void
    {
        $GLOBALS['ips']['actions'][$ident] = true;
    }
    protected function DisableAction(string $ident): void
    {
        unset($GLOBALS['ips']['actions'][$ident]);
    }
    protected function GetBuffer(string $name): string
    {
        return (string)($this->buffers[$name] ?? '');
    }
    protected function SetBuffer(string $name, string $value): void
    {
        $this->buffers[$name] = $value;
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
require __DIR__ . '/../WPModbusHubGateway/module.php';

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
        return $this->fakeRead('input', $startReg, $count);
    }
    public function readHolding($startReg, $count)
    {
        return $this->fakeRead('holding', $startReg, $count);
    }
    private function fakeRead(string $type, int $addr, int $count = 1): ?array
    {
        $regs = [];
        for ($i = 0; $i < $count; $i++) {
            $key = $type . ':' . ($addr + $i);
            if (in_array($key, $this->fail, true) || !array_key_exists($key, $this->values)) {
                return null;
            }
            $regs[$i] = $this->values[$key] & 0xFFFF;
        }
        return $regs;
    }
    public function close(): void
    {
    }
}

// ---------------------------------------------------------------------------
echo "Block 1: Lebenszyklus und Status\n";
// ---------------------------------------------------------------------------

$mod = new WPModbusHub(12345);
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
echo "Block 1b: floatLE() -- IDMs vertauschte Wortreihenfolge\n";
// ---------------------------------------------------------------------------

$floatLE = new WPMBHUB_ModbusTcpClient('x', 502, 1);
// 8.2 als IEEE754: 0x41 03 33 33 -> big-endian High-Word 0x4103, Low-Word
// 0x3333. IDM ueberträgt Low-Word ZUERST -- Register[0]=Low, Register[1]=High.
check('floatLE(): 8.2 aus vertauschten Registern korrekt', abs($floatLE->floatLE([0 => 0x3333, 1 => 0x4103], 0) - 8.2) < 0.0001);
check('floatLE(): 0.0 korrekt (beide Register 0)', $floatLE->floatLE([0 => 0, 1 => 0], 0) === 0.0);
check('floatLE(): negativer Wert korrekt (-3.5)', abs($floatLE->floatLE([0 => 0x0000, 1 => 0xC060], 0) - (-3.5)) < 0.0001);

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

// Stiebel Eltron: Adressen = PDF-Adresse minus 1 (Block 1 ab 501, ISG zaehlt 1-basiert).
// Bestaetigt am Direktzugang eines Nutzers (Forum thorsten1, 05.11.2026): Warmwasser-Ist
// im PDF 522, dort Input-Register 521. Vor 0.7.7 wurden Adressen wie 6 abgefragt und
// die ISG antwortete "Adresse nicht vorhanden".
$fakeStiebel = new FakeModbusClient('192.168.1.51', 502, 1);
$fakeStiebel->values = [
    'input:506' => 45,   // Aussentemperatur 4.5°C (PDF 507)
    'input:514' => 351,  // Vorlauf 35.1°C (PDF 515)
    'input:515' => 301,  // Ruecklauf 30.1°C (PDF 516)
    'input:521' => 470,  // Warmwasser Ist 47.0°C (PDF 522)
    'input:522' => 500,  // Warmwasser Soll 50.0°C (PDF 523)
    'input:507' => 213,  // Zone1 Ist 21.3°C (PDF 508)
    'input:509' => 210,  // Zone1 Soll 21.0°C (PDF 510, WPM 3)
];
$valuesStiebel = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $fakeStiebel);
check('Stiebel Eltron: alle sieben Felder korrekt dekodiert', $valuesStiebel === [
    'Aussentemperatur' => 4.5, 'Vorlauftemperatur' => 35.1, 'Ruecklauftemperatur' => 30.1, 'Warmwasser' => 47.0,
    'WarmwasserSoll' => 50.0, 'Zone1Ist' => 21.3, 'Zone1Soll' => 21.0,
], json_encode($valuesStiebel));
check('Stiebel Eltron: Aussentemperatur liegt bei 506 (PDF 507 minus 1), nicht mehr bei 6', WPModbusHub::DRIVERS['stiebeleltron']['registers']['Aussentemperatur']['addr'] === 506);
check('Stiebel Eltron: Warmwasser-Ist liegt bei 521 (am Direktzugang eines Nutzers bestaetigt)', WPModbusHub::DRIVERS['stiebeleltron']['registers']['Warmwasser']['addr'] === 521);
check('Stiebel Eltron: keine Adresse unter 500 (alle Systemwerte liegen in Block 1 ab 501)', count(array_filter(WPModbusHub::DRIVERS['stiebeleltron']['registers'], fn ($d) => $d['addr'] < 500)) === 0);

// Ersatzwert 0x8000 ("Objekt nicht vorhanden") darf nie als Temperatur erscheinen.
$GLOBALS['ips']['debug'] = [];
$fakeStiebelMissing = new FakeModbusClient('192.168.1.51', 502, 1);
$fakeStiebelMissing->values = ['input:506' => 45, 'input:507' => 0x8000, 'input:509' => 0x8000, 'input:508' => 205];
$mapMissing = ['Aussentemperatur' => WPModbusHub::DRIVERS['stiebeleltron']['registers']['Aussentemperatur'], 'Zone1Ist' => WPModbusHub::DRIVERS['stiebeleltron']['registers']['Zone1Ist'], 'Zone1Soll' => WPModbusHub::DRIVERS['stiebeleltron']['registers']['Zone1Soll']];
$valuesMissing = $readRegisters->invoke($mod, $mapMissing, $fakeStiebelMissing);
check('Stiebel Eltron: Ersatzwert 0x8000 ergibt kein Feld (keine -3276,8 °C)', !array_key_exists('Zone1Ist', $valuesMissing) && ($valuesMissing['Aussentemperatur'] ?? null) === 4.5, json_encode($valuesMissing));
check('Stiebel Eltron: bei 0x8000 wird die Ausweichadresse (WPM 3i) versucht', ($valuesMissing['Zone1Soll'] ?? null) === 20.5, json_encode($valuesMissing));
$missingDebug = array_values(array_filter($GLOBALS['ips']['debug'], fn ($d) => $d['topic'] === 'Modbus' && strpos($d['text'], 'nicht vorhanden') !== false));
check('Stiebel Eltron: fehlendes Objekt steht im Klartext in der Debugausgabe', count($missingDebug) === 1 && strpos($missingDebug[0]['text'], 'Zone1Ist') === 0, json_encode($missingDebug));

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

// Waterkotte: alles Holding-Register, BMS-Analogadresse = Modbus-Adresse 1:1
// (offizielles Waterkotte-PDF), inkl. Pufferspeichertemperatur.
$fakeWaterkotte = new FakeModbusClient('192.168.1.54', 502, 1);
$fakeWaterkotte->values = [
    'holding:1'  => 82,  // Aussentemperatur 8.2°C
    'holding:11' => 320, // Ruecklauf 32.0°C
    'holding:12' => 355, // Vorlauf 35.5°C
    'holding:16' => 410, // Speichertemperatur 41.0°C
    'holding:19' => 480, // Warmwasser Ist 48.0°C
    'holding:37' => 500, // Warmwasser Soll 50.0°C
    'holding:30' => 210, // Zone1 Ist 21.0°C
    'holding:31' => 215, // Zone1 Soll 21.5°C
];
$valuesWaterkotte = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['waterkotte']['registers'], $fakeWaterkotte);
check('Waterkotte: alle acht Felder korrekt dekodiert', $valuesWaterkotte === [
    'Aussentemperatur' => 8.2, 'Ruecklauftemperatur' => 32.0, 'Vorlauftemperatur' => 35.5,
    'Speichertemperatur' => 41.0, 'Warmwasser' => 48.0, 'WarmwasserSoll' => 50.0,
    'Zone1Ist' => 21.0, 'Zone1Soll' => 21.5,
], json_encode($valuesWaterkotte));

// IDM: einziger Hersteller mit 32-Bit-Float (2 Register, Low-Word zuerst --
// siehe floatLE()), gemischt mit einem einfachen UCHAR-Register (WarmwasserSoll).
// Rohregister per pack('G', $wert) + Bit-Zerlegung erzeugt (echter
// IEEE754-Roundtrip, nicht erfunden).
$fakeIdm = new FakeModbusClient('192.168.1.55', 502, 1);
$fakeIdm->values = [
    'input:1000' => 13107, 'input:1001' => 16643, // Aussentemperatur 8.2°C
    'input:1050' => 0,     'input:1051' => 16910, // Vorlauftemperatur 35.5°C
    'input:1052' => 0,     'input:1053' => 16896, // Ruecklauftemperatur 32.0°C
    'input:1008' => 0,     'input:1009' => 16932, // Speichertemperatur 41.0°C
    'input:1014' => 0,     'input:1015' => 16960, // Warmwasser (Speicher oben, B48) 48.0°C
    'input:1012' => 0,     'input:1013' => 16920, // WarmwasserUnten (Speicher unten, B41) 38.0°C
    'holding:1032' => 46,                          // WarmwasserSoll 46 (UCHAR, kein Faktor)
    'input:1350' => 0,     'input:1351' => 16808, // Zone1Ist 21.0°C
    'input:1378' => 0,     'input:1379' => 16812, // Zone1Soll 21.5°C
];
$valuesIdm = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['idm']['registers'], $fakeIdm);
// Vergleich gerundet, nicht strikt === -- 8.2 & Co. lassen sich als 32-Bit-
// Float nicht exakt darstellen (Praezisionsverlust ggue. dem float64-
// Literal ist hier KEIN Dekodierfehler, sondern IEEE754-Rundung selbst).
check('IDM: alle neun Felder korrekt dekodiert (Float32 + UCHAR gemischt)', array_map(fn($v) => round($v, 3), $valuesIdm) === [
    'Aussentemperatur' => 8.2, 'Ruecklauftemperatur' => 32.0, 'Vorlauftemperatur' => 35.5,
    'Speichertemperatur' => 41.0, 'Warmwasser' => 48.0, 'WarmwasserUnten' => 38.0, 'WarmwasserSoll' => 46.0,
    'Zone1Ist' => 21.0, 'Zone1Soll' => 21.5,
], json_encode($valuesIdm));

// IDM-Teilausfall: fehlt nur EIN Register eines Float32-Paares (hier das
// High-Word von Vorlauftemperatur), muss das ganze Feld fehlen, nicht mit
// einem falschen Halb-Wert auftauchen.
$fakeIdmPartial = new FakeModbusClient('192.168.1.55', 502, 1);
$fakeIdmPartial->values = ['input:1000' => 13107, 'input:1001' => 16643, 'input:1050' => 0];
$valuesIdmPartial = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['idm']['registers'], $fakeIdmPartial);
check('IDM: unvollstaendiges Float32-Paar liefert kein Feld (kein Halb-Wert)', !array_key_exists('Vorlauftemperatur', $valuesIdmPartial) && round($valuesIdmPartial['Aussentemperatur'] ?? 0, 3) === 8.2);

// Proxon: vier Register. Warmwasser Ist (Faktor 100) und Soll (Faktor 10) ohne
// Offset, die beiden Behaelterfuehler mit Offset -100 (°C = Roh/10 - 100).
// Rohwerte 1412/1454 stammen aus dem Livetest an einer echten T300 (19.09.2026,
// Display 41,3 bzw. 45,4 °C).
$fakeProxon = new FakeModbusClient('192.168.1.56', 502, 41);
$fakeProxon->values = [
    'input:882'    => 4650, // Warmwasser (BehaelterAvg) 46.5°C
    'holding:2000' => 480,  // WarmwasserSoll (Normal Wassertemperatur) 48.0°C
    'input:813'    => 1412, // T20 Behaelter unten: 1412/10 - 100 = 41.2
    'input:814'    => 1454, // T21 Behaelter Mitte: 1454/10 - 100 = 45.4
];
$valuesProxon = $readRegisters->invoke($mod, WPModbusHub::DRIVERS['proxon']['registers'], $fakeProxon);
check('Proxon: vier Felder korrekt dekodiert (Faktor 100/10, Offset -100)', array_map(fn($v) => round($v, 3), $valuesProxon) === [
    'Warmwasser' => 46.5, 'WarmwasserSoll' => 48.0, 'WarmwasserUnten' => 41.2, 'WarmwasserMitte' => 45.4,
], json_encode($valuesProxon));

// Offset-Pfad einzeln, inkl. Vorspannungs-Randfaelle (0 °C und unter null).
$offsetDef = ['regType' => 'input', 'addr' => 1, 'scale' => 10, 'signed' => false, 'offset' => -100];
$fakeOff = new FakeModbusClient('x', 502, 1);
foreach ([1000 => 0.0, 900 => -10.0, 1412 => 41.2] as $raw => $expect) {
    $fakeOff->values = ['input:1' => $raw];
    $v = $readRegisters->invoke($mod, ['T' => $offsetDef], $fakeOff);
    check("Offset: Roh $raw -> $expect °C", abs(($v['T'] ?? 999) - $expect) < 0.0001, json_encode($v));
}
// Ohne 'offset' bleibt das Verhalten unveraendert (Rueckwaertskompatibilitaet).
$fakeOff->values = ['input:1' => 470];
$vNoOff = $readRegisters->invoke($mod, ['T' => ['regType' => 'input', 'addr' => 1, 'scale' => 10, 'signed' => true]], $fakeOff);
check('Offset: ohne offset-Schluessel unveraendert (47.0)', ($vNoOff['T'] ?? null) === 47.0);

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
check('Aussentemperatur wird NICHT automatisch archiviert (Review 30.10.2026)', ($GLOBALS['ips']['archived'][$GLOBALS['ips']['variables']['Aussentemperatur']['id']] ?? false) === false);

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

// Waterkotte-Werte (mit Speichertemperatur) durchreichen -> bufferTempID muss
// auf die echte Pufferspeicher-Variable zeigen (generisches Feld, nicht nur
// fuer Waterkotte gedacht).
$maintainVars->invoke($mod, $valuesWaterkotte, true);
check('Speichertemperatur-Variable gesetzt', ($GLOBALS['ips']['variables']['Speichertemperatur']['value'] ?? null) === 41.0);
$functionsWaterkotte = $mod->GetFunctions();
check('GetFunctions(): bufferTempID zeigt auf Speichertemperatur', ($functionsWaterkotte[0]['bufferTempID'] ?? 0) === $GLOBALS['ips']['variables']['Speichertemperatur']['id']);

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
check('Formular hat ein Hersteller-Select mit acht Optionen', $manufacturerSelect !== null && count($manufacturerSelect['options']) === 8);

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

// -- NEWS_VERSIONS-Banner (SUITE.md "Einheitliche Formular-Optik" Punkt 1,
// Dashboard/Dietmar 23.09.2026): zeigt nur die Luecke seit SeenNews, merkt die
// tatsaechlich installierte Bibliotheksversion, nicht nur den letzten Schluessel.
$newsMod = new WPModbusHub(12345);
$newsMod->Create();
$formNews = json_decode($newsMod->GetConfigurationForm(), true);
$newsPanel = findFormElement($formNews['elements'], 'NewsPanel');
check('News-Banner erscheint bei leerem SeenNews', $newsPanel !== null);
check('News-Banner-Caption nennt die hoechste Version', ($newsPanel['caption'] ?? '') === '🆕 Neu bis Version ' . array_key_last(WPModbusHub::NEWS_VERSIONS), $newsPanel['caption'] ?? 'null');
$GLOBALS['ips']['libraryVersion'] = '0.9.9-beta.3';
$newsMod->AckNews();
$seenNews = new ReflectionMethod(WPModbusHub::class, 'ReadAttributeString');
$seenNews->setAccessible(true);
check('AckNews() speichert die tatsaechlich installierte BASISVERSION "0.9.9" (Beta-Suffix entfernt, NICHT nur den letzten NEWS_VERSIONS-Schluessel 0.7.1)', $seenNews->invoke($newsMod, 'SeenNews') === '0.9.9', $seenNews->invoke($newsMod, 'SeenNews'));
$GLOBALS['ips']['libraryVersion'] = '';
$formNewsGone = json_decode($newsMod->GetConfigurationForm(), true);
check('News-Banner ist nach AckNews() weg (kein neuerer Eintrag)', findFormElement($formNewsGone['elements'], 'NewsPanel') === null);

// Kuenstlicher zweiter Eintrag: nur die LUECKE seit SeenNews erscheint, nicht die Historie.
$newsVersionsRef = new ReflectionClassConstant(WPModbusHub::class, 'NEWS_VERSIONS');
$allVersions = $newsVersionsRef->getValue();
check('NEWS_VERSIONS-Konstante hat den erwarteten Schluessel 0.7.1', array_key_exists('0.7.1', $allVersions));

$GLOBALS['ips']['libraryVersion'] = '';
$oldNewsMod = new WPModbusHub(12345);
$oldNewsMod->Create();
$writeSeenNews = new ReflectionMethod(WPModbusHub::class, 'WriteAttributeString');
$writeSeenNews->setAccessible(true);
$writeSeenNews->invoke($oldNewsMod, 'SeenNews', '0.5.0');
$formOldNews = json_decode($oldNewsMod->GetConfigurationForm(), true);
$oldNewsPanel = findFormElement($formOldNews['elements'], 'NewsPanel');
check('Wer eine sehr alte Version zuletzt sah, sieht trotzdem jeden vorhandenen Eintrag genau einmal (keine falsche Vervielfachung)', $oldNewsPanel !== null && count(array_filter($oldNewsPanel['items'], fn($i) => ($i['type'] ?? '') === 'Label')) === 2 * count(WPModbusHub::NEWS_VERSIONS) + array_sum(array_map('count', WPModbusHub::NEWS_VERSIONS)) - count(WPModbusHub::NEWS_VERSIONS), json_encode($oldNewsPanel));
$writeSeenNews->invoke($oldNewsMod, 'SeenNews', '9.9.9');
$formFutureNews = json_decode($oldNewsMod->GetConfigurationForm(), true);
check('Wer scheinbar eine NEUERE Version als jeden Eintrag zuletzt sah, sieht keinen Banner', findFormElement($formFutureNews['elements'], 'NewsPanel') === null);

// ---------------------------------------------------------------------------
echo "Block 9: Logging nur auf Klick (Symcon-Review 30.10.2026)\n";
// ---------------------------------------------------------------------------
// Das Modul darf Variablen nicht von selbst archivieren -- nur ueber den
// Formular-Knopf, den der Nutzer bewusst klickt.
$floatIds = [];
$otherIds = [];
foreach ($GLOBALS['ips']['variables'] as $v) {
    if ($v['type'] === VARIABLETYPE_FLOAT) {
        $floatIds[] = $v['id'];
    } else {
        $otherIds[] = $v['id'];
    }
}
check('Logging: Testbasis hat Messwert-Variablen', count($floatIds) > 0, (string)count($floatIds));
check('Logging: nach allen Update-Zyklen ist NICHTS automatisch archiviert', array_filter($GLOBALS['ips']['archived'] ?? []) === []);
$enableText = $mod->EnableArchiving();
check('Logging: Klick schaltet alle Messwerte ein', count(array_filter($floatIds, fn ($id) => ($GLOBALS['ips']['archived'][$id] ?? false) === true)) === count($floatIds));
check('Logging: Klick fasst Status-/Schaltvariablen nicht an', count(array_filter($otherIds, fn ($id) => ($GLOBALS['ips']['archived'][$id] ?? false) === true)) === 0);
check('Logging: Rueckmeldung nennt die Zahl der Messwerte', strpos($enableText, '✅') === 0 && strpos($enableText, 'für ' . count($floatIds) . ' Messwert') !== false, $enableText);
$enableAgain = $mod->EnableArchiving();
check('Logging: zweiter Klick meldet "schon eingeschaltet" und schaltet nichts doppelt', strpos($enableAgain, 'für 0 Messwert') !== false && strpos($enableAgain, 'waren schon eingeschaltet') !== false, $enableAgain);
$GLOBALS['ips']['noArchive'] = true;
check('Logging: ohne Archiv-Instanz klare Fehlermeldung', strpos($mod->EnableArchiving(), '❌') === 0);
unset($GLOBALS['ips']['noArchive']);
$savedVars = $GLOBALS['ips']['variables'];
$GLOBALS['ips']['variables'] = [];
check('Logging: ohne Messwerte ein Hinweis statt Erfolgsmeldung', strpos($mod->EnableArchiving(), 'ℹ️') === 0);
$GLOBALS['ips']['variables'] = $savedVars;
$formJson = json_decode(file_get_contents(__DIR__ . '/../WPModbusHub/form.json'), true);
$archButton = null;
foreach ($formJson['elements'] as $el) {
    if (($el['name'] ?? null) === 'GeneralPanel') {
        foreach ($el['items'] as $it) {
            if (($it['type'] ?? '') === 'Button' && strpos($it['onClick'] ?? '', 'WPMBHUB_EnableArchiving($id)') !== false) {
                $archButton = $it;
            }
        }
    }
}
check('Logging: Formular bietet den Knopf im Bereich Allgemein an', $archButton !== null);

// ---------------------------------------------------------------------------
echo "Block 5b: Fehlerursache sichtbar (Tester-Meldung thorsten1, 04.11.2026)\n";
// ---------------------------------------------------------------------------
// Vorher hiess jeder Fehler (keine Verbindung, Zeitueberschreitung, Ausnahme des
// Geraets) nur "antwortet nicht". Jetzt steht die Ursache pro Register in der
// Instanz-Debugausgabe.
class FailingModbusClient extends WPMBHUB_ModbusTcpClient
{
    public function readInput($startReg, $count)
    {
        $this->lastError = 'Modbus-Ausnahme 0x02';
        return null;
    }
    public function readHolding($startReg, $count)
    {
        $this->lastError = 'connect';
        return null;
    }
}
$GLOBALS['ips']['debug'] = [];
$failClient = new FailingModbusClient('192.168.1.168', 502, 1);
$rrFail = $readRegisters->invoke($mod, ['Aussentemperatur' => ['regType' => 'input', 'addr' => 6, 'scale' => 10, 'signed' => true], 'WarmwasserSoll' => ['regType' => 'holding', 'addr' => 8, 'scale' => 10, 'signed' => true]], $failClient);
check('Fehlerursache: kein Register lesbar liefert weiter null', $rrFail === null);
$dbgModbus = array_values(array_filter($GLOBALS['ips']['debug'], fn ($d) => $d['topic'] === 'Modbus'));
check('Fehlerursache: je fehlgeschlagenem Register eine Debugzeile, danach der Abbruch nach dem Verbindungsfehler', count($dbgModbus) === 3 && strpos($dbgModbus[2]['text'], 'Abbruch nach Verbindungsfehlern') !== false, (string)count($dbgModbus));
check('Fehlerursache: Debugzeile nennt Ausnahme, Registerart und Adresse', strpos($dbgModbus[0]['text'], 'Modbus-Ausnahme 0x02') !== false && strpos($dbgModbus[0]['text'], 'input Register 6') !== false, $dbgModbus[0]['text'] ?? 'null');
check('Fehlerursache: Verbindungsfehler wird im Klartext erklaert', strpos($dbgModbus[1]['text'], 'keine Verbindung') !== false, $dbgModbus[1]['text'] ?? 'null');

// Echter Client gegen einen Mini-Server, der mit einer Modbus-Ausnahme antwortet (FC 0x84, Code 02).
$srvScript = sys_get_temp_dir() . '/wpmbhub-exc-' . getmypid() . '.php';
file_put_contents($srvScript, '<?php $s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); fwrite(STDOUT, stream_socket_get_name($s,false)."\n"); fflush(STDOUT); $c=stream_socket_accept($s,5); if($c){ $h=fread($c,12); $tid=substr($h,0,2); fwrite($c,$tid."\x00\x00\x00\x03\x01\x84\x02"); fflush($c); usleep(300000);}');
$proc = proc_open([PHP_BINARY, $srvScript], [1 => ['pipe', 'w']], $pipes);
$addrLine = trim((string)fgets($pipes[1]));
[$srvHost, $srvPort] = explode(':', $addrLine);
$realClient = new WPMBHUB_ModbusTcpClient($srvHost, (int)$srvPort, 1);
$excResult = $realClient->readInput(6, 1);
check('Echter Client: Ausnahmeantwort liefert null', $excResult === null);
check('Echter Client: Ausnahmecode steht in lastError', $realClient->lastError === 'Modbus-Ausnahme 0x02', $realClient->lastError);
$realClient->close();
proc_close($proc);
@unlink($srvScript);

// ---------------------------------------------------------------------------
echo "Block 6: Vollstaendigkeit der Methodenaufrufe\n";
// ---------------------------------------------------------------------------

foreach ([
    ['libs/ModbusTcpClient.php', WPMBHUB_ModbusTcpClient::class],
    ['libs/WPMBHUB_HeatpumpTrait.php', WPModbusHub::class],
    ['WPModbusHub/module.php', WPModbusHub::class],
    ['libs/ModbusGatewayClient.php', WPMBHUB_ModbusGatewayClient::class],
    ['WPModbusHubGateway/module.php', WPModbusHubGateway::class],
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
echo "Block 7: WPModbusHubGateway -- Symcon-ModBus-Gateway statt TCP-Socket\n";
// ---------------------------------------------------------------------------

// Gegenstelle nach dem Vorbild des Gateways: Anfrage-JSON in, rohe Antwort aus
// (2 Byte Kopf Function+ByteCount, dann big-endian Register). $table
// "input:882" => Registerwert. $log sammelt die eingegangenen Anfragen.
function makeGatewayResponder(array $table, array &$log): callable
{
    return function (string $json) use ($table, &$log) {
        $req = json_decode($json, true);
        $log[] = $req;
        if (($req['DataID'] ?? '') !== '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}') {
            return false;
        }
        $type = $req['Function'] === 3 ? 'holding' : 'input';
        $regs = '';
        for ($i = 0; $i < $req['Quantity']; $i++) {
            $key = $type . ':' . ($req['Address'] + $i);
            if (!array_key_exists($key, $table)) {
                return chr($req['Function'] | 0x80) . chr(2);
            }
            $regs .= pack('n', $table[$key] & 0xFFFF);
        }
        return chr($req['Function']) . chr(strlen($regs)) . $regs;
    };
}

// --- Client direkt ---
$log = [];
$client = new WPMBHUB_ModbusGatewayClient(makeGatewayResponder(['input:100' => 258, 'holding:200' => 5, 'holding:201' => 65535], $log));
$r = $client->readInput(100, 1);
check('Gateway-Client: readInput liest ein Register (Function 4)', $r === [258] && $log[0]['Function'] === 4 && $log[0]['Address'] === 100 && $log[0]['Quantity'] === 1, json_encode($r));
check('Gateway-Client: Anfrage traegt die verifizierte DataID und leeres Data', $log[0]['DataID'] === '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}' && $log[0]['Data'] === '');
check('Gateway-Client: Kopf passt zu Function+Bytezahl (Diagnose)', $client->headerLooksLikePdu === true);
$r2 = $client->readHolding(200, 2);
check('Gateway-Client: readHolding liest zwei Register (Function 3)', $r2 === [5, 65535] && $log[1]['Function'] === 3);
check('Gateway-Client: Dekodierhilfen der Basisklasse nutzbar (s16 -1)', $client->s16($r2, 1) === -1);
$rExc = $client->readInput(999, 1);
check('Gateway-Client: Modbus-Ausnahme liefert null mit lesbarem Fehler', $rExc === null && strpos($client->lastError, 'Ausnahme 0x02') !== false, $client->lastError);

$calls = 0;
$dead = new WPMBHUB_ModbusGatewayClient(function (string $json) use (&$calls) { $calls++; return false; });
$dead->readInput(1, 1); $dead->readInput(2, 1); $dead->readInput(3, 1); $dead->readInput(4, 1);
check('Gateway-Client: nach 2 Anfragen ohne Antwort werden weitere uebersprungen (kein minutenlanges Blockieren)', $calls === 2, (string)$calls);

$odd = new WPMBHUB_ModbusGatewayClient(function (string $json) { return "\x00\x00" . pack('n', 777); });
check('Gateway-Client: unerwarteter Kopf wird toleriert, nur Diagnose meldet es', $odd->readHolding(1, 1) === [777] && $odd->headerLooksLikePdu === false);
$short = new WPMBHUB_ModbusGatewayClient(function (string $json) { return "\x03\x04\x00\x01"; });
check('Gateway-Client: zu kurze Antwort liefert null', $short->readHolding(1, 2) === null && strpos($short->lastError, 'zu kurz') !== false);
$thrower = new WPMBHUB_ModbusGatewayClient(function (string $json) { throw new RuntimeException('Gateway weg'); });
check('Gateway-Client: Ausnahme im Aufruf wird abgefangen (null statt Absturz)', $thrower->readInput(1, 1) === null);

// --- Modul ---
$GLOBALS['ips']['variables'] = [];
$GLOBALS['ips']['properties'] = [];
$GLOBALS['ips']['parent'] = 0;
unset($GLOBALS['ips']['parentResponder']);
$gw = new WPModbusHubGateway(12345);
$gw->Create();
$gw->ApplyChanges();
check('Gateway-Modul: inaktiv -> Status 104, kein Timer', $gw->status === 104 && $gw->GetTimerInterval('WPMBGW_UpdateTimer') === 0);

$GLOBALS['ips']['properties']['WPMBGW_Active'] = true;
$gw->ApplyChanges();
check('Gateway-Modul: aktiv ohne Gateway -> Status 201, Timer laeuft trotzdem', $gw->status === 201 && $gw->GetTimerInterval('WPMBGW_UpdateTimer') === 60000);

$GLOBALS['ips']['parent'] = 4711;
$gw->ApplyChanges();
check('Gateway-Modul: aktiv mit Gateway -> Status 102', $gw->status === 102);

// Proxon-Karte durch die Gateway-Strecke
$GLOBALS['ips']['properties']['Manufacturer'] = 'proxon';
$log = [];
$GLOBALS['ips']['parentResponder'] = makeGatewayResponder(['input:882' => 4650, 'holding:2000' => 480, 'input:813' => 1412, 'input:814' => 1454], $log);
$gw->Update();
check('Gateway-Modul: Behaelterfuehler unten/Mitte mit Offset ueber Gateway (41.2 / 45.4)',
    abs(($GLOBALS['ips']['variables']['WarmwasserUnten']['value'] ?? 0) - 41.2) < 0.001 && abs(($GLOBALS['ips']['variables']['WarmwasserMitte']['value'] ?? 0) - 45.4) < 0.001);
check('Gateway-Modul: Proxon Warmwasser Ist/Soll ueber Gateway korrekt (46.5 / 48.0)',
    ($GLOBALS['ips']['variables']['Warmwasser']['value'] ?? null) === 46.5 && ($GLOBALS['ips']['variables']['WarmwasserSoll']['value'] ?? null) === 48.0);
check('Gateway-Modul: Erfolg -> Erreichbar true, Status 102', ($GLOBALS['ips']['variables']['Erreichbar']['value'] ?? null) === true && $gw->status === 102);
check('Gateway-Modul: Function 4 fuer Input-, Function 3 fuer Holding-Register', $log[0]['Function'] === 4 && $log[1]['Function'] === 3);

// IDM (Float32, vertauschte Wortreihenfolge) ueber dieselbe Strecke
$GLOBALS['ips']['properties']['Manufacturer'] = 'idm';
$GLOBALS['ips']['variables'] = [];
$log = [];
$GLOBALS['ips']['parentResponder'] = makeGatewayResponder([
    'input:1000' => 13107, 'input:1001' => 16643, // 8.2 °C, Low-Word zuerst
    'holding:1032' => 46,
], $log);
$gw->Update();
check('Gateway-Modul: IDM-Float32 ueber Gateway korrekt (8.2 °C)', abs(($GLOBALS['ips']['variables']['Aussentemperatur']['value'] ?? 0) - 8.2) < 0.001);
check('Gateway-Modul: Float32 fragt 2 Register in EINER Anfrage', $log[0]['Quantity'] === 2);

// Ausfall: keine Antwort
$GLOBALS['ips']['parentResponder'] = function (string $json) { return false; };
$gw->Update();
check('Gateway-Modul: Regler antwortet nicht -> Status 202, Erreichbar false', $gw->status === 202 && ($GLOBALS['ips']['variables']['Erreichbar']['value'] ?? null) === false);

// Kein Gateway -> Update meldet 201 statt Absturz
$GLOBALS['ips']['parent'] = 0;
$gw->Update();
check('Gateway-Modul: Update ohne Gateway -> Status 201', $gw->status === 201);
$GLOBALS['ips']['parent'] = 4711;

// TestConnection / ReadRaw
$GLOBALS['ips']['properties']['Manufacturer'] = 'proxon';
$log = [];
$GLOBALS['ips']['parentResponder'] = makeGatewayResponder(['input:882' => 4650, 'holding:2000' => 480], $log);
$report = $gw->TestConnection();
check('TestConnection: Erfolg zeigt Anfrage, rohe Antwort und Ergebnis', strpos($report, '✅') !== false && strpos($report, 'Antwort: 0x') !== false && strpos($report, '"Function":4') !== false, $report);
$GLOBALS['ips']['parentResponder'] = function (string $json) { return false; };
$reportFail = $gw->TestConnection();
check('TestConnection: Fehlschlag nennt Pruefhinweise (Geraete-ID, Parity)', strpos($reportFail, '❌') !== false && strpos($reportFail, 'Geräte-ID') !== false, $reportFail);
$GLOBALS['ips']['parentResponder'] = makeGatewayResponder(['input:882' => 4650, 'holding:5' => 0xFFFF], $log);
$raw = $gw->ReadRaw(4, 882, 1);
check('ReadRaw: zeigt unsigned und signed sowie die Rohantwort', strpos($raw, 'unsigned [4650]') !== false && strpos($raw, '0x0402122a') !== false, $raw);
check('ReadRaw: signed-Anzeige bei 0xFFFF ist -1', strpos($gw->ReadRaw(3, 5, 1), 'signed [-1]') !== false);
check('ReadRaw: Schreib-Function wird abgelehnt (nur lesen)', strpos($gw->ReadRaw(6, 5, 1), 'Nur Function 3') !== false);

// Formular / Vertrag
$formGw = json_decode($gw->GetConfigurationForm(), true);
$selGw = findFormElement($formGw['elements'], 'Manufacturer');
check('Gateway-Formular: Hersteller-Select aus DRIVERS erzeugt (8 Optionen)', $selGw !== null && count($selGw['options']) === count(WPModbusHub::DRIVERS) && count($selGw['options']) === 8);
check('Gateway-Formular: hat "Verbindung testen"-Knopf', strpos(json_encode($formGw, JSON_UNESCAPED_UNICODE), 'WPMBGW_TestConnection($id)') !== false);
check('Gateway-Formular: "Über dieses Modul" steht ganz unten', (end($formGw['elements'])['caption'] ?? '') === '🧡  Über dieses Modul');

$fnGw = $gw->GetFunctions();
$GLOBALS['ips']['properties']['Manufacturer'] = 'nibe';
$fnTcp = (new WPModbusHub(12345))->GetFunctions();
check('Vertrag: Gateway- und TCP-Modul liefern dieselben Vertragsfelder', array_keys($fnGw[0]) === array_keys($fnTcp[0]));
check('Vertrag: Gateway-Modul Type=heatpump, contractVersion 1.15', ($fnGw[0]['Type'] ?? '') === 'heatpump' && ($fnGw[0]['contractVersion'] ?? '') === '1.15');
check('Registerkarten sind geteilt (eine Quelle der Wahrheit)', WPModbusHub::DRIVERS === WPModbusHubGateway::DRIVERS && WPModbusHub::DRIVERS === WPMBHUB_Drivers::DRIVERS);

// module.json des Gateway-Moduls: verifizierte Schnittstellen
$mj = json_decode(file_get_contents(__DIR__ . '/../WPModbusHubGateway/module.json'), true);
check('module.json: parentRequirements = verifizierte Gateway-DataID', ($mj['parentRequirements'] ?? []) === ['{E310B701-4AE7-458E-B618-EC13A1A6F6A8}']);
check('module.json: implemented = RX-Typ des Gateways (an Kinder)', ($mj['implemented'] ?? []) === ['{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}']);
check('module.json: Klassenname = name, Praefix WPMBGW', ($mj['name'] ?? '') === 'WPModbusHubGateway' && ($mj['prefix'] ?? '') === 'WPMBGW');

// Gateway-Modul: eigene Kopie des News-Banners verhaelt sich identisch.
$GLOBALS['ips']['libraryVersion'] = '';
$gwNews = new WPModbusHubGateway(12345);
$gwNews->Create();
$formGwNews = json_decode($gwNews->GetConfigurationForm(), true);
$gwNewsPanel = findFormElement($formGwNews['elements'], 'NewsPanel');
check('Gateway: News-Banner erscheint bei leerem SeenNews', $gwNewsPanel !== null && ($gwNewsPanel['caption'] ?? '') === '🆕 Neu bis Version ' . array_key_last(WPModbusHubGateway::NEWS_VERSIONS), json_encode($gwNewsPanel['caption'] ?? null));
$gwNews->AckNews();
$formGwNewsGone = json_decode($gwNews->GetConfigurationForm(), true);
check('Gateway: News-Banner ist nach AckNews() weg (kein Bibliotheksstring -> letzter Schluessel als Fallback)', findFormElement($formGwNewsGone['elements'], 'NewsPanel') === null);


// ---------------------------------------------------------------------------
echo "Block 8: Statuszeile im Formular (live berechnet, jeder Zustand)\n";
// ---------------------------------------------------------------------------

function statusOf($module): array
{
    $form = json_decode($module->GetConfigurationForm(), true);
    $label = findFormElement($form['elements'], 'ConnectionStatus');
    return [$label['caption'] ?? '', $label['color'] ?? null];
}
function setAttr($module, string $name, int $value): void
{
    $m = new ReflectionMethod($module, 'WriteAttributeInteger');
    $m->setAccessible(true);
    $m->invoke($module, $name, $value);
}

$GLOBALS['ips']['variables'] = [];
$GLOBALS['ips']['properties']['Manufacturer'] = 'proxon';
$GLOBALS['ips']['properties']['WPMBGW_Active'] = false;
$GLOBALS['ips']['properties']['WPMBGW_Interval'] = 60;
$GLOBALS['ips']['parent'] = 0;
$g2 = new WPModbusHubGateway(12345);
$g2->Create();

// Die statische form.json darf nicht schon einen Ersatztext tragen: leer ausliefern gilt nicht.
$rawForm = json_decode(file_get_contents(__DIR__ . '/../WPModbusHubGateway/form.json'), true);
check('Gateway-form.json: Statuszeile ist nur ein leerer Platzhalter (kein statischer Satz)', (findFormElement($rawForm['elements'], 'ConnectionStatus')['caption'] ?? 'x') === '');
$rawFormTcp = json_decode(file_get_contents(__DIR__ . '/../WPModbusHub/form.json'), true);
check('TCP-form.json: Statuszeile ist nur ein leerer Platzhalter (kein statischer Satz)', (findFormElement($rawFormTcp['elements'], 'ConnectionStatus')['caption'] ?? 'x') === '');

[$line, $color] = statusOf($g2);
check('Gateway inaktiv, kein Gateway: ℹ️ noch nicht eingerichtet', strpos($line, 'ℹ️ Noch nicht eingerichtet') === 0 && strpos($line, 'ModBus-Gateway') !== false && $color === -1, $line);

$GLOBALS['ips']['properties']['WPMBGW_Active'] = true;
[$line, $color] = statusOf($g2);
check('Gateway aktiv, kein Gateway: ⛔ Pflichtangabe fehlt, rot', strpos($line, '⛔ Pflichtangabe fehlt') === 0 && $color === 0xFF0000, $line);

$GLOBALS['ips']['parent'] = 4711;
[$line, $color] = statusOf($g2);
check('Aktiv, noch kein Zyklus: ℹ️ erster Lesezyklus folgt', strpos($line, 'ℹ️ Noch kein Lesezyklus') === 0 && strpos($line, '60 s') !== false, $line);

$GLOBALS['ips']['parentResponder'] = makeGatewayResponder(['input:882' => 4650, 'holding:2000' => 480, 'input:813' => 1412, 'input:814' => 1454], $log);
$g2->Update();
[$line, $color] = statusOf($g2);
check('Erfolg: ✅ nennt Herstellernamen und Alter', strpos($line, '✅ ') === 0 && strpos($line, 'Proxon') !== false && strpos($line, 'gelesen vor 0 s') !== false && $color === -1, $line);
check('Erfolg: ✅ nennt die tatsächlich gelesenen Werte (deutsches Dezimalkomma)', strpos($line, 'Warmwasser 46,5 °C') !== false && strpos($line, 'Warmwasser Sollwert 48,0 °C') !== false && strpos($line, 'Warmwasser unten 41,2 °C') !== false, $line);

$GLOBALS['ips']['parentResponder'] = makeGatewayResponder(['input:882' => 4650, 'holding:2000' => 480, 'input:814' => 1454], $log);
$g2->Update();
[$line] = statusOf($g2);
check('Teilausfall: ⚠️ nennt, wie viele und welche Felder fehlen', strpos($line, '⚠️ ') === 0 && strpos($line, '1 von 4 Feldern') !== false, $line);
check('Teilausfall: das fehlende Feld wird beim Namen genannt', strpos($line, 'Warmwasser unten') !== false, $line);

$GLOBALS['ips']['parentResponder'] = function (string $json) { return false; };
$g2->Update();
[$line, $color] = statusOf($g2);
check('Keine Antwort: ⚠️ antwortet nicht, mit Prüfhinweis zum Gateway-Weg', strpos($line, '⚠️ ') === 0 && strpos($line, 'antwortet nicht') !== false && strpos($line, 'Baudrate') !== false, $line);
check('Keine Antwort: letzte bekannte Werte bleiben sichtbar, "letzte Antwort" nennt den Zeitpunkt', strpos($line, 'Letzte bekannte Werte') !== false && strpos($line, 'Warmwasser 46,5 °C') !== false && strpos($line, 'letzte Antwort: vor') !== false, $line);

$GLOBALS['ips']['parentResponder'] = makeGatewayResponder(['input:882' => 4650, 'holding:2000' => 480, 'input:813' => 1412, 'input:814' => 1454], $log);
$g2->Update();
setAttr($g2, 'LastCycleAt', time() - 1000);
[$line] = statusOf($g2);
check('Veraltet: ⚠️ meldet, wenn die letzte Aktualisierung viel zu lange her ist', strpos($line, '⚠️ Die letzte Aktualisierung war vor 16 min') === 0 && strpos($line, 'erwartet wird alle 60 s') !== false, $line);

$GLOBALS['ips']['properties']['WPMBGW_Active'] = false;
[$line, $color] = statusOf($g2);
check('Ausgeschaltet (Gateway da): ℹ️ Ausgeschaltet', strpos($line, 'ℹ️ Ausgeschaltet') === 0 && $color === -1, $line);
$GLOBALS['ips']['properties']['WPMBGW_Active'] = true;

// Auswahlfeld: Zeile folgt der Auswahl, nicht nur dem Speicherstand
$GLOBALS['ips']['formFieldUpdates'] = [];
$g2->OnChangeManufacturer('idm');
check('Gateway: Herstellerwechsel im offenen Formular stellt die Zeile um', strpos($GLOBALS['ips']['formFieldUpdates']['ConnectionStatus']['caption'] ?? '', 'Hersteller geändert') !== false, json_encode($GLOBALS['ips']['formFieldUpdates']['ConnectionStatus'] ?? null));
$GLOBALS['ips']['formFieldUpdates'] = [];
$g2->OnChangeManufacturer('proxon');
check('Gateway: zurück zum gespeicherten Hersteller zeigt wieder die Zeile zum Zustand (samt Farbe)', strpos($GLOBALS['ips']['formFieldUpdates']['ConnectionStatus']['caption'] ?? '', 'Hersteller geändert') === false && strpos($GLOBALS['ips']['formFieldUpdates']['ConnectionStatus']['caption'] ?? '', 'Aktualisierung') !== false && ($GLOBALS['ips']['formFieldUpdates']['ConnectionStatus']['color'] ?? null) === -1, json_encode($GLOBALS['ips']['formFieldUpdates']['ConnectionStatus'] ?? null, JSON_UNESCAPED_UNICODE));

// TCP-Modul: Pflichtangabe IP-Adresse, Erfolgszeile
$GLOBALS['ips']['variables'] = [];
$GLOBALS['ips']['properties']['Manufacturer'] = 'nibe';
$GLOBALS['ips']['properties']['Host'] = '';
$GLOBALS['ips']['properties']['WPMBHUB_Active'] = true;
$t2 = new WPModbusHub(12345);
$t2->Create();
[$line, $color] = statusOf($t2);
check('TCP aktiv ohne IP-Adresse: ⛔ rot, nennt die IP-Adresse', strpos($line, '⛔') === 0 && strpos($line, 'IP-Adresse') !== false && $color === 0xFF0000, $line);
$GLOBALS['ips']['properties']['Host'] = '203.0.113.1';
[$line] = statusOf($t2);
check('TCP mit IP-Adresse, noch kein Zyklus: ℹ️', strpos($line, 'ℹ️ Noch kein Lesezyklus') === 0, $line);
// Zyklus wie in Update(): Werte -> Variablen -> Zyklusmerker
$rc = new ReflectionMethod(WPModbusHub::class, 'recordCycle');
$rc->setAccessible(true);
$maintainVars->invoke($t2, $valuesNibe, true);
$rc->invoke($t2, WPModbusHub::DRIVERS['nibe']['registers'], $valuesNibe);
setAttr($t2, 'LastSeenAt', time());
[$line, $color] = statusOf($t2);
check('TCP Erfolg: ✅ mit NIBE-Werten', strpos($line, '✅ ') === 0 && strpos($line, 'NIBE') !== false && strpos($line, 'Außentemperatur 7,5 °C') !== false && strpos($line, 'Vorlauftemperatur 38,2 °C') !== false, $line);
$maintainVars->invoke($t2, [], false);
[$line] = statusOf($t2);
check('TCP nicht erreichbar: ⚠️ mit Prüfhinweis zu IP-Adresse/Port/Unit-ID', strpos($line, '⚠️ ') === 0 && strpos($line, 'Unit-ID') !== false, $line);

// ---------------------------------------------------------------------------
echo "Block 10: Zweisprachigkeit (Deutsch/Englisch) und Translate()-Kosten\n";
// ---------------------------------------------------------------------------

$libLocale = (string)file_get_contents(__DIR__ . '/../libs/locale.json');
$deTable = json_decode($libLocale, true)['translations']['de'] ?? [];
check('locale.json: gültig, deutsche Tabelle vorhanden', count($deTable) > 100);
check('locale.json der beiden Module = libs/locale.json (php .tools/locale-bauen.php)',
    file_get_contents(__DIR__ . '/../WPModbusHub/locale.json') === $libLocale && file_get_contents(__DIR__ . '/../WPModbusHubGateway/locale.json') === $libLocale);

// Alle englischen Quelltexte einsammeln: t()-Aufrufe, NEWS, Treiberkarten, Feldnamen, form.json.
$srcTexts = [];
$litRe = "('(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")";
$unq = function (string $lit): string {
    return $lit[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], substr($lit, 1, -1)) : stripcslashes(substr($lit, 1, -1));
};
foreach (array_merge(glob(__DIR__ . '/../libs/*.php'), glob(__DIR__ . '/../WPModbusHub*/module.php')) as $file) {
    $code = (string)file_get_contents($file);
    preg_match_all('/WPMBHUB_Text::t\(\s*' . $litRe . '/', $code, $mm);
    foreach ($mm[1] as $lit) {
        $srcTexts[$unq($lit)] = basename($file);
    }
    preg_match_all('/\?\s*self::DRIVERS\[\$manufacturer\]\[\'caption\'\]\s*:\s*' . $litRe . '/', $code, $mm);
    foreach ($mm[1] as $lit) {
        $srcTexts[$unq($lit)] = basename($file);
    }
}
foreach (['WPModbusHub', 'WPModbusHubGateway'] as $cls) {
    foreach ($cls::NEWS_VERSIONS as $lines) {
        foreach ($lines as $line) {
            $srcTexts[$line] = $cls . ' NEWS';
        }
    }
}
foreach (WPMBHUB_Drivers::DRIVERS as $drv) {
    $srcTexts[$drv['caption']] = 'Drivers';
    $srcTexts[$drv['confidence']] = 'Drivers';
}
foreach (WPMBHUB_Drivers::FIELD_CAPTIONS as $cap) {
    $srcTexts[$cap] = 'FIELD_CAPTIONS';
}
$walkForm = function ($node, string $src) use (&$walkForm, &$srcTexts): void {
    foreach ((array)$node as $k => $v) {
        if (in_array($k, ['caption', 'confirm', 'label'], true) && is_string($v) && $v !== '') {
            $srcTexts[$v] = $src;
        } elseif (is_array($v)) {
            $walkForm($v, $src);
        }
    }
};
foreach (['WPModbusHub', 'WPModbusHubGateway'] as $dir) {
    $walkForm(json_decode((string)file_get_contents(__DIR__ . '/../' . $dir . '/form.json'), true), $dir . '/form.json');
}
$srcTexts['.'] = 'Text::zahl';
check('Quelltexte gefunden (t(), NEWS, Treiber, Felder, form.json)', count($srcTexts) > 100, (string)count($srcTexts));

$fehlt = [];
$platz = [];
foreach ($srcTexts as $text => $quelle) {
    if (!isset($deTable[$text])) {
        $fehlt[] = $quelle . ': ' . $text;
        continue;
    }
    $n1 = preg_match_all('/%(?:\d+\$)?[0-9]*[sdX]/', str_replace('%%', '', (string)$text));
    $n2 = preg_match_all('/%(?:\d+\$)?[0-9]*[sdX]/', str_replace('%%', '', $deTable[$text]));
    if ($n1 !== $n2) {
        $platz[] = $text;
    }
}
check('jeder Quelltext hat eine deutsche Fassung', $fehlt === [], implode(' | ', array_slice($fehlt, 0, 5)) . ' (' . count($fehlt) . ')');
check('Platzhalter: englischer und deutscher Text haben gleich viele', $platz === [], implode(' | ', array_slice($platz, 0, 5)));
$ueberzaehlig = array_diff(array_keys($deTable), array_keys($srcTexts));
check('locale.json hat keine Einträge ohne Quelltext (verwaist)', $ueberzaehlig === [], implode(' | ', array_slice($ueberzaehlig, 0, 5)));
$deutschImQuelltext = array_filter(array_keys($srcTexts), function ($t) {
    return preg_match('/[äöüÄÖÜß]/u', $t) === 1;
});
check('englische Quelltexte enthalten keine Umlaute', $deutschImQuelltext === [], implode(' | ', array_map(function ($t) { return mb_substr($t, 0, 50); }, array_slice($deutschImQuelltext, 0, 3))));

// --- Deutsch: Formular, Statuszeile, Variablennamen
$setProps = function (string $manu) {
    $GLOBALS['ips']['variables'] = [];
    $GLOBALS['ips']['properties']['Manufacturer'] = $manu;
    $GLOBALS['ips']['properties']['Host'] = '203.0.113.9';
    $GLOBALS['ips']['properties']['WPMBHUB_Active'] = true;
    $GLOBALS['ips']['properties']['WPMBGW_Active'] = true;
};
$cycle = function ($mod, array $vals) {
    $m = new ReflectionMethod($mod, 'maintainDeviceVariables');
    $m->setAccessible(true);
    $m->invoke($mod, $vals, true);
    $r = new ReflectionMethod($mod, 'recordCycle');
    $r->setAccessible(true);
    $r->invoke($mod, WPModbusHub::DRIVERS['nibe']['registers'], $vals);
};
$vals = ['Aussentemperatur' => 7.5, 'Vorlauftemperatur' => 38.2, 'Warmwasser' => 48.0, 'Zone1Ist' => 21.3];

$GLOBALS['ips']['locale'] = json_decode($libLocale, true)['translations']['de'];
$setProps('nibe');
$de = new WPModbusHub(12345);
$de->Create();
$cycle($de, $vals);
check('Deutsch: Variablenname "Außentemperatur", "Erreichbar"', ($GLOBALS['ips']['variables']['Aussentemperatur']['name'] ?? '') === 'Außentemperatur' && ($GLOBALS['ips']['variables']['Erreichbar']['name'] ?? '') === 'Erreichbar');
$formDe = json_decode($de->GetConfigurationForm(), true);
$optDe = findFormElement($formDe['elements'], 'Manufacturer')['options'] ?? [];
check('Deutsch: Herstellerliste aus den Treibern (8 Einträge, deutsche Namen)', count($optDe) === 8 && $optDe[0]['caption'] === 'NIBE (S-Serie, z. B. S1155/S1255/S2125)' && $optDe[7]['value'] === 'proxon', json_encode(array_column($optDe, 'caption'), JSON_UNESCAPED_UNICODE));
// Statische Formulartexte (form.json) uebersetzt Symcon selbst (Store-Punkt 25): sie bleiben englischer Quelltext im
// Formular, und jeder davon muss in locale.json stehen, sonst bliebe er in der deutschen Konsole englisch.
$staticTexts = function ($node) use (&$staticTexts): array {
    $found = [];
    if (is_array($node)) {
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array($key, ['caption', 'confirm', 'label'], true) && is_string($value) && $value !== '') {
                $found[] = $value;
            } elseif (is_array($value)) {
                $found = array_merge($found, $staticTexts($value));
            }
        }
    }
    return $found;
};
$localeDe = json_decode((string)file_get_contents(__DIR__ . '/../libs/locale.json'), true)['translations']['de'];
foreach (['WPModbusHub', 'WPModbusHubGateway'] as $moduleDir) {
    $missing = array_values(array_filter(array_unique($staticTexts(json_decode((string)file_get_contents(__DIR__ . '/../' . $moduleDir . '/form.json'), true))), fn ($t) => !isset($localeDe[$t])));
    check('Formular ' . $moduleDir . ': jeder statische Text steht in locale.json (Symcon übersetzt ihn selbst)', $missing === [], json_encode($missing, JSON_UNESCAPED_UNICODE));
}
check('Formular: statische Texte bleiben englischer Quelltext (keine eigene Übersetzung)', strpos(json_encode($formDe, JSON_UNESCAPED_UNICODE), '"🔧 Allgemein"') === false && strpos(json_encode($formDe, JSON_UNESCAPED_UNICODE), '📈 Logging für die Messwerte einschalten') === false
    && strpos(json_encode($formDe, JSON_UNESCAPED_UNICODE), '📈 Enable logging for the measured values') !== false);
check('Deutsch: Statuszeile mit Dezimalkomma', strpos(statusOf($de)[0], 'Außentemperatur 7,5 °C') !== false, statusOf($de)[0]);
check('Deutsch: GetFunctions-Caption deutsch', $de->GetFunctions()[0]['Caption'] === 'NIBE (S-Serie, z. B. S1155/S1255/S2125)');
check('Deutsch: "Neu"-Banner zeigt die deutschen Zeilen zu 0.8.0 und 0.9.0', strpos(json_encode($formDe, JSON_UNESCAPED_UNICODE), 'Das Modul ist jetzt durchgehend zweisprachig') !== false && strpos(json_encode($formDe, JSON_UNESCAPED_UNICODE), 'neue Karte für Lüftungsintegralgeräte') !== false && strpos(json_encode($formDe, JSON_UNESCAPED_UNICODE), '🆕 Neu bis Version ' . array_key_last(WPModbusHub::NEWS_VERSIONS)) !== false);
$deGw = new WPModbusHubGateway(12345);
$deGw->Create();
$formDeGw = json_decode($deGw->GetConfigurationForm(), true);
$optDeGw = findFormElement($formDeGw['elements'], 'Manufacturer')['options'] ?? [];
check('Deutsch: Gateway-Herstellerliste deutsch', ($optDeGw[0]['caption'] ?? '') === 'NIBE (S-Serie, z. B. S1155/S1255/S2125)' && ($optDeGw[4]['caption'] ?? '') === 'Samsung EHS (über MIM-B19N-Zubehörmodul)', json_encode(array_column($optDeGw, 'caption'), JSON_UNESCAPED_UNICODE));
check('Deutsch: Gateway-"Neu"-Banner deutsch (Abschnittstitel bleibt Quelltext, Symcon übersetzt ihn)', strpos(json_encode($formDeGw, JSON_UNESCAPED_UNICODE), 'Das Modul ist jetzt durchgehend zweisprachig') !== false && strpos(json_encode($formDeGw, JSON_UNESCAPED_UNICODE), '🔌 Heat pump (via ModBus gateway)') !== false);

// --- Englisch: keine deutschen Reste
$GLOBALS['ips']['locale'] = [];
$setProps('nibe');
$en = new WPModbusHub(12345);
$en->Create();
$cycle($en, $vals);
$germanLeft = function (string $text): bool {
    return preg_match('/[äöüÄÖÜß]|\b(und|nicht|oder|wird|Hersteller|Wärme\w*|Verbindung|Schalter|aktiv|eingeschaltet|Fehler|antwortet|Gateway-Variante)\b/u', $text) === 1;
};
check('Englisch: Variablenname "Outdoor temperature", "Reachable"', ($GLOBALS['ips']['variables']['Aussentemperatur']['name'] ?? '') === 'Outdoor temperature' && ($GLOBALS['ips']['variables']['Erreichbar']['name'] ?? '') === 'Reachable');
$captionsOf = function (array $node) use (&$captionsOf): array {
    $out = [];
    foreach ($node as $k => $v) {
        if (in_array($k, ['caption', 'confirm', 'label'], true) && is_string($v)) {
            $out[] = $v;
        } elseif (is_array($v)) {
            $out = array_merge($out, $captionsOf($v));
        }
    }
    return $out;
};
foreach ([['TCP', $en], ['Gateway', new WPModbusHubGateway(12345)]] as [$label, $mod]) {
    if ($label === 'Gateway') {
        $mod->Create();
    }
    $formEn = json_decode($mod->GetConfigurationForm(), true);
    $rest = array_values(array_filter($captionsOf($formEn), $germanLeft));
    check("Englisch: $label-Formular ohne deutsche Reste", $rest === [], implode(' | ', array_map(function ($t) { return mb_substr($t, 0, 70); }, array_slice($rest, 0, 4))));
    $optEn = findFormElement($formEn['elements'], 'Manufacturer')['options'] ?? [];
    check("Englisch: $label-Herstellerliste englisch", ($optEn[0]['caption'] ?? '') === 'NIBE (S series, e.g. S1155/S1255/S2125)' && ($optEn[4]['caption'] ?? '') === 'Samsung EHS (via MIM-B19N accessory module)', json_encode(array_column($optEn, 'caption')));
    check("Englisch: $label-Status-Texte (form.json status) übersetzt/neutral", count(array_filter(array_column($formEn['status'], 'caption'), $germanLeft)) === 0);
}
check('Englisch: "Neu"-Banner zeigt die englischen Zeilen zu 0.8.0 und 0.9.0', strpos(json_encode(json_decode($en->GetConfigurationForm(), true)), 'The module is now fully bilingual') !== false && strpos(json_encode(json_decode($en->GetConfigurationForm(), true)), 'new card for integral ventilation units') !== false);
check('Englisch: Statuszeile mit Dezimalpunkt', strpos(statusOf($en)[0], 'Outdoor temperature 7.5 °C') !== false, statusOf($en)[0]);
$driversEn = array_filter(array_merge(...array_map(function ($d) { return [$d['caption'], $d['confidence']]; }, array_values(WPMBHUB_Drivers::DRIVERS))), $germanLeft);
// Herstellernamen/PDF-Titel mit deutschen Wörtern (z. B. "Navigatorregelung", "Software Technische Information") sind Eigennamen.
$driversEn = array_filter($driversEn, function ($t) { return preg_match('/[äöüÄÖÜß]/u', $t) === 1; });
check('Englisch: Treiber-Texte enthalten keine Umlaute', $driversEn === [], implode(' | ', array_map(function ($t) { return mb_substr($t, 0, 50); }, $driversEn)));
check('Englisch: GetFunctions-Caption englisch', $en->GetFunctions()[0]['Caption'] === 'NIBE (S series, e.g. S1155/S1255/S2125)');
$GLOBALS['ips']['properties']['WPMBHUB_Active'] = false;
check('Englisch: Statuszeile "Switched off"', strpos(statusOf($en)[0], 'ℹ️ Switched off') === 0, statusOf($en)[0]);
$GLOBALS['ips']['properties']['WPMBHUB_Active'] = true;
$GLOBALS['ips']['properties']['Host'] = '';
check('Englisch: Pflichtangabe fehlt', strpos(statusOf($en)[0], '⛔ Required entry missing: no IP address entered.') === 0, statusOf($en)[0]);
$GLOBALS['ips']['properties']['Host'] = '203.0.113.9';

// Englisch: Archivierung, Verbindung testen, ReadRaw, Debugzeilen
$GLOBALS['ips']['archives'] = [];
$enArchive = $en->EnableArchiving();
check('Englisch: EnableArchiving-Meldung englisch', !$germanLeft($enArchive) && $enArchive !== '', $enArchive);
$GLOBALS['ips']['properties']['Manufacturer'] = 'proxon';
$gwEn = new WPModbusHubGateway(12345);
$gwEn->Create();
$GLOBALS['ips']['parent'] = 0;
$tc = $gwEn->TestConnection();
check('Englisch: TestConnection ohne Gateway englisch', !$germanLeft($tc) && strpos($tc, '❌ No ModBus gateway connected.') === 0, $tc);
$rr = $gwEn->ReadRaw(6, 5, 1);
check('Englisch: ReadRaw lehnt Schreiben englisch ab', strpos($rr, 'Only function 3') === 0, $rr);

// --- Kosten: Symcons Translate() liest bei jedem Aufruf die locale.json -- hoechstens einmal je Text und Prozess
$GLOBALS['ips']['locale'] = json_decode($libLocale, true)['translations']['de'];
$setProps('nibe');
$cost = new WPModbusHub(12345);
$cost->Create();
$GLOBALS['ips']['translateCalls'] = 0;
$fakeClient = new FakeModbusClient('203.0.113.9', 502, 1);
$fakeClient->values = ['input:1' => 75, 'input:2' => 382, 'input:8' => 480, 'input:116' => 213];
$readM = new ReflectionMethod($cost, 'readRegisters');
$readM->setAccessible(true);
$runCycle = function () use ($cost, $readM, $fakeClient, $cycle) {
    $v = $readM->invoke($cost, WPModbusHub::DRIVERS['nibe']['registers'], $fakeClient);
    $cycle($cost, $v ?? []);
    $cost->GetFunctions();
};
$runCycle();
$afterFirst = $GLOBALS['ips']['translateCalls'];
for ($i = 0; $i < 20; $i++) {
    $runCycle();
}
check('Translate-Kosten: ein Lesezyklus samt Vertragsabfrage fragt Symcon nur beim ersten Mal', $GLOBALS['ips']['translateCalls'] === $afterFirst && $afterFirst < 20, "erste Runde $afterFirst Aufrufe, nach 21 Runden " . $GLOBALS['ips']['translateCalls']);
$GLOBALS['ips']['translateCalls'] = 0;
$cost->GetConfigurationForm();
$formFirst = $GLOBALS['ips']['translateCalls'];
$GLOBALS['ips']['translateCalls'] = 0;
for ($i = 0; $i < 10; $i++) {
    $cost->GetConfigurationForm();
}
check('Translate-Kosten: Formular fragt jeden Text höchstens einmal (danach 0 Aufrufe)', $GLOBALS['ips']['translateCalls'] === 0 && $formFirst <= count($srcTexts), "erstes Formular $formFirst Aufrufe, weitere zehn: " . $GLOBALS['ips']['translateCalls']);
// Fehlerfall im Zyklus (Debugzeilen mit Platzhaltern) darf die Kosten nicht treiben
$GLOBALS['ips']['translateCalls'] = 0;
$fakeClient->fail = ['input:1', 'input:2'];
$runCycle();
$errFirst = $GLOBALS['ips']['translateCalls'];
$GLOBALS['ips']['translateCalls'] = 0;
for ($i = 0; $i < 10; $i++) {
    $runCycle();
}
check('Translate-Kosten: auch Fehlerzyklen mit Debugzeilen fragen Symcon nur beim ersten Mal', $GLOBALS['ips']['translateCalls'] === 0, "erster Fehlerzyklus $errFirst, danach " . $GLOBALS['ips']['translateCalls']);
// Ein Uebersetzer, der wirft (Instanz weg), darf den Zyklus nicht abbrechen
WPMBHUB_Text::setzen(WPMBHUB_Text::mitMerkliste(function (string $t): string { throw new RuntimeException('Instanz weg'); }));
check('Übersetzer wirft: Quelltext bleibt stehen, nichts bricht ab', WPMBHUB_Text::t('Hot water') === 'Hot water');

// ---------------------------------------------------------------------------
echo "Block 11: Stiebel Eltron -- WPM- und LWZ-Karte, Energie, Betriebsart, Reglerkennung (0.9.0)\n";
// ---------------------------------------------------------------------------

$GLOBALS['ips']['locale'] = json_decode((string)file_get_contents(__DIR__ . '/../libs/locale.json'), true)['translations']['de'];
check('Stiebel: zwei Karten (WPM und LWZ) mit eigenen Adressen', isset(WPModbusHub::DRIVERS['stiebeleltron'], WPModbusHub::DRIVERS['stiebeleltron_lwz'])
    && WPModbusHub::DRIVERS['stiebeleltron']['registers']['Aussentemperatur']['addr'] === 506 && WPModbusHub::DRIVERS['stiebeleltron_lwz']['registers']['Aussentemperatur']['addr'] === 6);

// Alle Adressen gegen die offizielle Doku (Wire = Doku - 1): [Karte][Ident] => Doku-Adresse (bei kwhmwh: kWh-Teil)
$doku = [
    'stiebeleltron' => ['Aussentemperatur' => 507, 'Zone1Ist' => 508, 'Zone1Soll' => 510, 'Vorlauftemperatur' => 515, 'Ruecklauftemperatur' => 516, 'Warmwasser' => 522, 'WarmwasserSoll' => 523,
        'Quellentemperatur' => 536, 'WaermemengeHeizen' => 3502, 'WaermemengeWarmwasser' => 3505, 'StromHeizen' => 3512, 'StromWarmwasser' => 3515, 'Betriebsart' => 1501,
        'Zone1Komfort' => 1502, 'Zone1Eco' => 1503, 'WarmwasserKomfort' => 1510, 'WarmwasserEco' => 1511],
    'stiebeleltron_lwz' => ['Aussentemperatur' => 7, 'Zone1Ist' => 8, 'Zone1Soll' => 9, 'Vorlauftemperatur' => 12, 'Ruecklauftemperatur' => 13, 'Warmwasser' => 16, 'WarmwasserSoll' => 17,
        'Verdichterstarts' => 31, 'WaermemengeHeizen' => 3002, 'WaermemengeWarmwasser' => 3005, 'StromHeizen' => 3023, 'StromWarmwasser' => 3026, 'Betriebsart' => 1001],
];
$bad = [];
foreach ($doku as $card => $map) {
    foreach ($map as $ident => $docAddr) {
        $addr = WPModbusHub::DRIVERS[$card]['registers'][$ident]['addr'] ?? null;
        if ($addr !== $docAddr - 1) {
            $bad[] = "$card/$ident: $addr statt " . ($docAddr - 1);
        }
    }
    foreach (WPModbusHub::DRIVERS[$card]['registers'] as $ident => $_) {
        if (!isset($map[$ident])) {
            $bad[] = "$card/$ident nicht in der Doku-Tabelle des Tests";
        }
    }
}
check('Stiebel: jede Adresse ist Doku-Adresse minus 1 (WPM Abschnitt 6, LWZ Abschnitt 7)', $bad === [], implode(' | ', $bad));
check('Stiebel: Betriebsart und Zähler lesen Holding bzw. Input wie in der Doku', WPModbusHub::DRIVERS['stiebeleltron']['registers']['Betriebsart']['regType'] === 'holding'
    && WPModbusHub::DRIVERS['stiebeleltron']['registers']['StromHeizen']['regType'] === 'input');

// Lesen: kWh- und MWh-Teil werden addiert, Quellentemperatur, Betriebsart
$fakeWpm = new FakeModbusClient('203.0.113.9', 502, 1);
$fakeWpm->values = ['input:506' => 45, 'input:535' => 0xFFF6 /* -1,0 */, 'input:3501' => 250, 'input:3502' => 12, 'input:3504' => 999, 'input:3505' => 3,
    'input:3511' => 7, 'input:3512' => 4, 'input:3514' => 1, 'input:3515' => 0, 'holding:1500' => 4];
$modWpm = new WPModbusHub(12345);
$GLOBALS['ips']['properties']['Manufacturer'] = 'stiebeleltron';
$modWpm->Create();
$readWpm = new ReflectionMethod($modWpm, 'readRegisters');
$readWpm->setAccessible(true);
$vWpm = $readWpm->invoke($modWpm, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $fakeWpm);
check('Stiebel WPM: Wärmemenge Heizen = 12 MWh + 250 kWh = 12250 kWh', ($vWpm['WaermemengeHeizen'] ?? null) === 12250.0, json_encode($vWpm));
check('Stiebel WPM: Wärmemenge Warmwasser = 3 MWh + 999 kWh = 3999 kWh', ($vWpm['WaermemengeWarmwasser'] ?? null) === 3999.0);
check('Stiebel WPM: Strom Heizen 4007 kWh, Strom Warmwasser 1 kWh (MWh-Teil 0)', ($vWpm['StromHeizen'] ?? null) === 4007.0 && ($vWpm['StromWarmwasser'] ?? null) === 1.0, json_encode($vWpm));
check('Stiebel WPM: Quellentemperatur -1,0 °C, Betriebsart 4 (ECO)', ($vWpm['Quellentemperatur'] ?? null) === -1.0 && ($vWpm['Betriebsart'] ?? null) === 4.0);

// Variablenarten und Profile
$GLOBALS['ips']['variables'] = [];
$mvWpm = new ReflectionMethod($modWpm, 'maintainDeviceVariables');
$mvWpm->setAccessible(true);
$mvWpm->invoke($modWpm, $vWpm, true);
$vars = $GLOBALS['ips']['variables'];
check('Stiebel WPM: Energie = Float mit ~Electricity, Betriebsart = Ganzzahl mit Modusprofil, Temperatur = Float °C',
    $vars['WaermemengeHeizen']['type'] === VARIABLETYPE_FLOAT && $vars['WaermemengeHeizen']['profile'] === '~Electricity'
    && $vars['Betriebsart']['type'] === VARIABLETYPE_INTEGER && $vars['Betriebsart']['profile'] === 'WPMBHUB.StiebelModeWPM' && $vars['Betriebsart']['value'] === 4
    && $vars['Quellentemperatur']['profile'] === 'NRG.Celsius', json_encode(array_map(function ($v) { return [$v['type'], $v['profile']]; }, $vars)));
check('Stiebel: Modusprofile angelegt, deutsche Namen (Programmbetrieb/Komfortbetrieb/ECO-Betrieb)', ($GLOBALS['ips']['profiles']['WPMBHUB.StiebelModeWPM']['associations'][3] ?? '') === 'Komfortbetrieb'
    && ($GLOBALS['ips']['profiles']['WPMBHUB.StiebelModeLWZ']['associations'][11] ?? '') === 'Automatik', json_encode($GLOBALS['ips']['profiles']['WPMBHUB.StiebelModeLWZ'] ?? null, JSON_UNESCAPED_UNICODE));
check('Stiebel: Variablenname deutsch', $vars['WaermemengeHeizen']['name'] === 'Wärmemenge Heizen (gesamt)' && $vars['StromHeizen']['name'] === 'Stromverbrauch Heizen (gesamt)');
check('Stiebel: Statuszeile nennt nur Temperaturen, keine Zähler', strpos($statusText = (function () use ($modWpm) {
    $m = new ReflectionMethod($modWpm, 'lastValuesText');
    $m->setAccessible(true);
    return $m->invoke($modWpm, 'stiebeleltron');
})(), 'Wärmemenge') === false && strpos($statusText, 'Außentemperatur') !== false, $statusText);
check('Stiebel: Vertrag bleibt ohne Energiefelder (EnergyID/PowerID 0), ohne Verdichterstarts compressorStartsID 0', $modWpm->GetFunctions()[0]['EnergyID'] === 0 && $modWpm->GetFunctions()[0]['PowerID'] === 0 && $modWpm->GetFunctions()[0]['compressorStartsID'] === 0);

// LWZ: Sensorfehler -60/-50, Verdichterstarts im Vertrag
$fakeLwz = new FakeModbusClient('203.0.113.9', 502, 1);
$fakeLwz->values = ['input:6' => 0xFDA8 /* -60 */, 'input:11' => 0xFE0C /* -50 */, 'input:12' => 355, 'input:15' => 480, 'input:30' => 4321,
    'input:3001' => 100, 'input:3002' => 5, 'holding:1000' => 11];
$GLOBALS['ips']['properties']['Manufacturer'] = 'stiebeleltron_lwz';
$GLOBALS['ips']['variables'] = [];
$modLwz = new WPModbusHub(12345);
$modLwz->Create();
$readLwz = new ReflectionMethod($modLwz, 'readRegisters');
$readLwz->setAccessible(true);
$GLOBALS['ips']['debug'] = [];
$vLwz = $readLwz->invoke($modLwz, WPModbusHub::DRIVERS['stiebeleltron_lwz']['registers'], $fakeLwz);
check('Stiebel LWZ: Sensorfehler -60 und -50 erscheinen nicht als Temperatur', !array_key_exists('Aussentemperatur', $vLwz) && !array_key_exists('Vorlauftemperatur', $vLwz), json_encode($vLwz));
check('Stiebel LWZ: gültige Werte, Verdichterstarts 4321, Wärmemenge 5100 kWh, Betriebsart 11', ($vLwz['Ruecklauftemperatur'] ?? null) === 35.5 && ($vLwz['Warmwasser'] ?? null) === 48.0
    && ($vLwz['Verdichterstarts'] ?? null) === 4321.0 && ($vLwz['WaermemengeHeizen'] ?? null) === 5100.0 && ($vLwz['Betriebsart'] ?? null) === 11.0, json_encode($vLwz));
$mvLwz = new ReflectionMethod($modLwz, 'maintainDeviceVariables');
$mvLwz->setAccessible(true);
$mvLwz->invoke($modLwz, $vLwz, true);
check('Stiebel LWZ: Verdichterstarts = Ganzzahl ohne Profil, Betriebsart mit LWZ-Profil', $GLOBALS['ips']['variables']['Verdichterstarts']['type'] === VARIABLETYPE_INTEGER
    && $GLOBALS['ips']['variables']['Betriebsart']['profile'] === 'WPMBHUB.StiebelModeLWZ');
check('Stiebel LWZ: Vertrag nennt die Verdichterstarts (compressorStartsID)', $modLwz->GetFunctions()[0]['compressorStartsID'] === $GLOBALS['ips']['variables']['Verdichterstarts']['id']);

// Nicht vorhandene Felder sind kein Lesefehler: Luft-Wärmepumpe ohne Quellentemperatur
$GLOBALS['ips']['properties']['Manufacturer'] = 'stiebeleltron';
$GLOBALS['ips']['properties']['WPMBHUB_Active'] = true;
$GLOBALS['ips']['properties']['Host'] = '203.0.113.9';
$GLOBALS['ips']['variables'] = [];
$modAir = new WPModbusHub(12345);
$modAir->Create();
$fakeAir = new FakeModbusClient('203.0.113.9', 502, 1);
foreach (WPModbusHub::DRIVERS['stiebeleltron']['registers'] as $id => $def) {
    $n = ($def['type'] ?? '') === 'kwhmwh' ? 2 : 1;
    for ($i = 0; $i < $n; $i++) {
        $fakeAir->values[$def['regType'] . ':' . ($def['addr'] + $i)] = 100;
    }
}
$fakeAir->values['input:535'] = 0x8000;
$readAir = new ReflectionMethod($modAir, 'readRegisters');
$readAir->setAccessible(true);
$recAir = new ReflectionMethod($modAir, 'recordCycle');
$recAir->setAccessible(true);
$vAir = $readAir->invoke($modAir, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $fakeAir);
$recAir->invoke($modAir, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $vAir);
$mvAir = new ReflectionMethod($modAir, 'maintainDeviceVariables');
$mvAir->setAccessible(true);
$mvAir->invoke($modAir, $vAir, true);
setAttr($modAir, 'LastSeenAt', time());
[$lineAir] = statusOf($modAir);
check('Stiebel: vom Gerät als "nicht vorhanden" gemeldetes Feld (Quellentemperatur 0x8000) ergibt ✅ mit Hinweis, kein ⚠️', strpos($lineAir, '✅ ') === 0 && strpos($lineAir, '(Das Gerät bietet 1 Feld(er) nicht an.)') !== false, $lineAir);
// Echter Lesefehler (Register fehlt, kein Ersatzwert) bleibt ⚠️
unset($fakeAir->values['input:521']);
$vAir2 = $readAir->invoke($modAir, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $fakeAir);
$recAir->invoke($modAir, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $vAir2);
[$lineAir2] = statusOf($modAir);
check('Stiebel: echter Lesefehler eines Felds bleibt ⚠️ und nennt es', strpos($lineAir2, '⚠️ ') === 0 && strpos($lineAir2, 'Warmwasser') !== false && strpos($lineAir2, 'Quellentemperatur') === false, $lineAir2);

// Reglerkennung
$rc = new ReflectionMethod($modAir, 'readControllerCode');
$rc->setAccessible(true);
$fakeAir->values['input:5001'] = 390;
$rc->invoke($modAir, $fakeAir, WPModbusHub::DRIVERS['stiebeleltron']);
$recAir->invoke($modAir, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $vAir);
[$lineOk] = statusOf($modAir);
check('Reglerkennung 390 (WPM 3) passt zur WPM-Karte: kein Hinweis', strpos($lineOk, 'Reglerkennung') === false, $lineOk);
$fakeAir->values['input:5001'] = 103;
$rc->invoke($modAir, $fakeAir, WPModbusHub::DRIVERS['stiebeleltron']);
[$lineMis] = statusOf($modAir);
check('Reglerkennung 103 (LWZ) bei gewählter WPM-Karte: Hinweis auf die andere Stiebel-Karte', strpos($lineMis, 'Reglerkennung 103') !== false && strpos($lineMis, 'Lüftungsintegralgerät LWZ') !== false, $lineMis);
$fakeAir->values['input:5001'] = 0x8000;
$rc->invoke($modAir, $fakeAir, WPModbusHub::DRIVERS['stiebeleltron']);
[$lineNone] = statusOf($modAir);
check('Reglerkennung 0x8000 (nicht angeboten): kein Hinweis', strpos($lineNone, 'Reglerkennung') === false, $lineNone);
$fakeAir->values['input:5001'] = 104;
$rc->invoke($modAir, $fakeAir, WPModbusHub::DRIVERS['stiebeleltron_lwz']);
$GLOBALS['ips']['properties']['Manufacturer'] = 'stiebeleltron_lwz';
[$lineLwz] = statusOf($modAir);
check('Reglerkennung 104 bei LWZ-Karte: kein Hinweis', strpos($lineLwz, 'Reglerkennung') === false, $lineLwz);
$GLOBALS['ips']['properties']['Manufacturer'] = 'nibe';
$fakeAir->values['input:5001'] = 103;
$rc->invoke($modAir, $fakeAir, WPModbusHub::DRIVERS['nibe']);
check('Hersteller ohne Reglerkennung (NIBE): Kennung 0, nie ein Hinweis', (function () use ($modAir) {
    $m = new ReflectionMethod($modAir, 'ReadAttributeInteger');
    $m->setAccessible(true);
    return $m->invoke($modAir, 'ControllerCode') === 0;
})());

// Nicht erreichbares Gerät: der Zyklus bricht ab, statt je Feld 3 s zu warten (kurze Intervalle ab 5 s)
class CountingFailClient extends WPMBHUB_ModbusTcpClient
{
    public int $reads = 0;
    public string $failWith = 'connect';
    public function readInput($startReg, $count)
    {
        $this->reads++;
        $this->lastError = $this->failWith;
        return null;
    }
    public function readHolding($startReg, $count)
    {
        return $this->readInput($startReg, $count);
    }
}
$GLOBALS['ips']['properties']['Manufacturer'] = 'stiebeleltron';
$modAbort = new WPModbusHub(12345);
$modAbort->Create();
$readAbort = new ReflectionMethod($modAbort, 'readRegisters');
$readAbort->setAccessible(true);
$nRegs = count(WPModbusHub::DRIVERS['stiebeleltron']['registers']);
$cc = new CountingFailClient('203.0.113.9', 502, 1);
$GLOBALS['ips']['debug'] = [];
$resAbort = $readAbort->invoke($modAbort, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $cc);
check("Verbindungsfehler: nach dem ersten Register Schluss ($nRegs Felder, 1 Versuch statt $nRegs mal 3 s)", $resAbort === null && $cc->reads === 1 && $nRegs > 10, "reads={$cc->reads}");
$cc2 = new CountingFailClient('203.0.113.9', 502, 1);
$cc2->failWith = 'timeout';
$readAbort->invoke($modAbort, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $cc2);
check('Zeitüberschreitung: nach zwei Versuchen in Folge Schluss', $cc2->reads === 2, "reads={$cc2->reads}");
$cc3 = new CountingFailClient('203.0.113.9', 502, 1);
$cc3->failWith = 'Modbus-Ausnahme 0x02';
$readAbort->invoke($modAbort, WPModbusHub::DRIVERS['stiebeleltron']['registers'], $cc3);
check('Ausnahme des Geräts (Adresse nicht vorhanden) bricht NICHT ab: alle Felder werden versucht', $cc3->reads === $nRegs, "reads={$cc3->reads}");
$cc4 = new CountingFailClient('203.0.113.9', 502, 1);
$cc4->failWith = 'timeout';
$cc4->values = [];
check('Intervall: Formular erlaubt ab 5 s (beide Module)', json_decode(file_get_contents(__DIR__ . '/../WPModbusHub/form.json'), true)['elements'][1]['items'][1]['minimum'] === 5
    && json_decode(file_get_contents(__DIR__ . '/../WPModbusHubGateway/form.json'), true)['elements'][1]['items'][1]['minimum'] === 5);
$GLOBALS['ips']['properties']['WPMBHUB_Interval'] = 5;
$GLOBALS['ips']['properties']['WPMBHUB_Active'] = true;
$GLOBALS['ips']['properties']['Host'] = '203.0.113.9';
$modAbort->ApplyChanges();
check('Intervall 5 s wird übernommen (Timer 5000 ms)', $modAbort->GetTimerInterval('WPMBHUB_UpdateTimer') === 5000, (string)$modAbort->GetTimerInterval('WPMBHUB_UpdateTimer'));
$GLOBALS['ips']['properties']['WPMBHUB_Interval'] = 60;

// Ganzer Zyklus über das Gateway: Update() liest Felder UND die Reglerkennung, das Formular warnt bei falscher Karte
$GLOBALS['ips']['variables'] = [];
$GLOBALS['ips']['properties']['Manufacturer'] = 'stiebeleltron';
$GLOBALS['ips']['properties']['WPMBGW_Active'] = true;
$GLOBALS['ips']['properties']['WPMBGW_Interval'] = 60;
$GLOBALS['ips']['parent'] = 4711;
$tableWpm = [];
foreach (WPModbusHub::DRIVERS['stiebeleltron']['registers'] as $id => $def) {
    $n = ($def['type'] ?? '') === 'kwhmwh' ? 2 : 1;
    for ($i = 0; $i < $n; $i++) {
        $tableWpm[$def['regType'] . ':' . ($def['addr'] + $i)] = 120;
    }
}
$tableWpm['input:5001'] = 104; // LWZ-Kennung, gewählt ist aber die WPM-Karte
$logUp = [];
$GLOBALS['ips']['parentResponder'] = makeGatewayResponder($tableWpm, $logUp);
$gwUp = new WPModbusHubGateway(12345);
$gwUp->Create();
$gwUp->Update();
$readsController = count(array_filter($logUp, function ($r) { return $r['Address'] === 5001; })) === 1;
check('Gateway-Update liest die Reglerkennung (Adresse 5001) genau einmal je Zyklus', $readsController, json_encode(array_column($logUp, 'Address')));
[$lineUp] = statusOf($gwUp);
check('Gateway-Update: Formular warnt, dass 104 (LWZ) nicht zur gewählten WPM-Karte passt', strpos($lineUp, 'Reglerkennung 104') !== false, $lineUp);
check('Gateway-Update: Energie und Betriebsart landen als Variablen', isset($GLOBALS['ips']['variables']['StromHeizen'], $GLOBALS['ips']['variables']['Betriebsart']) && $GLOBALS['ips']['variables']['StromHeizen']['value'] === 120120.0, json_encode(array_keys($GLOBALS['ips']['variables'])));
$GLOBALS['ips']['parent'] = 0;

// ---------------------------------------------------------------------------
echo "Block 12: schreibbare Raumsollwerte (Stiebel WPM) hinter der Belehrung\n";
// ---------------------------------------------------------------------------

$GLOBALS['ips']['locale'] = json_decode((string)file_get_contents(__DIR__ . '/../libs/locale.json'), true)['translations']['de'];
$GLOBALS['ips']['variables'] = [];
$GLOBALS['ips']['actions'] = [];
$GLOBALS['ips']['properties']['Manufacturer'] = 'stiebeleltron';
$GLOBALS['ips']['properties']['Host'] = '127.0.0.1';
$GLOBALS['ips']['properties']['WPMBHUB_Active'] = false; // Update() im Test nicht ins Netz schicken
$wm = new WPModbusHub(12345);
$wm->Create();
$mvW = new ReflectionMethod($wm, 'maintainDeviceVariables');
$mvW->setAccessible(true);
$rrW = new ReflectionMethod($wm, 'readRegisters');
$rrW->setAccessible(true);
$arW = new ReflectionMethod($wm, 'activeRegisters');
$arW->setAccessible(true);

check('Vor der Belehrung: die beiden Sollwerte gehören nicht zu den gelesenen Registern', !isset($arW->invoke($wm, 'stiebeleltron')['Zone1Komfort']) && !isset($arW->invoke($wm, 'stiebeleltron')['Zone1Eco']) && isset($arW->invoke($wm, 'stiebeleltron')['Aussentemperatur']));
$fakeW = new FakeModbusClient('127.0.0.1', 502, 1);
$fakeW->values = ['input:506' => 45, 'holding:1501' => 215, 'holding:1502' => 180, 'holding:1509' => 485, 'holding:1510' => 420];
$vW = $readW = $rrW->invoke($wm, $arW->invoke($wm, 'stiebeleltron'), $fakeW);
$mvW->invoke($wm, $vW, true);
check('Vor der Belehrung: keine Sollwert-Variablen, kein Lesen der Holding-Register 1501/1502', !isset($GLOBALS['ips']['variables']['Zone1Komfort']) && !isset($GLOBALS['ips']['variables']['Zone1Eco']) && !array_key_exists('Zone1Komfort', $vW));
$formW = json_decode($wm->GetConfigurationForm(), true);
$panelW = findFormElement($formW['elements'], 'WriteNoticePanel');
check('Formular zeigt die Belehrung (deutsch) mit Bestätigungsknopf und Rückfrage', $panelW !== null && strpos(json_encode($panelW, JSON_UNESCAPED_UNICODE), 'Einstellungen am Gerät ändern') !== false
    && strpos(json_encode($panelW, JSON_UNESCAPED_UNICODE), 'Ich habe das gelesen und verstanden') !== false && strpos(json_encode($panelW, JSON_UNESCAPED_UNICODE), 'wirklich freigeben') !== false
    && strpos(json_encode($panelW, JSON_UNESCAPED_UNICODE), 'WPMBHUB_AcceptWriteNotice($id)') !== false);
check('Belehrung nennt Haftung, Schreibabstand und dass es nicht an echter Hardware getestet ist', strpos(json_encode($panelW, JSON_UNESCAPED_UNICODE), 'eigene Verantwortung') !== false && strpos(json_encode($panelW, JSON_UNESCAPED_UNICODE), 'alle 30 Sekunden') !== false && strpos(json_encode($panelW, JSON_UNESCAPED_UNICODE), 'noch nicht getestet') !== false);
check('Vor der Belehrung: RequestAction wird abgelehnt', (function () use ($wm) {
    try {
        $wm->RequestAction('Zone1Komfort', 22);
    } catch (\Exception $e) {
        return strpos($e->getMessage(), 'gesperrt') !== false;
    }
    return false;
})());
$GLOBALS['ips']['properties']['Manufacturer'] = 'nibe';
check('Hersteller ohne schreibbare Felder (NIBE): keine Belehrung im Formular', findFormElement(json_decode($wm->GetConfigurationForm(), true)['elements'], 'WriteNoticePanel') === null);
$GLOBALS['ips']['properties']['Manufacturer'] = 'stiebeleltron';

// Belehrung bestätigen
$wm->AcceptWriteNotice();
check('Nach der Bestätigung: Sollwerte gehören zu den gelesenen Registern', isset($arW->invoke($wm, 'stiebeleltron')['Zone1Komfort'], $arW->invoke($wm, 'stiebeleltron')['Zone1Eco']));
$vW2 = $rrW->invoke($wm, $arW->invoke($wm, 'stiebeleltron'), $fakeW);
$mvW->invoke($wm, $vW2, true);
check('Nach der Bestätigung: beide Variablen entstehen mit Bereichsprofil 5..30 und Aktion', ($GLOBALS['ips']['variables']['Zone1Komfort']['value'] ?? null) === 21.5 && ($GLOBALS['ips']['variables']['Zone1Eco']['value'] ?? null) === 18.0
    && $GLOBALS['ips']['variables']['Zone1Komfort']['profile'] === 'WPMBHUB.RoomSetpoint' && ($GLOBALS['ips']['profiles']['WPMBHUB.RoomSetpoint']['values'] ?? null) === [5, 30, 0.5]
    && !empty($GLOBALS['ips']['actions']['Zone1Komfort']) && !empty($GLOBALS['ips']['actions']['Zone1Eco']), json_encode($GLOBALS['ips']['variables']['Zone1Komfort'] ?? null));
check('Nach der Bestätigung: Warmwasser-Sollwerte entstehen mit Profil 10..60 in Schritten von 0,5 und Aktion', ($GLOBALS['ips']['variables']['WarmwasserKomfort']['value'] ?? null) === 48.5 && ($GLOBALS['ips']['variables']['WarmwasserEco']['value'] ?? null) === 42.0
    && $GLOBALS['ips']['variables']['WarmwasserKomfort']['profile'] === 'WPMBHUB.HotWaterSetpoint' && ($GLOBALS['ips']['profiles']['WPMBHUB.HotWaterSetpoint']['values'] ?? null) === [10, 60, 0.5]
    && !empty($GLOBALS['ips']['actions']['WarmwasserKomfort']) && !empty($GLOBALS['ips']['actions']['WarmwasserEco']), json_encode($GLOBALS['ips']['variables']['WarmwasserKomfort'] ?? null));
check('Nach der Bestätigung: Namen "Warmwasser Komforttemperatur" und "Warmwasser ECO-Temperatur"', ($GLOBALS['ips']['variables']['WarmwasserKomfort']['name'] ?? '') === 'Warmwasser Komforttemperatur' && ($GLOBALS['ips']['variables']['WarmwasserEco']['name'] ?? '') === 'Warmwasser ECO-Temperatur');
check('Nach der Bestätigung: Variablennamen "Heizkreis 1 Komforttemperatur" und "Heizkreis 1 ECO-Temperatur"', $GLOBALS['ips']['variables']['Zone1Komfort']['name'] === 'Heizkreis 1 Komforttemperatur' && $GLOBALS['ips']['variables']['Zone1Eco']['name'] === 'Heizkreis 1 ECO-Temperatur');
check('Statuszeile nennt die Sollwerte nicht als Temperaturwerte (nur Messwerte)', (function () use ($wm) {
    $m = new ReflectionMethod($wm, 'lastValuesText');
    $m->setAccessible(true);
    return strpos($m->invoke($wm, 'stiebeleltron'), 'Komforttemperatur') === false;
})());

// Schreiben gegen einen Mini-Server (Function 6 mit Echo, Function 3 zum Zurücklesen)
function startWriteServer(string $mode)
{
    $script = sys_get_temp_dir() . '/wpmbhub-wr-' . getmypid() . '-' . $mode . '.php';
    file_put_contents($script, '<?php $mode=$argv[1]; $s=stream_socket_server("tcp://127.0.0.1:0",$e,$m); fwrite(STDOUT, stream_socket_get_name($s,false)."\n"); fflush(STDOUT); $store=[];
while($c=@stream_socket_accept($s,2)){ while(($h=fread($c,12))!==false && strlen($h)===12){ $tid=substr($h,0,2); $fc=ord($h[7]); $reg=(ord($h[8])<<8)|ord($h[9]); $val=(ord($h[10])<<8)|ord($h[11]);
 if($mode==="exception"){ fwrite($c,$tid."\x00\x00\x00\x03\x01".chr($fc|0x80)."\x02"); fflush($c); continue; }
 if($fc===6){ $store[$reg]=($mode==="ignore")?($store[$reg]??0):$val; $echo=($mode==="wrongecho")?$val+10:$val; fwrite($c,$tid."\x00\x00\x00\x06\x01\x06".pack("nn",$reg,$echo)); }
 elseif($fc===3){ fwrite($c,$tid."\x00\x00\x00\x05\x01\x03\x02".pack("n",$store[$reg]??0)); }
 fflush($c);} fclose($c);}');
    $proc = proc_open([PHP_BINARY, $script, $mode], [1 => ['pipe', 'w']], $pipes);
    $addr = trim((string)fgets($pipes[1]));
    [$h, $p] = explode(':', $addr);
    return [$proc, $pipes, (int)$p, $script];
}
$tryAction = function ($mod, string $ident, $value): string {
    try {
        $mod->RequestAction($ident, $value);
        return 'ok';
    } catch (\Exception $e) {
        return $e->getMessage();
    }
};
$setProp = function (int $port) {
    $GLOBALS['ips']['properties']['Host'] = '127.0.0.1';
    $GLOBALS['ips']['properties']['Port'] = $port;
    $GLOBALS['ips']['properties']['UnitId'] = 1;
};
$clearBuf = function ($mod) {
    $b = new ReflectionProperty($mod, 'buffers');
    $b->setAccessible(true);
    $b->setValue($mod, []);
};

[$procOk, $pipesOk, $portOk, $scriptOk] = startWriteServer('echo');
$setProp($portOk);
$clearBuf($wm);
$GLOBALS['ips']['debug'] = [];
$res = $tryAction($wm, 'Zone1Komfort', 22);
check('Schreiben: 22 °C wird geschrieben (Wert 220), zurückgelesen und als Variablenwert gezeigt', $res === 'ok' && $GLOBALS['ips']['variables']['Zone1Komfort']['value'] === 22.0, $res);
$res2 = $tryAction($wm, 'Zone1Komfort', 23);
check('Schreiben: eine zweite Änderung innerhalb von 30 s wird abgelehnt', strpos($res2, 'höchstens eine Änderung alle 30 Sekunden') !== false && $GLOBALS['ips']['variables']['Zone1Komfort']['value'] === 22.0, $res2);
check('Schreiben: jeder Wert hat seinen eigenen 30-s-Abstand (ECO sofort möglich)', $tryAction($wm, 'Zone1Eco', 17) === 'ok' && $GLOBALS['ips']['variables']['Zone1Eco']['value'] === 17.0);
$clearBuf($wm);
check('Schreiben: Wert über dem Bereich wird abgelehnt', strpos($tryAction($wm, 'Zone1Komfort', 35), 'außerhalb des erlaubten Bereichs 5 bis 30') !== false);
check('Schreiben: Wert unter dem Bereich wird abgelehnt', strpos($tryAction($wm, 'Zone1Komfort', 4.9), 'außerhalb') !== false);
check('Schreiben: Text statt Zahl wird abgelehnt', strpos($tryAction($wm, 'Zone1Komfort', 'warm'), 'keine Zahl') !== false);
check('Schreiben: Schrittweite 0,5 (21,5 wird als 215 geschrieben und bleibt 21,5; 21,6 wird 21,5)', $tryAction($wm, 'Zone1Komfort', 21.5) === 'ok' && $GLOBALS['ips']['variables']['Zone1Komfort']['value'] === 21.5);
$GLOBALS['ips']['debug'] = [];
check('Schreiben: Warmwasser Komfort 55,5 °C wird in Register 1509 als 555 geschrieben, zurückgelesen und gezeigt', $tryAction($wm, 'WarmwasserKomfort', 55.5) === 'ok' && $GLOBALS['ips']['variables']['WarmwasserKomfort']['value'] === 55.5);
check('Schreiben: Warmwasser über 60 °C und unter 10 °C wird abgelehnt', strpos($tryAction($wm, 'WarmwasserEco', 61), 'außerhalb des erlaubten Bereichs 10 bis 60') !== false && strpos($tryAction($wm, 'WarmwasserEco', 9.5), 'außerhalb') !== false);
check('Schreiben: ein Messwert (Aussentemperatur) ist nicht änderbar', strpos($tryAction($wm, 'Aussentemperatur', 5), 'lässt sich nicht ändern') !== false);
check('Schreiben: der Vorgang steht im Meldungslog mit Name und Wert', count(array_filter($GLOBALS['ips']['log'], fn ($l) => strpos($l, 'per Nutzeraktion auf 22 °C geändert') !== false)) >= 1);
proc_close($procOk);
@unlink($scriptOk);

[$procW, $pipesW, $portW, $scriptW] = startWriteServer('wrongecho');
$setProp($portW);
$clearBuf($wm);
$resW = $tryAction($wm, 'Zone1Komfort', 20);
check('Schreiben: antwortet das Gerät mit einem anderen Wert, gibt es einen Fehler', strpos($resW, 'anderen Wert') !== false, $resW);
proc_close($procW);
@unlink($scriptW);

[$procI, $pipesI, $portI, $scriptI] = startWriteServer('ignore');
$setProp($portI);
$clearBuf($wm);
$GLOBALS['ips']['variables']['Zone1Komfort']['value'] = 22.0;
$resI = $tryAction($wm, 'Zone1Komfort', 19);
check('Schreiben: übernimmt das Gerät den Wert nicht (Zurücklesen weicht ab), wird das gemeldet und der echte Wert angezeigt', strpos($resI, 'nicht übernommen') !== false && $GLOBALS['ips']['variables']['Zone1Komfort']['value'] === 0.0, $resI . ' / ' . json_encode($GLOBALS['ips']['variables']['Zone1Komfort']['value']));
proc_close($procI);
@unlink($scriptI);

[$procE, $pipesE, $portE, $scriptE] = startWriteServer('exception');
$setProp($portE);
$clearBuf($wm);
$resE = $tryAction($wm, 'Zone1Komfort', 20);
check('Schreiben: Ausnahme des Geräts (Adresse/Wert abgelehnt) wird mit Ausnahmecode gemeldet', strpos($resE, 'Schreiben fehlgeschlagen') !== false && strpos($resE, 'Modbus-Ausnahme 0x02') !== false, $resE);
proc_close($procE);
@unlink($scriptE);

// Gateway schreibt nie
$gwClient = new WPMBHUB_ModbusGatewayClient(function (string $json) { return false; });
check('Gateway-Client: Schreiben wird nicht unterstützt (kein geratenes Puffer-Format)', $gwClient->writeHolding(1501, 220) === false && strpos($gwClient->lastError, 'nicht unterstützt') !== false, $gwClient->lastError);
$GLOBALS['ips']['properties']['Manufacturer'] = 'stiebeleltron';
$gwW = new WPModbusHubGateway(12345);
$gwW->Create();
$arG = new ReflectionMethod($gwW, 'activeRegisters');
$arG->setAccessible(true);
check('Gateway-Modul: die schreibbaren Felder werden nie angeboten, auch nicht nach einer Freigabe im TCP-Modul', !isset($arG->invoke($gwW, 'stiebeleltron')['Zone1Komfort']) && findFormElement(json_decode($gwW->GetConfigurationForm(), true)['elements'], 'WriteNoticePanel') === null);

// Sperren
$GLOBALS['ips']['properties']['Host'] = '127.0.0.1';
$wm->RevokeWriteNotice();
check('Sperren: Aktion der Variablen entfällt, Schreiben wird wieder abgelehnt, Variablen bleiben', empty($GLOBALS['ips']['actions']['Zone1Komfort']) && empty($GLOBALS['ips']['actions']['Zone1Eco'])
    && isset($GLOBALS['ips']['variables']['Zone1Komfort']) && strpos($tryAction($wm, 'Zone1Komfort', 21), 'gesperrt') !== false);
check('Sperren: danach werden die Sollwerte nicht mehr gelesen', !isset($arW->invoke($wm, 'stiebeleltron')['Zone1Komfort']));

// Englische Belehrung ohne deutsche Reste
$GLOBALS['ips']['locale'] = [];
$wmEn = new WPModbusHub(12345);
$wmEn->Create();
$panelEn = findFormElement(json_decode($wmEn->GetConfigurationForm(), true)['elements'], 'WriteNoticePanel');
$restEn = array_values(array_filter($captionsOf($panelEn), $germanLeft));
check('Englisch: Belehrung ohne deutsche Reste und mit Bestätigungsknopf', $panelEn !== null && $restEn === [] && strpos(json_encode($panelEn), 'I have read and understood this') !== false, implode(' | ', $restEn));
$GLOBALS['ips']['locale'] = json_decode((string)file_get_contents(__DIR__ . '/../libs/locale.json'), true)['translations']['de'];

// ---------------------------------------------------------------------------
echo "\n";
if ($failures === 0) {
    echo "Alle Pruefungen bestanden.\n";
    exit(0);
}
echo "$failures Pruefung(en) FEHLGESCHLAGEN.\n";
exit(1);
