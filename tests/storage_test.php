<?php
// Integration checks against the disposable database populated by api_test.php.
require_once __DIR__ . '/../backend/lib/OltManager.php';
require_once __DIR__ . '/../backend/lib/ConfigBackups.php';
$mgr = new OltManager();
$fails = 0;
function checkStorage(string $label, bool $ok): void {
    global $fails;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
}
$olt = $mgr->olts()[0] ?? null;
if (!$olt) { fwrite(STDERR, "Run api_test.php against the disposable database first.\n"); exit(1); }
checkStorage('SNMP community is encrypted at rest', !str_contains($olt['snmp_community_enc'], 'fixture-secret') && $mgr->decrypt($olt['snmp_community_enc']) === 'fixture-secret');
checkStorage('SNMP samples were persisted', count($mgr->snmpHistory((int)$olt['id'])) >= 4);
$backups = new ConfigBackups($mgr);
$id = $backups->capture((int)$olt['id']);
$row = $mgr->db()->query('SELECT * FROM config_backups WHERE id=' . $id)->fetch();
checkStorage('configuration backup encrypted at rest', !str_contains($row['config_enc'], 'fixture-backup-secret'));
$file = $backups->download($id);
checkStorage('backup decrypts to device configuration', str_contains($file['content'], 'fixture-backup-secret') && hash('sha256', $file['content']) === $row['sha256']);
$leaked = $mgr->db()->query("SELECT COUNT(*) FROM actions WHERE transcript LIKE '%fixture-backup-secret%'")->fetchColumn();
checkStorage('configuration credentials do not leak into activity transcripts', (int)$leaked === 0);
$backups->delete($id);
checkStorage('configuration backup deletion persists', !$backups->listing((int)$olt['id']));
echo $fails ? "{$fails} FAILED\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
