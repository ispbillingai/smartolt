<?php
// Local UDP fixture. Deliberately uses an independent BER codec from the production client.
// php tests/fake_snmp.php [port=0]. Prints its bound port for the parent test.
function element(int $tag, string $bytes): string {
    $n = strlen($bytes);
    return chr($tag) . ($n < 128 ? chr($n) : "\x82" . pack('n', $n)) . $bytes;
}
function number(int $value, int $tag = 2): string {
    $bytes = ltrim(pack('N', $value), "\0") ?: "\0";
    if (ord($bytes[0]) & 128) $bytes = "\0" . $bytes;
    return element($tag, $bytes);
}
function take(string &$bytes): array {
    $tag = ord($bytes[0]); $length = ord($bytes[1]); $offset = 2;
    if ($length & 128) {
        $count = $length & 127; $length = 0;
        while ($count--) $length = ($length << 8) | ord($bytes[$offset++]);
    }
    $body = substr($bytes, $offset, $length); $bytes = substr($bytes, $offset + $length);
    return [$tag, $body];
}
function oidEncode(string $oid): string {
    $a = array_map('intval', explode('.', $oid)); $b = [$a[0] * 40 + $a[1]];
    $b = array_merge($b, array_slice($a, 2)); $out = '';
    foreach ($b as $n) { $part = chr($n % 128); while ($n >= 128) { $n = intdiv($n, 128); $part = chr(($n % 128) | 128) . $part; } $out .= $part; }
    return element(6, $out);
}
function oidDecode(string $b): string {
    $a = []; $n = 0;
    for ($i = 0; $i < strlen($b); $i++) { $c = ord($b[$i]); $n = $n * 128 + ($c & 127); if (!($c & 128)) { $a[] = $n; $n = 0; } }
    $first = array_shift($a); $prefix = min(2, intdiv($first, 40));
    return implode('.', array_merge([$prefix, $first - $prefix * 40], $a));
}
$server = stream_socket_server('udp://127.0.0.1:' . (int)($argv[1] ?? 0), $errno, $error, STREAM_SERVER_BIND);
if (!$server) { fwrite(STDERR, $error); exit(1); }
$address = stream_socket_get_name($server, false);
echo substr($address, strrpos($address, ':') + 1) . "\n"; flush();
$data = [
    '1.3.6.1.2.1.1.1.0' => element(4, 'Huawei test OLT ' . str_repeat('x', 150)),
    '1.3.6.1.2.1.1.2.0' => oidEncode('1.3.6.1.4.1.2011'),
    '1.3.6.1.2.1.1.3.0' => number(123456, 0x43),
    '1.3.6.1.2.1.1.5.0' => element(4, 'OLT-test'),
    '1.3.6.1.2.1.2.2.1.3.1' => number(6),
    '1.3.6.1.2.1.2.2.1.3.2' => number(6),
    '1.3.6.1.2.1.2.2.1.3.3' => number(250),
];
foreach ([1, 2] as $index) {
    $base = '1.3.6.1.2.1.2.2.1.';
    $data[$base . '2.' . $index] = element(4, 'GE0/9/' . $index);
    $data[$base . '5.' . $index] = number(1000000000, 0x42);
    $data[$base . '7.' . $index] = number(1);
    $data[$base . '8.' . $index] = number($index);
    $data[$base . '10.' . $index] = number(4294967295, 0x41);
    $data[$base . '16.' . $index] = number(456, 0x41);
}
$data['1.3.6.1.2.1.31.1.1.1.6.1'] = element(0x46, "\0" . str_repeat("\xff", 8));
$data['1.3.6.1.2.1.31.1.1.1.10.1'] = element(0x46, hex2bin('000100000000000001'));
$data['1.3.6.1.2.1.31.1.1.1.15.1'] = number(10000, 0x42);
$data['1.3.6.1.2.1.31.1.1.1.19.1'] = number(123, 0x43);
$data['2.999.3'] = element(2, "\xff");
$retried = [];
while (true) {
    $packet = stream_socket_recvfrom($server, 65535, 0, $peer);
    if ($packet === false || $packet === '') continue;
    if (getenv('SNMP_FIXTURE_DEBUG')) fwrite(STDERR, 'RX ' . strlen($packet) . ' ' . $peer . "\n");
    [, $message] = take($packet); [, $version] = take($message); [, $community] = take($message); [$pduType, $pdu] = take($message);
    [, $idBytes] = take($pdu); [, $unused] = take($pdu); [, $unused] = take($pdu); [, $list] = take($pdu);
    if ($community === 'wrong') continue;
    if ($community === 'retry' && !isset($retried[bin2hex($idBytes)])) { $retried[bin2hex($idBytes)] = true; continue; }
    if ($community === 'malformed') { stream_socket_sendto($server, "\x30\x82\x01\x00\x02", 0, $peer); continue; }
    $oids = [];
    while ($list !== '') { [, $binding] = take($list); [, $oid] = take($binding); $oids[] = oidDecode($oid); }
    $bindings = '';
    if ($pduType === 0xa5) {
        if ($community === 'nonincreasing') $bindings = element(0x30, oidEncode($oids[0]) . number(6));
        elseif ($community === 'emptywalk') $bindings = '';
        else {
            foreach ($data as $oid => $value) if (strpos($oid, $oids[0] . '.') === 0) $bindings .= element(0x30, oidEncode($oid) . $value);
            $bindings .= element(0x30, oidEncode($oids[0]) . element(0x82, ''));
        }
    } else {
        foreach ($oids as $oid) $bindings .= element(0x30, oidEncode($community === 'wrongoid' ? '1.3.6.1.2.1.1.8.0' : $oid) . ($data[$oid] ?? element(0x80, '')));
    }
    $makeReply = function (string $id, string $comm) use ($bindings, $community) {
        return element(0x30, number(1) . element(4, $comm) . element(0xa2, element(2, $id) . number($community === 'error' ? 5 : 0) . number($community === 'error' ? 1 : 0) . element(0x30, $bindings)));
    };
    if ($community === 'correlation') {
        stream_socket_sendto($server, $makeReply("\0", $community), 0, $peer);
        stream_socket_sendto($server, $makeReply($idBytes, 'wrong-community'), 0, $peer);
    }
    if ($community === 'source') {
        $rogue = stream_socket_server('udp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND);
        stream_socket_sendto($rogue, "\x00", 0, $peer);
        fclose($rogue);
    }
    $sent = stream_socket_sendto($server, $makeReply($idBytes, $community), 0, $peer);
    if (getenv('SNMP_FIXTURE_DEBUG')) fwrite(STDERR, 'TX ' . $sent . "\n");
}
