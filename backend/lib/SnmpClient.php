<?php
// Read-only SNMPv2c over UDP. No PHP SNMP extension or installed MIB files required.
// Protocol: RFC 3416; system objects: RFC 3418; interface counters: RFC 2863.
class SnmpError extends RuntimeException {}

class SnmpClient
{
    private string $host;
    private int $port;
    private string $community;
    private float $timeout;
    private int $retries;
    private ?float $deadline = null;

    public function __construct(string $host, int $port, string $community, float $timeout = 2.0, int $retries = 1)
    {
        if (!filter_var($host, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,253}$)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?$/D', $host)) {
            throw new InvalidArgumentException('Enter a valid SNMP host.');
        }
        if ($port < 1 || $port > 65535 || $community === '' || strlen($community) > 255 || $timeout <= 0 || $timeout > 30 || $retries < 0 || $retries > 3) {
            throw new InvalidArgumentException('Invalid SNMP port, community or timeout.');
        }
        $this->host = $host;
        $this->port = $port;
        $this->community = $community;
        $this->timeout = $timeout;
        $this->retries = $retries;
    }

    /** Numeric OID => value. Missing objects have a null value; Counter64 is an exact decimal string. */
    public function get(array $oids): array
    {
        if (!$oids || count($oids) > 64) throw new InvalidArgumentException('Request between 1 and 64 SNMP objects.');
        $oids = array_map([self::class, 'normaliseOid'], $oids);
        $rows = $this->request(0xa0, $oids);
        if (count($rows) !== count($oids)) throw new SnmpError('The SNMP response has the wrong number of objects.');
        $out = [];
        foreach ($rows as $i => $row) {
            if ($row['oid'] !== $oids[$i]) throw new SnmpError('The SNMP response contains an unexpected object.');
            $out[$row['oid']] = $row['value'];
        }
        return $out;
    }

    /** Bounded GETBULK walk. Never return a silently truncated table. */
    public function walk(string $root, int $maxRows = 4096): array
    {
        $root = self::normaliseOid($root);
        if ($maxRows < 1 || $maxRows > 16384) throw new InvalidArgumentException('Invalid SNMP table limit.');
        $previousDeadline = $this->deadline;
        $this->deadline = $this->deadline ?? microtime(true) + 30;
        $cursor = $root;
        $out = [];
        try {
            while (true) {
                $rows = $this->request(0xa5, [$cursor]);
                if (!$rows) throw new SnmpError('The SNMP table response was empty.');
                foreach ($rows as $row) {
                    if (in_array($row['type'], [0x80, 0x81, 0x82], true)) return $out;
                    if (self::compareOid($row['oid'], $cursor) <= 0) throw new SnmpError('The SNMP agent returned a non-increasing table index.');
                    if (strpos($row['oid'], $root . '.') !== 0) return $out;
                    if (count($out) >= $maxRows) throw new SnmpError('The SNMP table exceeds the configured row limit.');
                    $cursor = $row['oid'];
                    $out[$cursor] = $row['value'];
                }
            }
        } finally {
            $this->deadline = $previousDeadline;
        }
    }

    public function systemInfo(): array
    {
        $base = '1.3.6.1.2.1.1.';
        $data = $this->get([$base . '1.0', $base . '2.0', $base . '3.0', $base . '5.0']);
        if ($data[$base . '1.0'] === null || $data[$base . '3.0'] === null) {
            throw new SnmpError('SNMP responded, but the community cannot read system identity and uptime.');
        }
        return ['description' => self::displayString((string)$data[$base . '1.0']),
            'object_id' => $data[$base . '2.0'], 'uptime_ticks' => $data[$base . '3.0'],
            'uptime_seconds' => (int)floor((float)$data[$base . '3.0'] / 100),
            'name' => self::displayString((string)($data[$base . '5.0'] ?? ''))];
    }

    /** Ethernet interfaces from IF-MIB (ifType=6), including uplinks exposed by the OLT firmware.
     * Counters are cumulative bytes, not a fabricated instantaneous traffic rate.
     */
    public function interfaces(): array
    {
        $previousDeadline = $this->deadline;
        $this->deadline = microtime(true) + 30;
        try {
            $types = $this->walk('1.3.6.1.2.1.2.2.1.3');
            $rows = [];
            foreach ($types as $oid => $type) {
                if ((int)$type !== 6) continue;
                if (count($rows) >= 512) throw new SnmpError('The OLT exposes more than 512 Ethernet interfaces.');
                $index = substr($oid, strrpos($oid, '.') + 1);
                $columns = ['name' => '1.3.6.1.2.1.2.2.1.2', 'speed_bps' => '1.3.6.1.2.1.2.2.1.5',
                    'admin_status' => '1.3.6.1.2.1.2.2.1.7', 'oper_status' => '1.3.6.1.2.1.2.2.1.8',
                    'in_octets32' => '1.3.6.1.2.1.2.2.1.10', 'out_octets32' => '1.3.6.1.2.1.2.2.1.16',
                    'in_octets64' => '1.3.6.1.2.1.31.1.1.1.6', 'out_octets64' => '1.3.6.1.2.1.31.1.1.1.10',
                    'speed_mbps' => '1.3.6.1.2.1.31.1.1.1.15', 'counter_discontinuity' => '1.3.6.1.2.1.31.1.1.1.19'];
                $objects = array_map(function ($o) use ($index) { return $o . '.' . $index; }, $columns);
                $values = $this->get(array_values($objects));
                $row = ['index' => (int)$index];
                foreach ($objects as $key => $object) $row[$key] = $values[$object];
                $row['name'] = self::displayString((string)($row['name'] ?? ('Interface ' . $index)));
                $hc = $row['in_octets64'] !== null && $row['out_octets64'] !== null;
                $row['counter_bits'] = $hc ? 64 : 32;
                $row['in_octets'] = $row[$hc ? 'in_octets64' : 'in_octets32'];
                $row['out_octets'] = $row[$hc ? 'out_octets64' : 'out_octets32'];
                if ($row['speed_mbps'] !== null && $row['speed_mbps'] > 0) $row['speed_bps'] = $row['speed_mbps'] * 1000000;
                unset($row['in_octets32'], $row['out_octets32'], $row['in_octets64'], $row['out_octets64'], $row['speed_mbps']);
                $rows[] = $row;
            }
            return $rows;
        } finally {
            $this->deadline = $previousDeadline;
        }
    }

    private static function displayString(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $s);
        return mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }

    private function request(int $pduType, array $oids): array
    {
        $id = random_int(1, 0x7fffffff);
        $bindings = '';
        foreach ($oids as $oid) $bindings .= self::tlv(0x30, self::tlv(6, self::encodeOid($oid)) . self::tlv(5, ''));
        $pdu = self::integer($id) . self::integer(0) . self::integer($pduType === 0xa5 ? 20 : 0) . self::tlv(0x30, $bindings);
        $packet = self::tlv(0x30, self::integer(1) . self::tlv(4, $this->community) . self::tlv($pduType, $pdu));
        $host = strpos($this->host, ':') === false ? $this->host : '[' . $this->host . ']';
        $sock = @stream_socket_client("udp://{$host}:{$this->port}", $errno, $errstr, $this->timeout);
        if (!$sock) throw new SnmpError('Could not open the SNMP connection.');
        stream_set_blocking($sock, false);
        try {
            for ($attempt = 0; $attempt <= $this->retries; $attempt++) {
                $until = min(microtime(true) + $this->timeout, $this->deadline ?? PHP_FLOAT_MAX);
                if ($until <= microtime(true)) throw new SnmpError('The SNMP scan exceeded its time limit.');
                if (@fwrite($sock, $packet) !== strlen($packet)) throw new SnmpError('Could not send the SNMP request.');
                while (($remaining = $until - microtime(true)) > 0) {
                    $read = [$sock]; $write = null; $except = null;
                    $ready = @stream_select($read, $write, $except, (int)$remaining, (int)(($remaining - floor($remaining)) * 1000000));
                    if ($ready === false) throw new SnmpError('Could not receive the SNMP response.');
                    if (!$ready) break;
                    // Connected UDP sockets accept packets only from this resolved host and port.
                    $reply = @fread($sock, 65535);
                    if ($reply === false || $reply === '') continue;
                    $parsed = self::decodeResponse($reply);
                    if ($parsed['id'] !== $id || !hash_equals($this->community, $parsed['community'])) continue;
                    if ($parsed['error'] !== 0) throw new SnmpError('The SNMP agent returned error ' . $parsed['error'] . ' at object ' . $parsed['error_index'] . '.');
                    return $parsed['bindings'];
                }
            }
            throw new SnmpError('SNMP timed out. Check the read community, UDP port, OLT access list and VPN/firewall rules.');
        } finally {
            fclose($sock);
        }
    }

    private static function tlv(int $type, string $value): string
    {
        $n = strlen($value);
        if ($n < 128) return chr($type) . chr($n) . $value;
        $bytes = '';
        while ($n > 0) { $bytes = chr($n & 255) . $bytes; $n >>= 8; }
        return chr($type) . chr(0x80 | strlen($bytes)) . $bytes . $value;
    }

    private static function integer(int $n): string
    {
        $value = '';
        do { $value = chr($n & 255) . $value; $n >>= 8; } while ($n > 0);
        if (ord($value[0]) >= 128) $value = "\0" . $value;
        return self::tlv(2, $value);
    }

    private static function normaliseOid(string $oid): string
    {
        $oid = ltrim($oid, '.');
        if (!preg_match('/^[0-2](?:\.(?:0|[1-9][0-9]*)){1,127}$/D', $oid)) throw new InvalidArgumentException('Invalid numeric SNMP object ID.');
        $arcs = explode('.', $oid);
        foreach ($arcs as $arc) if (strlen($arc) > 10 || (float)$arc > 4294967295) throw new InvalidArgumentException('SNMP object ID component is too large.');
        if ((int)$arcs[0] < 2 && (int)$arcs[1] > 39) throw new InvalidArgumentException('Invalid numeric SNMP object ID.');
        return $oid;
    }

    private static function encodeOid(string $oid): string
    {
        $arcs = array_map('intval', explode('.', self::normaliseOid($oid)));
        $first = array_shift($arcs) * 40 + array_shift($arcs);
        array_unshift($arcs, $first);
        $out = '';
        foreach ($arcs as $arc) {
            $bytes = chr($arc & 127);
            while (($arc >>= 7) > 0) $bytes = chr(128 | ($arc & 127)) . $bytes;
            $out .= $bytes;
        }
        return $out;
    }

    private static function readTlv(string $bytes, int &$offset, ?int $expected = null): array
    {
        if ($offset + 2 > strlen($bytes)) throw new SnmpError('Truncated SNMP response.');
        $type = ord($bytes[$offset++]); $length = ord($bytes[$offset++]);
        if ($expected !== null && $type !== $expected) throw new SnmpError('Unexpected SNMP response type.');
        if ($length & 128) {
            $count = $length & 127; $length = 0;
            if ($count < 1 || $count > 4 || $offset + $count > strlen($bytes)) throw new SnmpError('Invalid SNMP response length.');
            for ($i = 0; $i < $count; $i++) $length = $length * 256 + ord($bytes[$offset++]);
        }
        if ($offset + $length > strlen($bytes)) throw new SnmpError('Truncated SNMP response.');
        $value = substr($bytes, $offset, $length); $offset += $length;
        return [$type, $value];
    }

    private static function unsigned(string $bytes): string
    {
        if ($bytes === '' || strlen($bytes) > 9 || (strlen($bytes) === 9 && ord($bytes[0]) !== 0)) throw new SnmpError('Invalid SNMP integer.');
        $decimal = '0';
        // Decimal string arithmetic preserves all Counter64 values without bcmath or float rounding.
        for ($i = 0; $i < strlen($bytes); $i++) {
            $carry = ord($bytes[$i]); $next = '';
            for ($j = strlen($decimal) - 1; $j >= 0; $j--) {
                $v = (int)$decimal[$j] * 256 + $carry; $next = (string)($v % 10) . $next; $carry = intdiv($v, 10);
            }
            while ($carry > 0) { $next = (string)($carry % 10) . $next; $carry = intdiv($carry, 10); }
            $decimal = ltrim($next, '0') ?: '0';
        }
        return $decimal;
    }

    private static function decodeValue(int $type, string $bytes)
    {
        if (in_array($type, [5, 0x80, 0x81, 0x82], true)) return null;
        if ($type === 6) {
            $arcs = []; $arc = 0; $pending = false;
            for ($i = 0; $i < strlen($bytes); $i++) {
                $b = ord($bytes[$i]); $arc = $arc * 128 + ($b & 127); $pending = (bool)($b & 128);
                if ($arc > 4294967375) throw new SnmpError('Invalid SNMP object ID.');
                if (!$pending) { $arcs[] = $arc; $arc = 0; }
            }
            if ($pending || !$arcs) throw new SnmpError('Invalid SNMP object ID.');
            $first = array_shift($arcs); $a = min(2, intdiv($first, 40));
            return implode('.', array_merge([$a, $first - 40 * $a], $arcs));
        }
        if ($type === 2) {
            if ($bytes === '' || strlen($bytes) > 4) throw new SnmpError('Invalid SNMP Integer32.');
            $n = (ord($bytes[0]) & 128) ? -1 : 0;
            for ($i = 0; $i < strlen($bytes); $i++) $n = ($n << 8) | ord($bytes[$i]);
            return $n;
        }
        if (in_array($type, [0x41, 0x42, 0x43, 0x46], true)) {
            $n = self::unsigned($bytes);
            if ($type !== 0x46 && (float)$n > 4294967295) throw new SnmpError('Invalid SNMP unsigned integer.');
            return $type === 0x46 ? $n : (int)$n;
        }
        if ($type === 4) return $bytes;
        if ($type === 0x40 && strlen($bytes) === 4) return implode('.', array_values(unpack('C*', $bytes)));
        throw new SnmpError('Unsupported SNMP value type.');
    }

    private static function decodeResponse(string $packet): array
    {
        $pos = 0; [, $message] = self::readTlv($packet, $pos, 0x30);
        if ($pos !== strlen($packet)) throw new SnmpError('Trailing data in SNMP response.');
        $pos = 0; [, $version] = self::readTlv($message, $pos, 2);
        if (self::decodeValue(2, $version) !== 1) throw new SnmpError('The OLT did not return SNMPv2c.');
        [, $community] = self::readTlv($message, $pos, 4);
        [, $pdu] = self::readTlv($message, $pos, 0xa2);
        if ($pos !== strlen($message)) throw new SnmpError('Trailing data in SNMP message.');
        $pos = 0; [, $id] = self::readTlv($pdu, $pos, 2); [, $error] = self::readTlv($pdu, $pos, 2); [, $errorIndex] = self::readTlv($pdu, $pos, 2);
        [, $list] = self::readTlv($pdu, $pos, 0x30);
        if ($pos !== strlen($pdu)) throw new SnmpError('Trailing data in SNMP PDU.');
        $bindings = []; $pos = 0;
        while ($pos < strlen($list)) {
            [, $binding] = self::readTlv($list, $pos, 0x30); $p = 0;
            [, $oid] = self::readTlv($binding, $p, 6); [$type, $value] = self::readTlv($binding, $p);
            if ($p !== strlen($binding)) throw new SnmpError('Trailing data in SNMP object.');
            $bindings[] = ['oid' => self::decodeValue(6, $oid), 'type' => $type, 'value' => self::decodeValue($type, $value)];
        }
        return ['id' => self::decodeValue(2, $id), 'community' => $community, 'error' => self::decodeValue(2, $error),
            'error_index' => self::decodeValue(2, $errorIndex), 'bindings' => $bindings];
    }

    private static function compareOid(string $a, string $b): int
    {
        $aa = explode('.', $a); $bb = explode('.', $b);
        foreach ($aa as $i => $arc) {
            if (!isset($bb[$i])) return 1;
            if ((int)$arc !== (int)$bb[$i]) return (int)$arc <=> (int)$bb[$i];
        }
        return count($aa) <=> count($bb);
    }
}
