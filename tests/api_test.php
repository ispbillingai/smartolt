<?php
// End to end test: api.php against the fake OLT. Needs a site whose backend/config/database.php
// points at an empty database loaded from install.sql.
//   php tests/fake_olt.php 47023
//   php -S 127.0.0.1:8099            (from the site folder)
//   php tests/api_test.php http://127.0.0.1:8099/api.php 127.0.0.1 47023 <subdomain> <fleet secret>

[$_, $api, $oltHost, $oltPort, $sub, $fleetSecret] = $argv + [null, null, '127.0.0.1', '47023', 'demo', ''];
$token = null;
$fails = 0;

function call(string $action, array $data = [], array $headers = []): array
{
    global $api, $token;
    $h = ['Content-Type: application/json'];
    if ($token) $h[] = "Authorization: Bearer {$token}";
    $ch = curl_init($api);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => array_merge($h, $headers), CURLOPT_POSTFIELDS => json_encode(['action' => $action] + $data)]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$body, true);
    if (!is_array($j)) {
        $j = ['ok' => false, 'error' => 'not json: ' . substr((string)$body, 0, 300)];
    }
    $j['_http'] = $code;
    return $j;
}

function check(string $label, bool $cond, $detail = null): void
{
    global $fails;
    echo ($cond ? 'PASS ' : 'FAIL ') . $label . ($cond || $detail === null ? '' : '  ' . json_encode($detail)) . "\n";
    if (!$cond) $fails++;
}

// Auth
$r = call('olt.list');
check('no token is refused', $r['_http'] === 401, $r);
$token = hash_hmac('sha256', 'someone-else', $fleetSecret);
$r = call('olt.list');
check('another site\'s token is refused', $r['_http'] === 401, $r);
$token = 'freeisp_smartolt_' . $sub;
$r = call('olt.list');
check('a guessable token is refused', $r['_http'] === 401, $r);
$token = hash_hmac('sha256', $sub, $fleetSecret);
$r = call('ping');
check('the right token is accepted', $r['ok'] === true, $r);

// OLTs
$r = call('olt.save', ['name' => 'Bad pass', 'host' => $oltHost, 'telnet_port' => (int)$oltPort, 'username' => 'root', 'password' => 'nope']);
$badId = $r['id'] ?? 0;
$r = call('olt.refresh', ['id' => $badId]);
check('wrong OLT password gives a clear error', !$r['ok'] && stripos($r['error'] ?? '', 'refused the username or password') !== false, $r);
call('olt.delete', ['id' => $badId]);

$r = call('olt.save', ['name' => 'Main OLT', 'host' => $oltHost, 'telnet_port' => (int)$oltPort, 'username' => 'root', 'password' => 'admin123']);
check('save OLT', !empty($r['id']), $r);
$oltId = $r['id'];

$r = call('olt.refresh', ['id' => $oltId]);
check('refresh reads the OLT', $r['ok'] && ($r['summary']['onus'] ?? 0) === 4 && ($r['summary']['waiting'] ?? 0) === 2, $r);
check('model and version read', ($r['olt']['model'] ?? '') === 'MA5683T' && ($r['olt']['version'] ?? '') === 'MA5600V800R018C10', $r['olt'] ?? $r);
check('boards read through the More pager', count(array_filter($r['olt']['boards'] ?? [], function ($b) { return $b['gpon']; })) === 2, $r['olt']['boards'] ?? null);
check('profiles and VLANs read', count($r['olt']['profiles']['line'] ?? []) === 2 && count($r['olt']['profiles']['srv'] ?? []) === 2 && count($r['olt']['profiles']['vlans'] ?? []) === 2, $r['olt']['profiles'] ?? null);
check('password never returned', !isset($r['olt']['password_enc']));

$r = call('onu.list', ['olt_id' => $oltId]);
$byDesc = array_column($r['onus'] ?? [], null, 'description');
check('ONU list has descriptions and states', ($byDesc['alice_home']['run_state'] ?? '') === 'online' && ($byDesc['bob_shop']['run_state'] ?? '') === 'offline', $r);
check('signal stored for online ONUs', (float)($byDesc['alice_home']['rx_power'] ?? 0) === -19.03 && $byDesc['bob_shop']['rx_power'] === null, $byDesc['alice_home'] ?? null);
$r = call('onu.list', ['status' => 'offline']);
check('offline filter', count($r['onus'] ?? []) === 2, $r);
check('offline cause groups', ($byDesc['bob_shop']['status_group'] ?? '') === 'power_fail' && ($byDesc['erin_flat']['status_group'] ?? '') === 'los' && ($byDesc['erin_flat']['last_down_cause'] ?? '') === 'LOS', $byDesc);
check('model, distance and VLAN read', ($byDesc['alice_home']['model'] ?? '') === 'EG8145V5' && (int)($byDesc['alice_home']['distance_m'] ?? 0) === 1250 && (int)($byDesc['alice_home']['vlan'] ?? 0) === 100, $byDesc['alice_home'] ?? null);
$r = call('stats');
check('stats counts', ($r['counts']['power_fail'] ?? -1) === 1 && ($r['counts']['los'] ?? -1) === 1 && ($r['counts']['waiting'] ?? -1) === 2 && count($r['network'] ?? []) === 1, $r);

$r = call('olt.refresh', ['id' => 999999]);
check('unknown OLT is a 404', $r['_http'] === 404, $r);

