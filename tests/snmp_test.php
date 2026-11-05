<?php
require_once __DIR__ . '/../backend/lib/SnmpClient.php';
$fails = 0;
function check(string $label, bool $ok): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $fails++; }
function refuses(string $label, callable $fn, string $message): void {
    try { $fn(); check($label, false); }
    catch (SnmpError $e) { check($label, strpos($e->getMessage(), $message) !== false); }
}
$proc = proc_open([PHP_BINARY, __DIR__ . '/fake_snmp.php'], [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']], $pipes);
if (!is_resource($proc)) throw new RuntimeException('Could not start the UDP fixture.');
try {
    $port = (int)fgets($pipes[1]);
    if (!$port) throw new RuntimeException('SNMP fixture did not bind a UDP port.');
    $client = new SnmpClient('127.0.0.1', $port, 'test-read', .25, 0);
    $system = $client->systemInfo();
    check('real UDP system request and long BER lengths', $system['name'] === 'OLT-test' && strlen($system['description']) === 166);
    check('OID and TimeTicks decode', $system['object_id'] === '1.3.6.1.4.1.2011' && $system['uptime_ticks'] === 123456 && $system['uptime_seconds'] === 1234);
    $ifaces = $client->interfaces();
    check('IF-MIB Ethernet discovery filters non-Ethernet ports', count($ifaces) === 2 && $ifaces[0]['name'] === 'GE0/9/1');
    check('Counter64 has no float rounding', $ifaces[0]['in_octets'] === '18446744073709551615' && $ifaces[0]['out_octets'] === '72057594037927937');
    check('high speed and counter discontinuity', $ifaces[0]['speed_bps'] === 10000000000 && $ifaces[0]['counter_discontinuity'] === 123);
    check('32-bit fallback and operational state', $ifaces[1]['counter_bits'] === 32 && $ifaces[1]['in_octets'] === 4294967295 && $ifaces[1]['oper_status'] === 2);
    check('unknown objects return null', $client->get(['1.3.6.1.2.1.999.0'])['1.3.6.1.2.1.999.0'] === null);
    check('multi-byte first OID arc and signed Integer32', $client->get(['2.999.3'])['2.999.3'] === -1);
    check('request ID and community correlation', (new SnmpClient('127.0.0.1', $port, 'correlation', .25, 0))->systemInfo()['name'] === 'OLT-test');
    check('packets from other UDP source ports rejected', (new SnmpClient('127.0.0.1', $port, 'source', .25, 0))->systemInfo()['name'] === 'OLT-test');
    check('lost UDP request is retried', (new SnmpClient('127.0.0.1', $port, 'retry', .1, 1))->systemInfo()['name'] === 'OLT-test');
    refuses('wrong community times out', function () use ($port) { (new SnmpClient('127.0.0.1', $port, 'wrong', .1, 0))->systemInfo(); }, 'timed out');
    refuses('agent error is visible', function () use ($port) { (new SnmpClient('127.0.0.1', $port, 'error', .25, 0))->systemInfo(); }, 'error 5');
    refuses('malformed BER is rejected', function () use ($port) { (new SnmpClient('127.0.0.1', $port, 'malformed', .25, 0))->systemInfo(); }, 'Truncated');
    refuses('wrong GET object rejected', function () use ($port) { (new SnmpClient('127.0.0.1', $port, 'wrongoid', .25, 0))->systemInfo(); }, 'unexpected object');
    refuses('non-increasing walks cannot loop', function () use ($port) { (new SnmpClient('127.0.0.1', $port, 'nonincreasing', .25, 0))->interfaces(); }, 'non-increasing');
    refuses('empty walk response is reported', function () use ($port) { (new SnmpClient('127.0.0.1', $port, 'emptywalk', .25, 0))->interfaces(); }, 'empty');
    refuses('row limit never truncates silently', function () use ($client) { $client->walk('1.3.6.1.2.1.2.2.1.3', 1); }, 'row limit');
} finally {
    proc_terminate($proc);
    stream_set_blocking($pipes[2], false);
    $fixtureErrors = stream_get_contents($pipes[2]);
    if ($fixtureErrors !== '') fwrite(STDERR, $fixtureErrors);
    foreach ($pipes as $pipe) fclose($pipe);
    proc_close($proc);
}
exit($fails ? 1 : 0);
