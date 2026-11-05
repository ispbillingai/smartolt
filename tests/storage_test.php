<?php
// Integration checks against the disposable database populated by api_test.php.
require_once __DIR__ . '/../backend/lib/OltManager.php';
require_once __DIR__ . '/../backend/lib/ConfigBackups.php';
require_once __DIR__ . '/../backend/lib/EthernetMonitor.php';
$mgr = new OltManager();
$db = $mgr->db();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'smartolt_test' || !str_contains(file_get_contents(__DIR__.'/../backend/config/database.php'),'LOCAL TEST FIXTURE ONLY')) throw new RuntimeException('Disposable fixture only.');
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
$oltId = (int)$olt['id'];
$oldBackupState=$mgr->olt($oltId);
foreach(['timestamp','log'] as $failurePoint) {
    $db->prepare('UPDATE olts SET last_backup_at=?,backup_error=? WHERE id=?')->execute(['2026-01-01 00:00:00','Previous backup error',$oltId]);
    $countBefore=(int)$db->query('SELECT COUNT(*) FROM config_backups WHERE olt_id='.$oltId)->fetchColumn();
    $logsBefore=(int)$db->query("SELECT COUNT(*) FROM actions WHERE olt_id={$oltId} AND action='olt.backup' AND ok=1")->fetchColumn();
    $trigger='storage_fail_backup_'.$failurePoint;
    if($failurePoint==='timestamp') $db->exec("CREATE TRIGGER {$trigger} BEFORE UPDATE ON olts FOR EACH ROW BEGIN IF NEW.id={$oltId} AND NOT (NEW.last_backup_at <=> OLD.last_backup_at) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected backup timestamp failure'; END IF; END");
    else $db->exec("CREATE TRIGGER {$trigger} BEFORE INSERT ON actions FOR EACH ROW BEGIN IF NEW.olt_id={$oltId} AND NEW.action='olt.backup' AND NEW.ok=1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected backup log failure'; END IF; END");
    try { $backups->capture($oltId); $rejected=false; }
    catch(PDOException $e) { $rejected=str_contains($e->getMessage(),'injected backup'); }
    finally { $db->exec('DROP TRIGGER '.$trigger); }
    $after=$mgr->olt($oltId);
    checkStorage('backup '.$failurePoint.' failure rolls back snapshot, schedule and success log',$rejected && !$db->inTransaction()
        && (int)$db->query('SELECT COUNT(*) FROM config_backups WHERE olt_id='.$oltId)->fetchColumn()===$countBefore
        && $after['last_backup_at']==='2026-01-01 00:00:00' && $after['backup_error']==='Previous backup error'
        && (int)$db->query("SELECT COUNT(*) FROM actions WHERE olt_id={$oltId} AND action='olt.backup' AND ok=1")->fetchColumn()===$logsBefore);
}
$db->prepare('UPDATE olts SET last_backup_at=?,backup_error=? WHERE id=?')->execute([$oldBackupState['last_backup_at'],$oldBackupState['backup_error'],$oltId]);
$backups->schedule($oltId, 6);
checkStorage('backup interval persisted', (int)$mgr->olt($oltId)['backup_interval_hours'] === 6);
$mgr->db()->exec('UPDATE olts SET last_backup_at=NULL WHERE id='.$oltId);
$due = $backups->runDue();
checkStorage('due backup captures the actual simulated device configuration', isset($due['done'][$oltId]) && str_contains($backups->download($due['done'][$oltId])['content'], 'fixture-backup-secret'));
checkStorage('successful backup records its read time', (bool)$mgr->olt($oltId)['last_backup_at']);
checkStorage('a second scheduler run does not duplicate a recent backup', $backups->runDue() === ['done'=>[], 'failed'=>[]]);
$before = count($backups->listing($oltId));
$mgr->db()->exec('UPDATE olts SET last_backup_at=NULL,telnet_port=1 WHERE id='.$oltId);
$failure = $backups->runDue();
checkStorage('unreachable scheduled backup records failure without creating a snapshot', isset($failure['failed'][$oltId]) && count($backups->listing($oltId)) === $before && (bool)$mgr->olt($oltId)['backup_error']);
$mgr->db()->prepare('UPDATE olts SET telnet_port=? WHERE id=?')->execute([$olt['telnet_port'],$oltId]);
$retried = $backups->runDue();
checkStorage('failed scheduled backup retries and clears the error', isset($retried['done'][$oltId]) && $mgr->olt($oltId)['backup_error'] === '');
$backups->schedule($oltId,0);
checkStorage('disabled schedule performs no capture', $backups->runDue() === ['done'=>[], 'failed'=>[]]);
try { $backups->schedule($oltId,1); $invalid=false; } catch (InvalidArgumentException $e) { $invalid=true; }
checkStorage('unsupported schedule rejected without changing saved interval', $invalid && (int)$mgr->olt($oltId)['backup_interval_hours'] === 0);

