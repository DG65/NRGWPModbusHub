<?php

require_once __DIR__ . '/ModbusTcpClient.php';

// WPMBHUB_ModbusGatewayClient -- Lesezugriff ueber Symcons NATIVES ModBus-
// Gateway (Splitter {A5F663AB-C400-4FE5-B207-4D67CC030564}) statt eigener
// TCP-Socket-Verbindung. Damit geht auch RS485/Modbus RTU an einem seriellen
// Anschluss (Serial Port -> ModBus Gateway -> diese Instanz) -- die Serial-
// Port-Ebene uebernimmt Symcon, unser Modul braucht keinen eigenen COM-Zugriff.
//
// Schema NICHT geraten, sondern gegen den Rohcode des offiziellen Symcon-
// Referenzmoduls verifiziert (github.com/symcon/SymconBC, EM24-DIN/module.php,
// im Verbund bereits von MeterHub/InverterHub/ChargerHub gegengelesen):
//   Anfrage  SendDataToParent(json_encode([
//                'DataID' => '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}',
//                'Function' => 3|4, 'Address' => Register, 'Quantity' => Anzahl,
//                'Data' => '']))
//   Antwort  rohe Binaerdaten (kein JSON): 2 Header-Byte (Function + Byte-
//            Count), danach big-endian 16-Bit-Register.
//   Unit-/Slave-ID steht NICHT im Puffer, sondern ist Property "DeviceID" der
//   Gateway-Instanz (im Verbund live an echter Hardware bestaetigt).
// SendDataToParent() ist protected -- deshalb bekommt diese Klasse den Aufruf
// als Closure vom Modul (gleiches Muster wie MeterHub).
// Bewusst NUR lesend (Function 3/4): das Referenzmodul liest nur, ein
// Schreib-Schema waere geraten.
//
// Erbt von WPMBHUB_ModbusTcpClient nur die reinen Dekodierhilfen (u16/s16/
// floatLE ...), die Socketlogik bleibt ungenutzt (Vorbild: ChargerHub).

class WPMBHUB_ModbusGatewayClient extends WPMBHUB_ModbusTcpClient
{
    const GATEWAY_GUID = '{A5F663AB-C400-4FE5-B207-4D67CC030564}';
    const DATA_ID      = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';

    // Nach dieser Anzahl aufeinanderfolgender Anfragen OHNE jede Antwort werden
    // die uebrigen Anfragen dieses Zyklus uebersprungen -- sonst wuerde ein
    // nicht antwortender Regler (Gateway-Timeout je Anfrage, Standard 5 s)
    // einen Lesezyklus mit vielen Registern minutenlang blockieren.
    const MAX_CONSECUTIVE_NO_REPLY = 2;

    /** @var callable string(string $json) -- liefert die rohe Antwort oder false */
    private $sendFn;
    private $noReplyStreak = 0;

    public $lastRequest      = '';
    public $lastResponseHex  = '';
    public $lastResponseLen  = 0;
    // true, wenn das erste Antwortbyte dem angefragten Function Code und das
    // zweite der erwarteten Byteanzahl entspricht (Modbus-PDU-Kopf) -- nur
    // Diagnose, die Auswertung haengt nicht davon ab.
    public $headerLooksLikePdu = false;

    public function __construct(callable $sendFn)
    {
        parent::__construct('', 0, 0);
        $this->sendFn = $sendFn;
    }

    public function readHolding($startReg, $count)
    {
        return $this->request(3, (int)$startReg, (int)$count);
    }

    public function readInput($startReg, $count)
    {
        return $this->request(4, (int)$startReg, (int)$count);
    }

    public function close(): void
    {
    }

    private function request(int $function, int $address, int $quantity): ?array
    {
        $this->lastResponseHex = '';
        $this->lastResponseLen = 0;
        $this->headerLooksLikePdu = false;

        if ($this->noReplyStreak >= self::MAX_CONSECUTIVE_NO_REPLY) {
            $this->lastError = 'uebersprungen (Regler antwortet nicht)';
            return null;
        }

        $json = json_encode([
            'DataID'   => self::DATA_ID,
            'Function' => $function,
            'Address'  => $address,
            'Quantity' => $quantity,
            'Data'     => '',
        ]);
        $this->lastRequest = (string)$json;

        try {
            $resp = ($this->sendFn)($json);
        } catch (\Throwable $e) {
            $resp = false;
        }

        if (!is_string($resp) || $resp === '') {
            $this->noReplyStreak++;
            $this->lastError = 'keine Antwort vom Gateway';
            return null;
        }
        $this->noReplyStreak = 0;
        $this->lastResponseHex = bin2hex($resp);
        $this->lastResponseLen = strlen($resp);

        // Modbus-Ausnahmeantwort: Function Code mit gesetztem Bit 7 + Fehlercode.
        if (strlen($resp) === 2 && (ord($resp[0]) & 0x80)) {
            $this->lastError = sprintf('Modbus-Ausnahme 0x%02X', ord($resp[1]));
            return null;
        }
        if (strlen($resp) < 2 + 2 * $quantity) {
            $this->lastError = 'Antwort zu kurz (' . strlen($resp) . ' Byte, erwartet ' . (2 + 2 * $quantity) . ')';
            return null;
        }
        $this->headerLooksLikePdu = (ord($resp[0]) === $function) && (ord($resp[1]) === 2 * $quantity);
        $this->lastError = '';

        return array_values(unpack('n*', substr($resp, 2, 2 * $quantity)));
    }
}
