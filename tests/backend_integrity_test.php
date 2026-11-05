<?php
// Run only inside run-local.ps1's disposable MariaDB copy, after api_test.php.
require_once __DIR__ . '/../backend/lib/OltManager.php';
$failed = 0;
function verifyBackend(string $label, bool $ok): void { global $failed; echo ($ok?'PASS ':'FAIL ').$label."\n"; if(!$ok)$failed++; }
class InventoryTestDriver extends Huawei {
    public array $inventory = [];
    public array $services = [];
    public bool $complete = true;
    public array $mutations = [];
    public function __construct() {}
    public function info(): array { return ['model'=>'MA5683T','version'=>'fixture','boards'=>[['slot'=>1,'name'=>'H805GPFD','status'=>'Normal','gpon'=>true]],'line_profiles'=>[],'srv_profiles'=>[],'vlans'=>[],'traffic'=>[10=>['cir'=>10000,'pir'=>10000]],'uptime'=>'1d','temperature'=>'40']; }
    public function onts(): array { return $this->inventory; }
    public function inventoryComplete(): bool { return $this->complete; }
    public function autofind(): array { return []; }
    public function servicePorts(): array { return $this->services; }
    public function portSummary(int $frame,int $slot,int $port): ?array { return []; }
    public function portOptical(int $frame,int $slot,int $port): array { return []; }
    public function ontDetail(int $frame,int $slot,int $port,int $ontId): array { foreach($this->inventory as $u) if($u['ont_id']===$ontId) return ['sn'=>$u['sn'],'description'=>$u['description'],'run state'=>$u['run_state'],'control flag'=>$u['control_flag']]; throw new OltError('ONT not found.'); }
    public function setDescription(int $frame,int $slot,int $port,int $ontId,string $desc): void { $this->mutations[]=['description',$desc]; foreach($this->inventory as &$u) if($u['ont_id']===$ontId)$u['description']=$desc; }
    public function trafficTables(): array { return [10=>['cir'=>10240,'pir'=>10240]]; }
    public function setService(int $frame,int $slot,int $port,int $ontId,int $vlan,int $gemport,bool $bridge,?int $upTable,?int $downTable): int { $this->mutations[]=['service',$vlan,$gemport,$bridge,$upTable,$downTable]; return 1; }
}
class IntegrityManager extends OltManager {
    public InventoryTestDriver $driver;
    public function withOlt(array $olt,callable $fn,int $wait=30) { return $fn($this->driver); }
    public function reboot(int $id): void { if(!$this->onu($id)) throw new InvalidArgumentException('ONU not found.'); }
}
class NonWaitingManager extends OltManager {
    public function withOlt(array $olt,callable $fn,int $wait=30) { return parent::withOlt($olt,$fn,0); }
}
$mgr=new IntegrityManager(); $db=$mgr->db();
if($db->query('SELECT DATABASE()')->fetchColumn()!=='smartolt_test') { fwrite(STDERR,"Refusing to run outside the isolated smartolt_test database.\n"); exit(1); }
$mgr->driver=new InventoryTestDriver();
$oltId=$mgr->saveOlt(['name'=>'Backend integrity fixture','host'=>'127.0.0.1','telnet_port'=>47023,'username'=>'test','password'=>'test']);
$olt=$mgr->olt($oltId);
$speedId=$mgr->saveSpeedProfile(['name'=>'Backend mutation speed','down_mbps'=>10,'up_mbps'=>10]);
$presetId=null;
$row=function(int $id,string $state='online'):array { return ['frame'=>0,'slot'=>1,'port'=>0,'ont_id'=>$id,'sn'=>sprintf('48575443%08X',$id+100),'description'=>'integrity_'.$id,'control_flag'=>'active','run_state'=>$state,'config_state'=>'normal','match_state'=>'match']; };
$service=function(int $id,int $vlan):array { return ['index'=>$id+100,'frame'=>0,'slot'=>1,'port'=>0,'ont_id'=>$id,'vlan'=>$vlan,'gemport'=>1,'up_table'=>10,'down_table'=>10,'state'=>'up']; };
try {
    $mgr->driver->inventory=[$row(0),$row(1)]; $mgr->driver->services=[$service(0,100),$service(1,100)];
    $mgr->refresh($olt);
    $onus=$mgr->listOnus(['olt_id'=>$oltId]); $first=$onus[0]['id']; $second=$onus[1]['id'];
    verifyBackend('first discovery initializes desired and observed VLAN',(int)$onus[0]['vlan']===100 && (int)$onus[0]['olt_vlan']===100);
    $db->prepare('UPDATE onus SET vlan=200,gemport=3,address=?,rx_power=-19,olt_rx_power=-20,signal_at=? WHERE id=?')->execute(['Keep customer metadata',OltManager::now(),$first]);
    $mgr->refresh($olt);
    $one=$mgr->onu($first);
    verifyBackend('polling preserves desired VLAN and GEM while recording device drift',(int)$one['vlan']===200 && (int)$one['gemport']===3 && (int)$one['olt_vlan']===100 && (int)$one['olt_gemport']===1);
    verifyBackend('unavailable optical reading cannot leave a fresh-looking signal',$one['rx_power']===null && $one['olt_rx_power']===null);
    verifyBackend('polling preserves user metadata',$one['address']==='Keep customer metadata');
    $originalSerial=$mgr->driver->inventory[0]['sn']; $mgr->driver->inventory[0]['sn']='48575443FFFFFFFF';
    foreach(['liveOnu','onuEthernetStats','onuMacAddresses'] as $read) {
        try { $mgr->$read($first); verifyBackend($read.' cannot attach a reused position to the wrong customer',false); }
        catch(OltError $e) { verifyBackend($read.' cannot attach a reused position to the wrong customer',strpos($e->getMessage(),'serial number at this position changed')!==false && $mgr->onu($first)['sn']===$originalSerial); }
    }
    $mgr->driver->inventory[0]['sn']=$originalSerial;
    $mgr->addNote($second,'Private note on ONU that will disappear');
    $removedTicket=$mgr->saveTicket(['onu_id'=>$second,'subject'=>'Keep support record after ONU removal']);
    $db->prepare("UPDATE onus SET mode='bridge',speed_profile_id=? WHERE id=?")->execute([$speedId,$first]);
    $beforeMutations=count($mgr->driver->mutations);
    try { $mgr->setService($first,['mode'=>'router']); verifyBackend('unsupported routing change rejects before OLT mutation',false); }
    catch(InvalidArgumentException $e) { verifyBackend('unsupported routing change rejects before OLT mutation',strpos($e->getMessage(),'WAN and LAN provisioning')!==false && count($mgr->driver->mutations)===$beforeMutations); }
    try { $mgr->describe($first,'New_name','ref',['lat'=>'invalid']); verifyBackend('invalid metadata rejects before ONU rename',false); }
    catch(InvalidArgumentException $e) { verifyBackend('invalid metadata rejects before ONU rename',count($mgr->driver->mutations)===$beforeMutations && $mgr->onu($first)['description']===$one['description']); }
    $mgr->setService($first,['vlan'=>201]);
    $serviceMutation=end($mgr->driver->mutations);
    verifyBackend('omitted speed and mode are retained during VLAN edit',(int)$mgr->onu($first)['speed_profile_id']===$speedId && $serviceMutation===['service',201,3,true,10,10]);
    $mgr->driver->inventory=[$row(0)]; $mgr->driver->services=[$service(0,100)]; $mgr->driver->complete=false;
    try { $mgr->refresh($olt); verifyBackend('unproven completeness is a visible failure',false); }
    catch (OltError $e) { verifyBackend('unproven completeness is a visible failure',strpos($e->getMessage(),'complete row count')!==false); }
    verifyBackend('unproven inventory completeness never deletes missing customers',$mgr->onu($second)!==null);
    $mgr->driver->complete=true;
    $mgr->refresh($olt);
    verifyBackend('complete inventory reconciles removed customers',$mgr->onu($second)===null);
    verifyBackend('inventory removal deletes notes and preserves detached support tickets',(int)$db->query('SELECT COUNT(*) FROM onu_notes WHERE onu_id='.(int)$second)->fetchColumn()===0 && $db->query('SELECT onu_id FROM tickets WHERE id='.$removedTicket)->fetchColumn()===null);
    $before=$mgr->onu($first); $history=(int)$db->query('SELECT COUNT(*) FROM olt_stats WHERE olt_id='.$oltId)->fetchColumn();
    $db->exec("CREATE TRIGGER integrity_fail_update BEFORE UPDATE ON onus FOR EACH ROW BEGIN IF NEW.olt_id={$oltId} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected persistence failure'; END IF; END");
    $mgr->driver->inventory=[$row(0,'offline')];
    try { $mgr->refresh($olt); verifyBackend('injected persistence failure propagates',false); }
    catch(Throwable $e) { verifyBackend('injected persistence failure propagates',strpos($e->getMessage(),'injected persistence failure')!==false); }
    try { $mgr->describe($first,'Renamed_under_failure','New_ref',['address'=>'Changed']); verifyBackend('rename persistence failure restores hardware name',false); }
    catch(OltError $e) { verifyBackend('rename persistence failure restores hardware name',strpos($e->getMessage(),'previous OLT name was restored')!==false && $mgr->driver->inventory[0]['description']===$before['description'] && $mgr->onu($first)['description']===$before['description']); }
    $db->exec('DROP TRIGGER integrity_fail_update');
    $after=$mgr->onu($first);
    verifyBackend('failed inventory commit rolls back customer state',$after['run_state']===$before['run_state'] && $after['updated_at']===$before['updated_at']);
    verifyBackend('failed inventory commit adds no history',(int)$db->query('SELECT COUNT(*) FROM olt_stats WHERE olt_id='.$oltId)->fetchColumn()===$history);
    $bulk=$mgr->bulk([$first,999999,0],'reboot');
    verifyBackend('bulk gives exactly one outcome per requested ID',$bulk['done']===1 && count($bulk['failed'])===2 && count($bulk['results'])===3);
    try { $mgr->closeTicket(999999); verifyBackend('missing ticket cannot report successful close',false); }
    catch(InvalidArgumentException $e) { verifyBackend('missing ticket cannot report successful close',strpos($e->getMessage(),'Ticket not found')!==false); }
    // Exercise the real advisory-lock path, including a different database connection. The active
    // API fixture is the only other device in this disposable database; no external OLT is used.
    $real=new OltManager(); $other=new NonWaitingManager();
    $fixture=array_values(array_filter($real->olts(),fn($o)=>(int)$o['id']!==$oltId && $o['host']==='127.0.0.1'))[0] ?? null;
    if(!$fixture) throw new RuntimeException('Missing loopback protocol fixture.');
    $fixture['snmp_enabled']=0;
    $health=$other->olt((int)$fixture['id']);
    $lockName='smartolt_'.substr(hash('sha256',(new Database())->getSubdomain()),0,32).'_'.$fixture['id'];
    $lockLiteral=$db->quote($lockName);
    $real->withOlt($fixture,function(Huawei $session) use($real,$other,$fixture,$health,$lockLiteral) {
        verifyBackend('nested operations reuse the same locked OLT session',$real->withOlt($fixture,fn(Huawei $nested)=>$nested===$session));
        try { $other->refresh($fixture,false); verifyBackend('competing refresh cannot race an OLT action',false); }
        catch(OltError $e) { verifyBackend('competing refresh cannot race an OLT action',strpos($e->getMessage(),'busy with another task')!==false); }
        $afterBusy=$other->olt((int)$fixture['id']);
        verifyBackend('a busy lock does not mark a healthy device unreachable',$afterBusy['status']===$health['status'] && $afterBusy['last_error']===$health['last_error']);
        $real->db()->exec("CREATE TRIGGER integrity_require_lock BEFORE UPDATE ON onus FOR EACH ROW BEGIN IF NEW.olt_id=".(int)$fixture['id']." AND NOT (IS_USED_LOCK({$lockLiteral}) <=> CONNECTION_ID()) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='inventory lock released too early'; END IF; END");
        try { $snapshot=$real->refresh($fixture,false); verifyBackend('inventory SQL commit remains inside the device lock',$snapshot['onus']>0); }
        finally { $real->db()->exec('DROP TRIGGER integrity_require_lock'); }
    });
    verifyBackend('OLT advisory lock releases after the workflow',$db->query("SELECT IS_FREE_LOCK({$lockLiteral})")->fetchColumn()==1);
    $mgr->driver->inventory=[]; $mgr->driver->services=[];
    $summary=$mgr->refresh($olt);
    verifyBackend('complete empty inventory removes the last deleted ONU',$summary['removed']===1 && $mgr->listOnus(['olt_id'=>$oltId])===[]);
    $mgr->driver->inventory=[$row(3)]; $mgr->driver->services=[$service(3,100)]; $mgr->refresh($olt);
    $last=$mgr->listOnus(['olt_id'=>$oltId])[0]['id'];
    $db->prepare('UPDATE onus SET customer_ref=?,address=?,vlan=333 WHERE id=?')->execute(['Previous customer','Previous address',$last]);
    $mgr->driver->inventory[0]['sn']='4857544300C0FFEE'; $mgr->refresh($olt);
    $replacement=$mgr->listOnus(['olt_id'=>$oltId])[0];
    verifyBackend('inventory serial replacement cannot inherit the prior customer or desired service',(int)$replacement['id']!==(int)$last && $replacement['customer_ref']==='' && $replacement['address']==='' && (int)$replacement['vlan']===100 && $mgr->onu((int)$last)===null);
    $last=$replacement['id'];
    $mgr->addNote($last,'Private note removed with OLT');
    $oltTicket=$mgr->saveTicket(['onu_id'=>$last,'subject'=>'Retain ticket after OLT removal']);
    $pending=$mgr->savePending(['sn'=>'4857544300DE1E7E','olt_id'=>$oltId,'description'=>'Scoped queue']);
    $presetId=$mgr->savePreset(['name'=>'Deletion integrity preset','vlan'=>100]);
    $rule=$mgr->saveAutoAction(['olt_id'=>$oltId,'preset_id'=>$presetId,'enabled'=>1]);
    $db->exec("CREATE TRIGGER integrity_fail_olt_delete BEFORE DELETE ON olts FOR EACH ROW BEGIN IF OLD.id={$oltId} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected OLT delete failure'; END IF; END");
    try { $mgr->deleteOlt($oltId); verifyBackend('failed OLT deletion restores all dependent records',false); }
    catch(PDOException $e) { verifyBackend('failed OLT deletion restores all dependent records',$mgr->onu($last)!==null && (int)$db->query('SELECT COUNT(*) FROM onu_notes WHERE onu_id='.(int)$last)->fetchColumn()===1 && (int)$db->query('SELECT onu_id FROM tickets WHERE id='.$oltTicket)->fetchColumn()===(int)$last && (int)$db->query('SELECT COUNT(*) FROM pending_onus WHERE id='.$pending)->fetchColumn()===1 && (int)$db->query('SELECT COUNT(*) FROM auto_actions WHERE id='.$rule)->fetchColumn()===1); }
    finally { $db->exec('DROP TRIGGER integrity_fail_olt_delete'); }
    $mgr->deleteOlt($oltId);
    verifyBackend('OLT deletion removes scoped authorization rules and queues instead of broadening them',(int)$db->query('SELECT COUNT(*) FROM pending_onus WHERE id='.$pending)->fetchColumn()===0 && (int)$db->query('SELECT COUNT(*) FROM auto_actions WHERE id='.$rule)->fetchColumn()===0);
    verifyBackend('OLT deletion clears notes and PON samples while retaining ticket history',(int)$db->query('SELECT COUNT(*) FROM onu_notes WHERE onu_id='.(int)$last)->fetchColumn()===0 && $db->query('SELECT onu_id FROM tickets WHERE id='.$oltTicket)->fetchColumn()===null && (int)$db->query('SELECT COUNT(*) FROM pon_stats WHERE olt_id='.$oltId)->fetchColumn()===0);
} finally {
    $db->exec('DROP TRIGGER IF EXISTS integrity_fail_update');
    $db->exec('DROP TRIGGER IF EXISTS integrity_require_lock');
    $db->exec('DROP TRIGGER IF EXISTS integrity_fail_olt_delete');
    if($mgr->olt($oltId))$mgr->deleteOlt($oltId);
    $mgr->deleteCatalog('speed',$speedId);
    if($presetId)$mgr->deleteCatalog('preset',$presetId);
}
exit($failed?1:0);
