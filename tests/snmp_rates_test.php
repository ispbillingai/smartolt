<?php
require_once __DIR__ . '/../backend/lib/SnmpRates.php';
$fails = 0;
function verifyRate(string $label, bool $ok): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$ok) $fails++; }
$base = ['counter_bits' => 64, 'counter_discontinuity' => 0, 'speed_bps' => 1000000000,
    'in_octets' => '18446744073700000000', 'out_octets' => '9007199254740992'];
$new = array_replace($base, ['in_octets' => '18446744073700001000', 'out_octets' => '9007199254741992']);
$r = SnmpRates::calculate($new, $base, 10, false);
verifyRate('Counter64 differences stay exact above floating point precision', $r['rx_bps'] === 800.0 && $r['tx_bps'] === 800.0);
verifyRate('Reboot leaves gap', SnmpRates::calculate($new, $base, 10, true)['rx_bps'] === null);
verifyRate('Counter reset leaves gap', SnmpRates::calculate($base, $new, 10, false)['rx_bps'] === null);
verifyRate('Discontinuity leaves gap', SnmpRates::calculate(array_replace($new, ['counter_discontinuity' => 5]), $base, 10, false)['rx_bps'] === null);
verifyRate('Long outage leaves gap', SnmpRates::calculate($new, $base, 1000, false)['rx_bps'] === null);
verifyRate('Missing baseline leaves gap', SnmpRates::calculate($new, [], 10, false)['rx_bps'] === null);
verifyRate('Reused interface index leaves gap', SnmpRates::calculate($new + ['name' => 'GE2'], $base + ['name' => 'GE1'], 10, false)['rx_bps'] === null);
$old32 = array_replace($base, ['counter_bits' => 32, 'in_octets' => 1000, 'out_octets' => 2000]);
$new32 = array_replace($old32, ['in_octets' => 2000, 'out_octets' => 3000]);
verifyRate('Ambiguous gigabit 32-bit wrap leaves gap', SnmpRates::calculate($new32, $old32, 300, false)['rx_bps'] === null);
verifyRate('Short 32-bit interval supports rate', SnmpRates::calculate($new32, $old32, 1, false)['rx_bps'] === 8000.0);
verifyRate('Missing counter leaves gap', SnmpRates::calculate(array_replace($new, ['in_octets' => null]), $base, 10, false)['rx_bps'] === null);
exit($fails ? 1 : 0);
