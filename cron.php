<?php
// Every 5 minutes (install.sh adds it): read each OLT whose data is older than 5 minutes, so lists,
// status and light levels stay current without anyone pressing Refresh. Command line only.
//   */5 * * * * cd /var/www/html/<sub> && flock -n /tmp/smartolt-<sub>.lock php cron.php >> /var/log/smartolt-<sub>.log 2>&1

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/backend/lib/OltManager.php';

$mgr = new OltManager();
$mgr->source = 'cron';
// Times are written by PHP (OltManager::now), so compare against PHP's clock, not MySQL's.
$st = $mgr->db()->prepare('SELECT * FROM olts WHERE last_polled_at IS NULL OR last_polled_at < ? ORDER BY last_polled_at');
$st->execute([date('Y-m-d H:i:s', time() - 290)]);
$due = $st->fetchAll();
foreach ($due as $olt) {
    $t = microtime(true);
    try {
        $s = $mgr->refresh($olt);
        printf("%s olt %d %s: %d onus, %d online, %d removed, %.1fs\n", OltManager::now(), $olt['id'], $olt['name'], $s['onus'], $s['online'], $s['removed'], microtime(true) - $t);
    } catch (Throwable $e) {
        printf("%s olt %d %s: FAILED %s\n", OltManager::now(), $olt['id'], $olt['name'], $e->getMessage());
    }
}
