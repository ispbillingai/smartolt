<?php
// Every 5 minutes (install.sh adds it): read each OLT whose data is older than 5 minutes, so lists,
// status and light levels stay current without anyone pressing Refresh. Command line only.
//   */5 * * * * cd /var/www/html/<sub> && flock -n /tmp/smartolt-<sub>.lock php cron.php >> /var/log/smartolt-<sub>.log 2>&1

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/backend/lib/OltManager.php';
require_once __DIR__ . '/backend/lib/ConfigBackups.php';

$mgr = new OltManager();
$mgr->source = 'cron';
$jobLock = $mgr->db()->quote('smartolt_cron_' . substr(hash('sha256', (string)$mgr->db()->query('SELECT DATABASE()')->fetchColumn()), 0, 32));
if (!(int)$mgr->db()->query("SELECT GET_LOCK({$jobLock}, 0)")->fetchColumn()) {
    fwrite(STDERR, "Another polling job is already running.\n");
    exit(0);
}
$failed = false;
try {
// Times are written by PHP (OltManager::now), so compare against PHP's clock, not MySQL's.
$st = $mgr->db()->prepare('SELECT * FROM olts WHERE last_polled_at IS NULL OR last_polled_at < ? ORDER BY last_polled_at');
$st->execute([date('Y-m-d H:i:s', time() - 290)]);
$due = $st->fetchAll();
foreach ($due as $olt) {
    $t = microtime(true);
    try {
        $s = $mgr->refresh($olt);
        printf("%s olt %d %s: %d onus, %d online, %d waiting, %d removed, %.1fs\n", OltManager::now(), $olt['id'], $olt['name'], $s['onus'], $s['online'], $s['waiting'], $s['removed'], microtime(true) - $t);
        // Waiting ONUs that an auto action or the later list covers are authorized now.
        $a = $mgr->runAuto($mgr->olt((int)$olt['id']));
        $failed = $failed || (bool)$a['failed'];
        if ($a['done'] || $a['failed']) {
            printf("%s olt %d auto: %d authorized%s\n", OltManager::now(), $olt['id'], $a['done'], $a['failed'] ? ', failed: ' . implode('; ', $a['failed']) : '');
        }
    } catch (Throwable $e) {
        $failed = true;
        printf("%s olt %d %s: FAILED %s\n", OltManager::now(), $olt['id'], $olt['name'], $e->getMessage());
    }
}
// Persist run times: delayed jobs must not miss maintenance or run auto-sync twice.
$meta = $mgr->db()->prepare('SELECT v FROM meta WHERE k=?');
$meta->execute(['last_maintenance']);
if (time() - (int)$meta->fetchColumn() >= 3600) {
    $mgr->prune();
    $mgr->db()->prepare('REPLACE INTO meta(k,v) VALUES (?,?)')->execute(['last_maintenance', (string)time()]);
}
$meta->execute(['last_auto_sync']);
if ($mgr->autoSyncOn() && time() - (int)$meta->fetchColumn() >= 86400) {
    [$done, $syncFailed] = $mgr->runAutoSync();
    $failed = $failed || (bool)$syncFailed;
    printf("%s auto sync: %d resynced%s\n", OltManager::now(), $done, $syncFailed ? ', failed: ' . implode('; ', $syncFailed) : '');
    $mgr->db()->prepare('REPLACE INTO meta(k,v) VALUES (?,?)')->execute(['last_auto_sync', (string)time()]);
}
$backup = (new ConfigBackups($mgr))->runDue();
$failed = $failed || (bool)$backup['failed'];
printf("%s backups: %d captured, %d failed\n", OltManager::now(), count($backup['done']), count($backup['failed']));
} finally {
    $mgr->db()->query("SELECT RELEASE_LOCK({$jobLock})");
}
exit($failed ? 1 : 0);
