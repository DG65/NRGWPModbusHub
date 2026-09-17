<?php

// WPMBHUB_ModbusTcpClient -- gemeinsame Modbus-TCP-Grundfunktionen, portiert
// aus MeterHub (MHUB_ModbusTcpClient, DG65/NRGMeterHub) mit WPMBHUB_-Praefix
// (Verbund-Konvention 25.07.2026: globale Klassennamen kollidieren sonst,
// wenn mehrere NRG-Stack-Module im selben PHP-Prozess laufen). Funktional
// unveraendert -- dieselbe, im Solarpark-Praxiseinsatz gehaertete Logik
// (eine Verbindung je Lesezyklus statt je Anfrage, siehe MeterHub-CLAUDE.md
// "Modbus: eine Verbindung je Zyklus").

class WPMBHUB_ModbusTcpClient
{
    public $host;
    public $port;
    public $unitId;

    public function __construct($host, $port, $unitId)
    {
        $this->host   = $host;
        $this->port   = $port;
        $this->unitId = $unitId;
    }

    public function readHolding($startReg, $count)
    {
        return $this->modbusRead(0x03, $startReg, $count);
    }

    public function readInput($startReg, $count)
    {
        return $this->modbusRead(0x04, $startReg, $count);
    }

    private $sock = null;
    private $tid = 0;
    public $connects = 0;
    public $lastError = '';

    public function close(): void
    {
        if ($this->sock) {
            @fclose($this->sock);
        }
        $this->sock = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function connect(): bool
    {
        if ($this->sock) {
            return true;
        }
        $s = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($s === false) {
            $this->lastError = 'connect';
            return false;
        }
        stream_set_timeout($s, 3);
        $this->sock = $s;
        $this->connects++;
        return true;
    }

    private function transact(string $pdu): ?string
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $reused = $this->sock !== null;
            if (!$this->connect()) {
                return null;
            }
            $resp = $this->exchange($pdu);
            if ($resp !== null) {
                $this->lastError = '';
                return $resp;
            }
            $this->close();
            if (!$reused || !in_array($this->lastError, ['write', 'eof'], true)) {
                return null;
            }
        }
        return null;
    }

    private function exchange(string $pdu): ?string
    {
        $this->tid = ($this->tid % 65535) + 1;
        $tid = $this->tid;
        $frame = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId) . $pdu;
        if (@fwrite($this->sock, $frame) !== strlen($frame)) {
            $this->lastError = 'write';
            return null;
        }
        $deadline = microtime(true) + 3.0;
        while (true) {
            $head = $this->readExact(7, $deadline);
            if ($head === null) {
                return null;
            }
            $h = unpack('ntid/npid/nlen', $head);
            if ($h['len'] < 2 || $h['len'] > 260) {
                $this->lastError = 'frame';
                return null;
            }
            $body = $this->readExact($h['len'] - 1, $deadline);
            if ($body === null) {
                return null;
            }
            if ($h['tid'] === $tid) {
                return $body;
            }
        }
    }

    private function readExact(int $n, float $deadline): ?string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            if (microtime(true) >= $deadline) {
                $this->lastError = 'timeout';
                return null;
            }
            $chunk = @fread($this->sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = @stream_get_meta_data($this->sock);
                $this->lastError = !empty($meta['timed_out']) ? 'timeout' : 'eof';
                return null;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function modbusRead($fc, $startReg, $count)
    {
        $pdu = $this->transact(pack('Cnn', $fc, $startReg, $count));
        if ($pdu === null || strlen($pdu) < 2) {
            return null;
        }
        $rfc = ord($pdu[0]);
        if ($rfc & 0x80 || $rfc !== $fc) {
            return null;
        }

        $byteCount = ord($pdu[1]);
        $data      = substr($pdu, 2, $byteCount);

        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    public function u16($regs, $offset)
    {
        return isset($regs[$offset]) ? ($regs[$offset] & 0xFFFF) : 0;
    }

    public function s16($regs, $offset)
    {
        $v = $this->u16($regs, $offset);
        return $v > 32767 ? $v - 65536 : $v;
    }

    public function u32($regs, $offset)
    {
        return (($this->u16($regs, $offset) << 16) | $this->u16($regs, $offset + 1));
    }

    public function s32($regs, $offset)
    {
        $v = $this->u32($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }
}
