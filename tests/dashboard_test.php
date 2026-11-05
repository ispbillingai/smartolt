<?php
// HTTP regression after web_test.sh. Restricted to the disposable local test database.
require_once __DIR__ . '/../backend/lib/OltManager.php';
$mgr = new OltManager();
$config = new Database();
$base = rtrim($config->getAppUrl(), '/');
if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)
    || $mgr->db()->query('SELECT DATABASE()')->fetchColumn() !== 'smartolt_test'
    || !str_contains(file_get_contents(__DIR__ . '/../backend/config/database.php'), 'LOCAL TEST FIXTURE ONLY')) {
    throw new RuntimeException('Dashboard regression may only run against the disposable local fixture.');
}
$cookie = tempnam(sys_get_temp_dir(), 'smartolt-dashboard-');
$fails = 0;
function dashboardCheck(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}
function dashboardRequest(?array $post = null): string {
    global $base, $cookie;
    $ch = curl_init($base . '/index.php');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 20,
        CURLOPT_COOKIEFILE => $cookie, CURLOPT_COOKIEJAR => $cookie]);
    if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $html = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($html === false || $status !== 200) throw new RuntimeException('Dashboard HTTP request failed.');
    return $html;
}
$snapshot = $mgr->db()->query('SELECT id,last_polled_at,status,snmp_checked_at,snmp_enabled,snmp_status FROM olts')->fetchAll();
try {
    dashboardRequest(['username' => 'admin', 'password' => 'Fibre-Test-2026']);
    $mgr->db()->prepare("UPDATE olts SET last_polled_at=?,status='online',snmp_checked_at=?,snmp_enabled=1,snmp_status='online'")
        ->execute([OltManager::now(), OltManager::now()]);
    $fresh = dashboardRequest();
    dashboardCheck('dashboard recognizes recent Telnet and SNMP reads', str_contains($fresh, 'Recent read') && str_contains($fresh, 'Responding'));
    $old = date('Y-m-d H:i:s', time() - 86400);
    $mgr->db()->prepare('UPDATE olts SET last_polled_at=?,snmp_checked_at=?')->execute([$old, $old]);
    $stale = dashboardRequest();
    dashboardCheck('stale device reads are visibly marked', substr_count($stale, '</span>Stale</span>') >= 2);
    dashboardCheck('stale figures disclose they are cached', str_contains($stale, 'Customer figures below show the last received data.'));
    dashboardCheck('stale SNMP is not shown as responding', !str_contains($stale, '</span>Responding</span>'));
    dashboardCheck('stale state preserves last observed customer totals', preg_match('/data-testid="metric-online" data-count="([0-9]+)"/', $fresh, $a)
        && preg_match('/data-testid="metric-online" data-count="([0-9]+)"/', $stale, $b) && $a[1] === $b[1]);
} finally {
    $restore = $mgr->db()->prepare('UPDATE olts SET last_polled_at=?,status=?,snmp_checked_at=?,snmp_enabled=?,snmp_status=? WHERE id=?');
    foreach ($snapshot as $row) $restore->execute([$row['last_polled_at'], $row['status'], $row['snmp_checked_at'], $row['snmp_enabled'], $row['snmp_status'], $row['id']]);
    unlink($cookie);
}
echo $fails ? "{$fails} FAILED\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
