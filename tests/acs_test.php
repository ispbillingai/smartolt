<?php
// Run after the web fixture. Both endpoints and the database must be disposable local fixtures.
$acsUrl = rtrim($argv[1] ?? '', '/');
$base = rtrim($argv[2] ?? '', '/');
foreach ([$acsUrl, $base] as $url) if (parse_url($url, PHP_URL_HOST) !== '127.0.0.1') throw new RuntimeException('ACS tests require literal loopback HTTP servers.');
if (!str_contains(file_get_contents(__DIR__ . '/../backend/config/database.php'), 'LOCAL TEST FIXTURE ONLY')) throw new RuntimeException('Use the isolated test runner.');
require_once __DIR__ . '/../backend/lib/OltManager.php';
require_once __DIR__ . '/../backend/lib/AcsIntegration.php';
$mgr = new OltManager();
if ($mgr->db()->query('SELECT DATABASE()')->fetchColumn() !== 'smartolt_test') throw new RuntimeException('Use the isolated test database.');
$acs = new AcsIntegration($mgr);
if ($mgr->db()->query('SELECT COUNT(*) FROM acs_settings')->fetchColumn()) throw new RuntimeException('The test expects empty fixture ACS tables.');
$fails = 0;
function acsCheck(string $label, bool $ok): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$ok) $fails++; }
function acsThrows(callable $fn, string $part = ''): bool { try { $fn(); return false; } catch (Throwable $e) { return $part === '' || str_contains($e->getMessage(), $part); } }
function control(array $body = []): array {
    global $acsUrl;
    $ch = curl_init($acsUrl . '/__control');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
    if ($body) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POSTFIELDS => json_encode($body)]);
    $r = json_decode((string)curl_exec($ch), true); curl_close($ch);
    if (!is_array($r)) throw new RuntimeException('Fake ACS is not running.');
    return $r;
}
$cookie = tempnam(sys_get_temp_dir(), 'smartolt-acs-test-');
function acsPage(string $path, ?array $post = null): string {
    global $base, $cookie;
    $ch = curl_init($base . '/' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]);
    if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $html = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($html === false || $code !== 200 || preg_match('/(?:Fatal error|Warning|Parse error):/', $html)) throw new RuntimeException('ACS page failed: HTTP ' . $code);
    return $html;
}
function acsCsrf(string $html): string { if (!preg_match('/name="csrf" value="([a-f0-9]{64})"/', $html, $m)) throw new RuntimeException('CSRF field missing.'); return $m[1]; }
$onuId = 0;
try {
    control(['reset' => true]);
    acsCheck('ACS rejects plaintext remote connections', acsThrows(fn() => new AcsClient('http://acs.example.com', 'key'), 'HTTPS'));
    acsCheck('ACS rejects URL credentials and header injection', acsThrows(fn() => new AcsClient('https://user:pass@acs.example.com', 'key')) && acsThrows(fn() => new AcsClient($acsUrl, "bad\r\nkey")));
    acsCheck('ACS requires enabled configuration', acsThrows(fn() => $acs->checkConnection(), 'configure'));
    $acs->saveSettings($acsUrl, 'fixture-acs-key', true);
    $settings = $mgr->db()->query('SELECT * FROM acs_settings WHERE id=1')->fetch();
    acsCheck('ACS API key encrypted at rest and absent from public settings', !str_contains($settings['api_key_enc'], 'fixture-acs-key') && $mgr->decrypt($settings['api_key_enc']) === 'fixture-acs-key' && !str_contains(json_encode($acs->settings()), 'fixture-acs-key'));
    $acs->checkConnection();
    acsCheck('ACS checks the authenticated capability endpoint', $acs->settings()['status'] === 'connected');
    $other = (new Database())->getConnection();
    $lockName = 'acs_connection_' . substr(hash('sha256', 'smartolt_test'), 0, 32);
    $other->prepare('SELECT GET_LOCK(?,0)')->execute([$lockName]);
    try {
        acsCheck('connection cannot change while another ACS request is active', acsThrows(fn() => $acs->saveSettings($acsUrl, 'wrong-key', true), 'Another ACS') && $mgr->decrypt($mgr->db()->query('SELECT api_key_enc FROM acs_settings WHERE id=1')->fetchColumn()) === 'fixture-acs-key');
    } finally { $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]); }
    $client = new AcsClient($acsUrl, 'fixture-acs-key');
    $found = $client->device('485754434D2F5A9A');
    acsCheck('Huawei hex serial links to exact ASCII ACS serial', $found['id'] === 41 && $found['snapshot']['serial'] === 'HWTC4D2F5A9A');
    $safe = json_encode($found);
    acsCheck('device password and parameter secrets are discarded', !str_contains($safe, 'secret') && count($found['snapshot']['wifi']) === 2 && count($found['snapshot']['lan']) === 1 && count($found['snapshot']['hosts']) === 1);
    control(['mode' => 'ambiguous']);
    acsCheck('ambiguous duplicate serial aliases cannot link', acsThrows(fn() => $client->device('485754434D2F5A9A'), 'More than one'));
    control(['mode' => 'mismatch']);
    acsCheck('different returned serial cannot link', acsThrows(fn() => $client->device('485754434D2F5A9A'), 'different serial'));
    control(['mode' => 'malformeddevice']);
    acsCheck('malformed device fields fail without unsafe type coercion', acsThrows(fn() => $client->device('485754434D2F5A9A'), 'different serial'));
    control(['mode' => 'redirect']);
    acsCheck('ACS redirect cannot forward the API key', acsThrows(fn() => $client->checkConnection(), '302'));
    control(['mode' => 'malformed']);
    acsCheck('malformed responses are rejected without reflecting secret text', acsThrows(fn() => $client->checkConnection(), 'invalid response'));
    control(['mode' => 'rejectkey']);
    acsCheck('rejected key records an actionable connection error', acsThrows(fn() => $acs->checkConnection(), 'rejected') && $acs->settings()['status'] === 'error');
    control(['mode' => 'normal']);
    $oltId = (int)$mgr->db()->query('SELECT id FROM olts ORDER BY id LIMIT 1')->fetchColumn();
    if (!$oltId) throw new RuntimeException('Run the web fixture first.');
    $mgr->db()->prepare('INSERT INTO onus(olt_id,frame,slot,port,ont_id,sn,description,updated_at) VALUES(?,0,99,99,99,?,?,?)')->execute([$oltId, '485754434D2F5A9A', 'ACS local fixture', OltManager::now()]);
    $onuId = (int)$mgr->db()->lastInsertId();
    $cache = $acs->sync($onuId);
    acsCheck('safe ACS snapshot and report are persisted with read timestamp', $cache['acs_device_id'] == 41 && $cache['checked_at'] && count($acs->report()) === 1);
    $oltLock = 'smartolt_' . substr(hash('sha256', (new Database())->getSubdomain()), 0, 32) . '_' . $oltId;
    $other->prepare('SELECT GET_LOCK(?,0)')->execute([$oltLock]);
    try {
        acsCheck('ACS queue is blocked while OLT replacement/read holds the shared lock', acsThrows(fn() => $acs->queue($onuId, 'reboot', bin2hex(random_bytes(16))), 'OLT is busy') && control()['submitted'] === 0 && $acs->jobs($onuId) === []);
    } finally { $other->prepare('SELECT RELEASE_LOCK(?)')->execute([$oltLock]); }
    control(['mode' => 'missing']);
    acsCheck('failed ACS read keeps previous snapshot and records error', acsThrows(fn() => $acs->sync($onuId), 'not registered') && $acs->device($onuId)['last_error'] !== '' && $acs->device($onuId)['snapshot']['ssid'] === 'Fixture WiFi');
    control(['mode' => 'normal']);
    $request = bin2hex(random_bytes(16));
    $r = $acs->queue($onuId, 'refresh', $request);
    acsCheck('refresh is queued rather than reported applied', $r['status'] === 'queued' && control()['submitted'] === 1);
    $repeat = $acs->queue($onuId, 'refresh', $request);
    acsCheck('repeated form request does not queue a second task', $repeat['repeated'] && control()['submitted'] === 1);
    $acs->refreshJobs($onuId);
    acsCheck('pending remote task remains queued', $acs->jobs($onuId)[0]['status'] === 'queued');
    acsCheck('missing task IDs never mean completed', $client->taskState(41, []) === 'unknown');
    control(['mode' => 'malformedtasks']);
    acsCheck('malformed task list cannot report a successful operation', acsThrows(fn() => $acs->refreshJobs($onuId), 'invalid task list') && $acs->jobs($onuId)[0]['status'] === 'queued');
    control(['mode' => 'normal']);
    control(['task_status' => 'in_progress']); $acs->refreshJobs($onuId);
    acsCheck('running remote task is shown running', $acs->jobs($onuId)[0]['status'] === 'running');
    control(['task_status' => 'completed']); $acs->refreshJobs($onuId);
    acsCheck('completed state requires remote task confirmation', $acs->jobs($onuId)[0]['status'] === 'completed');
    $acs->queue($onuId, 'reboot', bin2hex(random_bytes(16)));
    control(['task_status' => 'failed']); $acs->refreshJobs($onuId);
    acsCheck('failed remote task is shown failed', $acs->jobs($onuId)[0]['status'] === 'failed');
    $count = control()['submitted'];
    acsCheck('invalid WiFi settings never submit', acsThrows(fn() => $acs->queue($onuId, 'wifi', bin2hex(random_bytes(16)), 'x', 'short')) && control()['submitted'] === $count);
    control(['mode' => 'badpreview']);
    acsCheck('unexpected ACS preview blocks WiFi mutation', acsThrows(fn() => $acs->queue($onuId, 'wifi', bin2hex(random_bytes(16)), 'New WiFi', 'new-wifi-password'), 'preview') && control()['submitted'] === $count);
    control(['mode' => 'normal', 'task_status' => 'pending']);
    $wifi = $acs->queue($onuId, 'wifi', bin2hex(random_bytes(16)), 'New WiFi', 'new-wifi-password');
    acsCheck('WiFi previews then queues the exact matched device', $wifi['status'] === 'queued' && control()['submitted'] === $count + 1);
    control(['mode' => 'uncertain']);
    $uncertain = bin2hex(random_bytes(16));
    acsCheck('uncertain accepted request is marked unknown', acsThrows(fn() => $acs->queue($onuId, 'reboot', $uncertain), 'could not be confirmed') && $acs->jobs($onuId)[0]['status'] === 'unknown');
    $count = control()['submitted'];
    acsCheck('unknown request cannot be automatically repeated', $acs->queue($onuId, 'reboot', $uncertain)['repeated'] && control()['submitted'] === $count);
    control(['mode' => 'malformedtaskids']);
    acsCheck('malformed returned task IDs leave accepted operation unknown', acsThrows(fn() => $acs->queue($onuId, 'refresh', bin2hex(random_bytes(16))), 'could not be confirmed') && $acs->jobs($onuId)[0]['status'] === 'unknown');
    control(['mode' => 'normal']);
    $stored = json_encode($mgr->db()->query('SELECT * FROM acs_devices')->fetchAll()) . json_encode($mgr->db()->query('SELECT * FROM acs_jobs')->fetchAll()) . json_encode($mgr->db()->query("SELECT * FROM actions WHERE action LIKE 'acs.%'")->fetchAll());
    acsCheck('device, task and new WiFi secrets never enter snapshots or event history', !str_contains($stored, 'secret') && !str_contains($stored, 'new-wifi-password') && !str_contains($stored, 'fixture-acs-key'));
    acsPage('index.php', ['username' => 'admin', 'password' => 'Fibre-Test-2026']);
    $page = acsPage('acs.php');
    acsCheck('ACS settings page renders without exposing saved key', str_contains($page, 'Test connection') && !str_contains($page, 'fixture-acs-key'));
    $settingsCsrf = acsCsrf($page);
    $page = acsPage('acs.php', ['csrf' => $settingsCsrf, 'action' => 'settings', 'base_url' => $acsUrl, 'api_key' => '', 'enabled' => '1']);
    acsCheck('save connection form preserves the existing key when blank', str_contains($page, 'ACS connection saved') && $mgr->decrypt($mgr->db()->query('SELECT api_key_enc FROM acs_settings WHERE id=1')->fetchColumn()) === 'fixture-acs-key');
    $page = acsPage('acs.php', ['csrf' => $settingsCsrf, 'action' => 'test']);
    acsCheck('test connection button verifies saved credentials', str_contains($page, 'accepted this connection and API key'));
    $page = acsPage('acs.php?onu_id=' . $onuId);
    $csrf = acsCsrf($page);
    acsCheck('ACS ONU page shows cached readings, WiFi form and honest pending status', str_contains($page, 'Queue WiFi change') && str_contains($page, 'Fixture WiFi') && str_contains($page, 'Queued means waiting') && !str_contains($page, 'device-wifi-secret'));
    $report = acsPage('reports.php?r=wifi');
    acsCheck('WiFi report displays measured ACS signal without credentials', str_contains($report, '-62') && str_contains($report, 'ACS local fixture') && !str_contains($report, 'device-wifi-secret'));
    $csv = acsPage('reports.php?r=wifi&csv=1');
    acsCheck('WiFi CSV exports only safe measured readings', str_contains($csv, 'RSSI') && str_contains($csv, '-62') && !str_contains($csv, 'secret'));
    $page = acsPage('acs.php?onu_id=' . $onuId, ['csrf' => $csrf, 'action' => 'read']);
    acsCheck('read ACS button performs authenticated read and updates task display', str_contains($page, 'Readings and task status retrieved'));
    $count = control()['submitted'];
    $page = acsPage('acs.php?onu_id=' . $onuId, ['csrf' => $csrf, 'action' => 'refresh', 'request_id' => bin2hex(random_bytes(16))]);
    acsCheck('request fresh device readings form queues an ACS action', str_contains($page, 'queued in the ACS') && control()['submitted'] === $count + 1);
    $page = acsPage('acs.php?onu_id=' . $onuId, ['csrf' => $csrf, 'action' => 'reboot', 'request_id' => bin2hex(random_bytes(16)), 'confirmed' => '1']);
    acsCheck('confirmed reboot form queues an ACS action', str_contains($page, 'queued in the ACS') && control()['submitted'] === $count + 2);
    $page = acsPage('acs.php?onu_id=' . $onuId, ['csrf' => $csrf, 'action' => 'wifi', 'request_id' => bin2hex(random_bytes(16)), 'ssid' => 'HTTP WiFi', 'wifi_password' => 'http-wifi-password', 'confirmed' => '1']);
    acsCheck('WiFi form queues and does not echo password', str_contains($page, 'queued in the ACS') && !str_contains($page, 'http-wifi-password'));
    $count = control()['submitted'];
    $page = acsPage('acs.php?onu_id=' . $onuId, ['csrf' => $csrf, 'action' => 'reboot', 'request_id' => bin2hex(random_bytes(16))]);
    acsCheck('reboot form requires explicit confirmation', str_contains($page, 'Confirm the device change') && control()['submitted'] === $count);
    $page = acsPage('acs.php?onu_id=' . $onuId, ['csrf' => 'bad', 'action' => 'refresh', 'request_id' => bin2hex(random_bytes(16))]);
    acsCheck('ACS mutations reject invalid CSRF', str_contains($page, 'session expired') && control()['submitted'] === $count);
    $mgr->db()->prepare('UPDATE onus SET sn=? WHERE id=?')->execute(['48575443FFFFFFFF', $onuId]);
    acsCheck('replaced ONU serial cannot inherit old ACS device mapping', $acs->device($onuId) === null && $acs->report() === [] && acsThrows(fn() => $acs->queue($onuId, 'reboot', bin2hex(random_bytes(16))), 'not registered') && control()['submitted'] === $count);
    $mgr->db()->prepare('UPDATE onus SET sn=? WHERE id=?')->execute(['485754434D2F5A9A', $onuId]);
    acsCheck('changing ACS URL requires a new explicit key', acsThrows(fn() => $acs->saveSettings('https://other.example.com', '', true), 'new ACS'));
    $acs->saveSettings('https://other.example.com', 'other-test-key', false);
    acsCheck('changing ACS endpoint clears mappings and prevents polling old pending requests', $acs->device($onuId) === null && !$mgr->db()->query("SELECT COUNT(*) FROM acs_jobs WHERE status IN ('queued','running','preparing')")->fetchColumn());
} finally {
    if ($onuId) $mgr->db()->prepare('DELETE FROM onus WHERE id=?')->execute([$onuId]);
    $mgr->db()->exec('DELETE FROM acs_jobs');
    $mgr->db()->exec('DELETE FROM acs_settings');
    $mgr->db()->exec("DELETE FROM actions WHERE action LIKE 'acs.%'");
    unlink($cookie);
}
echo $fails ? "{$fails} FAILED\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