$onu = $mgr->listOnus(['q'=>'alice_home'])[0];
$monitor = new EthernetMonitor($mgr);
$sample = $monitor->read((int)$onu['id'],1);
$history = $monitor->history((int)$onu['id'],1);
checkStorage('Ethernet monitor reads and persists a sample', count($history) === 1 && $history[0]['received_bytes'] === (string)$sample['received_bytes'] && $history[0]['sent_bytes'] === (string)$sample['sent_bytes']);
checkStorage('traffic report matches a real sample by OLT and search', count($monitor->latest($oltId,0,'alice')) === 1 && count($monitor->latest($oltId,0,'no-such-subscriber')) === 0);
$mgr->db()->prepare('UPDATE onu_ethernet_samples SET at=? WHERE onu_id=?')->execute([date('Y-m-d H:i:s',time()-48*3600),$onu['id']]);
checkStorage('traffic report time filter limits reading freshness', count($monitor->latest($oltId,0,'alice',24)) === 0 && count($monitor->latest($oltId,0,'alice',168)) === 1);
try { $monitor->read((int)$onu['id'],999); $invalid=false; } catch (InvalidArgumentException $e) { $invalid=true; }
checkStorage('invalid Ethernet port is rejected before connecting', $invalid);

$mgr->db()->exec('UPDATE olts SET last_polled_at=NOW()');
$mgr->db()->prepare('REPLACE INTO meta(k,v) VALUES (?,?)')->execute(['last_maintenance','0']);
$lock = 'smartolt_cron_'.substr(hash('sha256',(string)$mgr->db()->query('SELECT DATABASE()')->fetchColumn()),0,32);
$mgr->db()->query('SELECT GET_LOCK('.$mgr->db()->quote($lock).',0)');
$command = escapeshellarg(PHP_BINARY).' -d extension=pdo_mysql '.escapeshellarg(__DIR__.'/../cron.php');
exec($command.' 2>&1',$lockedOutput,$lockedCode);
$mgr->db()->query('SELECT RELEASE_LOCK('.$mgr->db()->quote($lock).')');
checkStorage('overlapping polling job exits without running maintenance', $lockedCode===0 && str_contains(implode('\n',$lockedOutput),'already running') && $mgr->db()->query("SELECT v FROM meta WHERE k='last_maintenance'")->fetchColumn()==='0');
exec($command.' 2>&1',$cronOutput,$cronCode);
checkStorage('cron runs maintenance outside a fixed minute window', $cronCode===0 && (int)$mgr->db()->query("SELECT v FROM meta WHERE k='last_maintenance'")->fetchColumn()>time()-60);
$backups->schedule($oltId,6);
$mgr->db()->exec('UPDATE olts SET last_backup_at=NULL,telnet_port=1 WHERE id='.$oltId);
exec($command.' 2>&1',$failedOutput,$failedCode);
checkStorage('cron exits nonzero when a scheduled backup fails', $failedCode===1 && str_contains(implode('\n',$failedOutput),'1 failed'));
$mgr->db()->prepare('UPDATE olts SET telnet_port=?,backup_interval_hours=0 WHERE id=?')->execute([$olt['telnet_port'],$oltId]);

// A real second DB connection must stop after waiting for another request's migration lock.
$schemaLock=(string)$db->query("SELECT CONCAT(DATABASE(),'_schema')")->fetchColumn();
$schemaVersion=(string)$db->query("SELECT v FROM meta WHERE k='schema_version'")->fetchColumn();
$contender=(new Database())->getConnection();
if ((int)$db->query('SELECT GET_LOCK('.$db->quote($schemaLock).',0)')->fetchColumn()!==1) throw new RuntimeException('Could not acquire the test schema lock.');
try {
    $db->prepare("UPDATE meta SET v=? WHERE k='schema_version'")->execute([Schema::VERSION-1]);
    $started=microtime(true); $rejected=false;
    try { Schema::ensure($contender); }
    catch(RuntimeException $e) { $rejected=str_contains($e->getMessage(),'database upgrade') && str_contains($e->getMessage(),'Retry'); }
    checkStorage('schema lock timeout stops the request before using an incomplete schema',$rejected && microtime(true)-$started>=29
        && (int)$db->query("SELECT v FROM meta WHERE k='schema_version'")->fetchColumn()===Schema::VERSION-1);
} finally {
    $db->prepare("UPDATE meta SET v=? WHERE k='schema_version'")->execute([$schemaVersion]);
    $db->query('SELECT RELEASE_LOCK('.$db->quote($schemaLock).')');
    $contender=null;
}
Schema::ensure($db);
checkStorage('schema lock regression restores the version and releases its lock',(int)$db->query('SELECT IS_FREE_LOCK('.$db->quote($schemaLock).')')->fetchColumn()===1
    && (string)$db->query("SELECT v FROM meta WHERE k='schema_version'")->fetchColumn()===$schemaVersion);
echo $fails ? "{$fails} FAILED\n" : "ALL PASSED\n";
exit($fails ? 1 : 0);
