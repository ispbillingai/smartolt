<?php
require_once __DIR__.'/../backend/lib/OltManager.php';
$mgr = new OltManager(); $db=$mgr->db();
if ($db->query('SELECT DATABASE()')->fetchColumn() !== 'smartolt_test' || !str_contains(file_get_contents(__DIR__.'/../backend/config/database.php'),'LOCAL TEST FIXTURE ONLY')) throw new RuntimeException('Disposable fixture only.');
$fails=0;
function catalogCheck(string $label,bool $ok): void { global $fails; echo ($ok?'PASS ':'FAIL ').$label."\n"; if(!$ok)$fails++; }
function refuses(callable $fn): bool { try{$fn();return false;}catch(InvalidArgumentException $e){return true;} }
catalogCheck('editing a nonexistent catalogue row is refused',refuses(fn()=>$mgr->saveZone(['id'=>9999999,'name'=>'Missing'])));
catalogCheck('deleting nonexistent queue entries and rules is refused',refuses(fn()=>$mgr->deletePending(9999999)) && refuses(fn()=>$mgr->deleteAutoAction(9999999)));
$zone=$mgr->saveZone(['name'=>'Integrity zone']);
catalogCheck('saving unchanged values succeeds for an existing row',$mgr->saveZone(['id'=>$zone,'name'=>'Integrity zone'])===$zone);
$sector=$mgr->saveSector(['name'=>'Integrity sector']);
$odb=$mgr->saveOdb(['name'=>'Integrity splitter','zone_id'=>$zone,'sector_id'=>$sector]);
$speed=$mgr->saveSpeedProfile(['name'=>'Integrity speed','down_mbps'=>10,'up_mbps'=>5]);
$type=$mgr->saveOnuType(['name'=>'Integrity type']);
$preset=$mgr->savePreset(['name'=>'Integrity preset','zone_id'=>$zone,'sector_id'=>$sector,'onu_type_id'=>$type,'vlan'=>100,'speed_profile_id'=>$speed,'gemport'=>4095]);
catalogCheck('preset accepts the driver maximum GEM port',(int)$mgr->preset($preset)['gemport']===4095);
catalogCheck('preset rejects invalid GEM ports',refuses(fn()=>$mgr->savePreset(['name'=>'badgem','gemport'=>0])) && refuses(fn()=>$mgr->savePreset(['name'=>'badgem','gemport'=>4096])));
catalogCheck('preset rejects invalid profile IDs',refuses(fn()=>$mgr->savePreset(['name'=>'badprofile','line_profile'=>'abc'])));
$pending=$mgr->savePending(['sn'=>'HWTCABCDEF12','description'=>'Before','preset_id'=>$preset,'zone_id'=>$zone,'sector_id'=>$sector,'odb_id'=>$odb,'onu_type_id'=>$type,'speed_profile_id'=>$speed]);
$rule=$mgr->saveAutoAction(['preset_id'=>$preset,'onu_type_id'=>$type,'enabled'=>1]);
$scopedType=$mgr->saveOnuType(['name'=>'Scoped delete type']);
$scopedRule=$mgr->saveAutoAction(['preset_id'=>$preset,'onu_type_id'=>$scopedType,'enabled'=>1]);
$mgr->deleteCatalog('type',$scopedType);
catalogCheck('deleting an ONU type removes its scoped rule instead of authorizing every model',
    (int)$db->query('SELECT COUNT(*) FROM auto_actions WHERE id='.$scopedRule)->fetchColumn()===0
    && (int)$db->query('SELECT onu_type_id FROM auto_actions WHERE id='.$rule)->fetchColumn()===$type);
foreach (['zone','sector','odb','speed','type'] as $kind) $mgr->deleteUnused($kind);
catalogCheck('delete unused retains entries referenced by presets, splitters, rules or queued ONUs',
    count(array_filter($mgr->zones(),fn($r)=>$r['id']==$zone))===1 && count(array_filter($mgr->sectors(),fn($r)=>$r['id']==$sector))===1
    && count(array_filter($mgr->odbs(),fn($r)=>$r['id']==$odb))===1 && count(array_filter($mgr->speedProfiles(),fn($r)=>$r['id']==$speed))===1
    && count(array_filter($mgr->onuTypes(),fn($r)=>$r['id']==$type))===1);