// Unconfigured
$r = call('onu.unconfigured', ['olt_id' => $oltId]);
check('autofind lists 2 ONUs', count($r['onus'] ?? []) === 2, $r);
$first = $r['onus'][0] ?? [];
check('autofind fields', ($first['sn'] ?? '') === '485754434D2F5A9A' && ($first['slot'] ?? -1) === 1 && ($first['equipment'] ?? '') === 'EG8145V5', $first);

// Authorize
$r = call('onu.authorize', ['olt_id' => $oltId, 'sn' => 'HWTC-4D2F5A9A', 'frame' => 0, 'slot' => 1, 'port' => 0,
    'line_profile' => 10, 'srv_profile' => 20, 'vlan' => 100, 'gemport' => 1, 'description' => 'Dave "Main" House; rm', 'customer_ref' => 'dave']);
check('authorize by vendor style SN', $r['ok'] && ($r['ont_id'] ?? -1) === 2, $r);
$r = call('onu.authorize', ['olt_id' => $oltId, 'sn' => '485754434D2F5A9A', 'frame' => 0, 'slot' => 1, 'port' => 0, 'line_profile' => 10, 'srv_profile' => 20]);
check('authorizing the same SN again shows the OLT error', !$r['ok'] && stripos($r['error'] ?? '', 'SN already exists') !== false, $r);
$r = call('onu.authorize', ['olt_id' => $oltId, 'sn' => 'xyz', 'frame' => 0, 'slot' => 1, 'port' => 0, 'line_profile' => 10, 'srv_profile' => 20]);
check('bad SN rejected before the OLT', !$r['ok'] && $r['_http'] === 400, $r);
$r = call('onu.unconfigured', ['olt_id' => $oltId]);
check('authorized ONU left the unconfigured list', count($r['onus'] ?? []) === 1, $r);

$r = call('onu.list', ['q' => 'dave']);
$dave = $r['onus'][0] ?? [];
check('new ONU stored with a safe description', ($dave['description'] ?? '') === 'Dave_Main_House_rm' && ($dave['vlan'] ?? 0) == 100, $dave);

// Live status
$r = call('onu.get', ['id' => $dave['id'] ?? 0]);
check('live status and signal', $r['ok'] && (float)($r['onu']['rx_power'] ?? 0) === -19.03 && ($r['onu']['status_group'] ?? '') === 'online', $r);

// Service, disable, replace, bulk, history
$r = call('onu.service', ['id' => $dave['id'], 'vlan' => 300]);
check('service moves to a new VLAN', $r['ok'] && (int)($r['onu']['vlan'] ?? 0) === 300, $r);
$r = call('onu.service', ['id' => $dave['id'], 'vlan' => 0]);
check('a bad VLAN is refused', !$r['ok'] && $r['_http'] === 400, $r);
$r = call('onu.disable', ['id' => $dave['id']]);
check('disable', $r['ok'], $r);
$r = call('onu.list', ['status' => 'disabled']);
check('disabled filter', count($r['onus'] ?? []) === 1, $r);
$r = call('onu.enable', ['id' => $dave['id']]);
check('enable', $r['ok'], $r);
$r = call('onu.meta', ['id' => $dave['id'], 'address' => 'Gate 2', 'lat' => '-1.30', 'lng' => '36.80']);
check('details saved', $r['ok'], $r);
$r = call('onu.replace_sn', ['id' => $dave['id'], 'sn' => 'HWTC-BBBB0002']);
check('replace serial', $r['ok'] && ($r['sn'] ?? '') === '48575443BBBB0002', $r);
$r = call('onu.bulk', ['ids' => [$byDesc['alice_home']['id'], $dave['id']], 'bulk_action' => 'reboot']);
check('bulk reboot', $r['ok'] && ($r['done'] ?? 0) === 2, $r);
$r = call('onu.bulk', ['ids' => [$dave['id']], 'bulk_action' => 'format_disk']);
check('unknown bulk action refused', !$r['ok'] && $r['_http'] === 400, $r);
$r = call('onu.history', ['id' => $dave['id']]);
check('history has the disable and enable', count($r['events'] ?? []) === 2, $r);
$r = call('catalogs.list');
check('catalogs list', $r['ok'] && count($r['onu_types'] ?? []) >= 10, $r);

// Rename, reboot, delete
$r = call('onu.describe', ['id' => $dave['id'], 'description' => 'dave_new']);
check('rename', $r['ok'] && $r['description'] === 'dave_new', $r);
$r = call('onu.reboot', ['id' => $dave['id']]);
check('reboot answers the y/n question', $r['ok'], $r);
$r = call('onu.delete', ['id' => $dave['id']]);
check('delete removes its service port first', $r['ok'] && ($r['service_ports_removed'] ?? 0) === 1, $r);
$r = call('olt.refresh', ['id' => $oltId]);
check('after delete the OLT has 4 ONUs again', ($r['summary']['onus'] ?? 0) === 4, $r);

// An offline ONU still shows its details, just without light levels
$r = call('onu.get', ['id' => $byDesc['bob_shop']['id'] ?? 0]);
check('offline ONU shows details without signal', $r['ok'] && ($r['detail']['run state'] ?? '') === 'offline' && $r['onu']['rx_power'] === null, $r);

$r = call('actions.list');
check('every change is logged', count($r['actions'] ?? []) >= 8, count($r['actions'] ?? []));

echo $fails ? "\n{$fails} FAILED\n" : "\nALL PASSED\n";
exit($fails ? 1 : 0);