$db->exec("CREATE TRIGGER integrity_pending_failure BEFORE UPDATE ON pending_onus FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture forced update failure'");
try { $mgr->savePending(['sn'=>'HWTCABCDEF12','description'=>'After']); $failed=false; } catch(PDOException $e){$failed=true;} finally {$db->exec('DROP TRIGGER integrity_pending_failure');}
$get=$db->prepare('SELECT * FROM pending_onus WHERE id=?');$get->execute([$pending]);$row=$get->fetch();
catalogCheck('failed queue update preserves the existing ONU and its preset',$failed && $row['description']==='Before' && (int)$row['preset_id']===$preset);
$db->prepare('REPLACE INTO meta(k,v) VALUES (?,?)')->execute(['autofail_48575443ABCDEF12',(string)time()]);
$again=$mgr->savePending(['sn'=>'HWTCABCDEF12','description'=>'After','preset_id'=>$preset]);
catalogCheck('successful queue update preserves ID and allows retry',$again===$pending && !$db->query("SELECT v FROM meta WHERE k='autofail_48575443ABCDEF12'")->fetchColumn());
catalogCheck('queue rejects VLAN zero',refuses(fn()=>$mgr->savePending(['sn'=>'HWTCABCDEF13','vlan'=>0])));
catalogCheck('preset deletion rejects queued dependencies and preserves intended service',refuses(fn()=>$mgr->deleteCatalog('preset',$preset))
    && $mgr->preset($preset)!==null && (int)$db->query('SELECT preset_id FROM pending_onus WHERE id='.$pending)->fetchColumn()===$preset
    && (int)$db->query('SELECT COUNT(*) FROM auto_actions WHERE id='.$rule)->fetchColumn()===1);
$mgr->savePending(['sn'=>'HWTCABCDEF12','description'=>'After','preset_id'=>0]);
$db->exec("CREATE TRIGGER integrity_preset_failure BEFORE DELETE ON presets FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture forced delete failure'");
try{$mgr->deleteCatalog('preset',$preset);$failed=false;}catch(PDOException $e){$failed=true;}finally{$db->exec('DROP TRIGGER integrity_preset_failure');}
$get->execute([$pending]);$row=$get->fetch();
catalogCheck('failed preset deletion restores its rules and preserves queue',$failed && $mgr->preset($preset)!==null && $row['preset_id']===null && (int)$db->query('SELECT COUNT(*) FROM auto_actions WHERE id='.$rule)->fetchColumn()===1);
$mgr->deleteCatalog('preset',$preset);
$get->execute([$pending]);$row=$get->fetch();
catalogCheck('unreferenced preset deletion removes rules and preserves independent queue',!$mgr->preset($preset) && $row['preset_id']===null && !(int)$db->query('SELECT COUNT(*) FROM auto_actions WHERE id='.$rule)->fetchColumn());
$mgr->deletePending($pending);
foreach (['odb'=>$odb,'zone'=>$zone,'sector'=>$sector,'speed'=>$speed,'type'=>$type] as $kind=>$id) $mgr->deleteCatalog($kind,$id);

// Two different states within the same bucket must not produce impossible totals.
$olt=$mgr->olts()[0];$t=(int)(floor(time()/600)*600);
$db->beginTransaction();
try{
    $db->prepare('DELETE FROM olt_stats WHERE olt_id=? AND at>=?')->execute([$olt['id'],date('Y-m-d H:i:s',$t)]);
    $insert=$db->prepare('INSERT INTO olt_stats(olt_id,at,total,online,los,power_fail) VALUES (?,?,?,?,?,?)');
    $insert->execute([$olt['id'],date('Y-m-d H:i:s',$t),10,10,0,0]);
    $insert->execute([$olt['id'],date('Y-m-d H:i:s',$t+1),10,2,7,1]);
    $rows=$mgr->networkStatus(1,(int)$olt['id']);$latest=end($rows);
    catalogCheck('network chart uses one coherent latest snapshot per bucket',$latest['online']===2 && $latest['offline']===8 && $latest['los']===7 && $latest['power_fail']===1);
}finally{$db->rollBack();}
AppTime::setDisplayZone('America/New_York');
$db->beginTransaction();
try {
    $ticket=$mgr->saveTicket(['subject'=>'Timezone integration','start_at'=>'2026-11-05T18:30']);
    $stored=$db->query('SELECT start_at FROM tickets WHERE id='.$ticket)->fetchColumn();
    catalogCheck('ticket schedule stores and displays the chosen timezone',$stored==='2026-11-06 02:30:00' && AppTime::format('Y-m-d H:i',$stored)==='2026-11-05 18:30');
    $before=$mgr->authorizationsPerDay(2);$day=array_key_last($before);
    $stored=AppTime::toStorage($day.' 23:30:00');
    $db->prepare("INSERT INTO actions(olt_id,action,target,ok,message,source,created_at) VALUES (?,'onu.authorize','timezone test',1,'ok','panel',?)")->execute([$olt['id'],$stored]);
    $after=$mgr->authorizationsPerDay(2);
    catalogCheck('daily authorization chart groups by displayed day, not stored date',$after[$day]===$before[$day]+1);
    $filtered=$mgr->actionsFiltered(['action'=>'onu.authorize','q'=>'timezone test','from'=>$day,'to'=>$day],10);
    catalogCheck('date filters include the full selected local day',count($filtered)===1);
} finally { $db->rollBack(); AppTime::setDisplayZone(AppTime::STORAGE_ZONE); }
echo $fails?"{$fails} FAILED\n":"ALL PASSED\n";exit($fails?1:0);
