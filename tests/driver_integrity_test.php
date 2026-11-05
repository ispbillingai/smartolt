<?php
require_once __DIR__ . '/../backend/lib/Huawei.php';
$failed = 0;
function verifyDriver(string $label, bool $ok): void { global $failed; echo ($ok?'PASS ':'FAIL ') . $label . "\n"; if (!$ok) $failed++; }
function rejectsDriver(string $label, callable $f, string $part): void { try { $f(); verifyDriver($label,false); } catch (Throwable $e) { verifyDriver($label,strpos($e->getMessage(),$part)!==false); } }
class StatefulDriverWire extends Telnet {
    public array $services = [10=>['vlan'=>100,'ont'=>0,'gem'=>1,'up'=>null,'down'=>null]];
    public array $onts = [0=>true];
    public array $serials = [0=>'4857544300000001'];
    public int $native = 100;
    public bool $wrappedConfig = false;
    public array $commands = [];
    public string $failure = '';
    private string $reply = '';
    public function __construct() {}
    public function write(string $text, bool $secret = false): void {
        $cmd=trim($text); $this->commands[]=$cmd; $out='';
        if (preg_match('/^display service-port port 0\/1\/0 ont (\d+)$/',$cmd,$m)) {
            foreach($this->services as $id=>$row) if ($row['ont']==$m[1]) $out.=sprintf("%d %d common gpon 0/1/0 %d %d vlan %d %s %s up\n",$id,$row['vlan'],$row['ont'],$row['gem'],$row['vlan'],$row['up']??'-',$row['down']??'-');
            $out=$out?:"Failure: No service virtual port can be operated\n";
        } elseif(preg_match('/^display current-configuration ont 0\/1\/0 (\d+)$/',$cmd,$m)) {
            foreach($this->services as $id=>$row) if($row['ont']==$m[1]) {
                $out.="service-port {$id} vlan {$row['vlan']} gpon 0/1/0 ont {$row['ont']} gemport {$row['gem']} multi-service user-vlan {$row['vlan']} tag-transform translate";
                if($row['up']!==null) $out.=($this->wrappedConfig?"\n  ":' ')."inbound traffic-table index {$row['up']} outbound traffic-table index {$row['down']}";
                $out.="\n";
            }
            $out.="ont port native-vlan 0 {$m[1]} eth 1 vlan {$this->native} priority 0\n";
        } elseif(preg_match('/^undo service-port (\d+)$/',$cmd,$m)) { unset($this->services[(int)$m[1]]);
        } elseif(preg_match('/^service-port (?:(\d+) )?vlan (\d+) gpon 0\/1\/0 ont (\d+) gemport (\d+) multi-service user-vlan \d+ tag-transform translate(?: inbound traffic-table index (\d+) outbound traffic-table index (\d+))?$/',$cmd,$m)) {
            if($m[1]==='' && in_array($this->failure,['reject_add','rollback'],true)) $out='Failure: The VLAN does not exist';
            elseif($m[1]!=='' && $this->failure==='rollback') { /* Simulated silent failure restoring the original service. */ }
            elseif(!($m[1]==='' && $this->failure==='silent_add')) $this->services[$m[1]!==''?(int)$m[1]:20]=['vlan'=>(int)$m[2],'ont'=>(int)$m[3],'gem'=>(int)$m[4],'up'=>isset($m[5])?(int)$m[5]:null,'down'=>isset($m[6])?(int)$m[6]:null];
        } elseif(strpos($cmd,'ont port native-vlan ')===0 && strpos($cmd,' vlan 200 ')!==false && $this->failure==='native') { $out='Failure: Unsupported native VLAN';
        } elseif(preg_match('/^ont port native-vlan 0 \d+ eth 1 vlan (\d+) priority 0$/',$cmd,$m)) { if($this->failure!=='silent_native')$this->native=(int)$m[1];
        } elseif(preg_match('/^ont add 0 sn-auth ([0-9A-F]+)/',$cmd,$m)) { $this->onts[2]=true; $this->serials[2]=$m[1]; $out='PortID :0, ONTID :'.($this->failure==='wrong_authorize_id'?'0':'2');
        } elseif(preg_match('/^ont delete 0 (\d+)$/',$cmd,$m)) {
            if($this->failure==='reject_delete') $out='Failure: Device busy';
            elseif($this->failure!=='silent_delete') { unset($this->onts[(int)$m[1]]); $out='Number of ONTs that can be deleted: 1, success: 1'; }
        } elseif(preg_match('/^display ont info 0 (\d+)$/',$cmd,$m)) { $out=isset($this->onts[(int)$m[1]]) ? "Control flag : active\nRun state : online\nSN : ".$this->serials[(int)$m[1]]."\nDescription : Original\n" : 'Failure: The ONT does not exist';
        } elseif($cmd==='display ont info 0 all') { $out='unrecognized inventory'; }
        $this->reply=$cmd."\n".$out."\nOLT(config)#";
    }
    public function readUntil(string $pattern, ?int $timeout = null): string { return $this->reply; }
}
foreach (['reject_add','silent_add','native','silent_native'] as $failure) {
    $wire=new StatefulDriverWire(); $wire->failure=$failure; $driver=new Huawei($wire); $before=$wire->services;
    rejectsDriver('service failure '.$failure.' is visible',fn()=>$driver->setService(0,1,0,0,200,1,true,null,null),'Service change failed');
    verifyDriver('service failure '.$failure.' restores old service',$wire->services===$before);
}
$wire=new StatefulDriverWire(); $wire->services[11]=['vlan'=>300,'ont'=>0,'gem'=>2,'up'=>null,'down'=>null]; $before=$wire->services; $driver=new Huawei($wire);
rejectsDriver('multi-service change cannot erase IPTV/other services',fn()=>$driver->setService(0,1,0,0,200,1,true,null,null),'multiple service ports');
verifyDriver('multi-service rejection leaves all services unchanged',$wire->services===$before);
$wire=new StatefulDriverWire(); $driver=new Huawei($wire); $driver->setService(0,1,0,0,200,1,true,10,11);
verifyDriver('service change validates VLAN GEM and speed readback',count($wire->services)===1 && $wire->services[20]['vlan']===200 && $wire->services[20]['up']===10 && $wire->services[20]['down']===11);
$wire=new StatefulDriverWire(); $wire->wrappedConfig=true; $wire->services[10]['up']=10; $wire->services[10]['down']=11; $wire->failure='native'; $before=$wire->services; $driver=new Huawei($wire);
rejectsDriver('wrapped running configuration remains recoverable',fn()=>$driver->setService(0,1,0,0,200,1,true,12,13),'previous service configuration was restored');
verifyDriver('rollback preserves wrapped speed table arguments',$wire->services===$before);
$wire=new StatefulDriverWire(); $wire->failure='rollback'; $driver=new Huawei($wire);
rejectsDriver('silent rollback failure cannot claim service recovery',fn()=>$driver->setService(0,1,0,0,200,1,true,null,null),'rollback could not be completed');
$wire=new StatefulDriverWire(); $wire->failure='reject_add'; $driver=new Huawei($wire);
rejectsDriver('failed authorization is rolled back',fn()=>$driver->authorize(0,1,0,'4857544300000002',10,20,'New',200,1,true),'rolled back');
verifyDriver('failed authorization removes only newly added ONU',array_keys($wire->onts)===[0] && count($wire->services)===1);
$wire=new StatefulDriverWire(); $wire->failure='wrong_authorize_id'; $driver=new Huawei($wire); $before=$wire->services;
rejectsDriver('authorization checks physical serial before using returned ID',fn()=>$driver->authorize(0,1,0,'4857544300000002',10,20,'New',200,1,true),'did not confirm the authorized serial');
verifyDriver('wrong authorization ID never removes or changes existing subscriber',$wire->services===$before && isset($wire->onts[0]) && !array_filter($wire->commands,fn($c)=>strpos($c,'undo service-port ')===0 || strpos($c,'ont delete ')===0));
$driver=new Huawei(new StatefulDriverWire());
rejectsDriver('silent activation failure fails readback',fn()=>$driver->setActive(0,1,0,0,false),'did not confirm');
rejectsDriver('silent rename failure fails readback',fn()=>$driver->setDescription(0,1,0,0,'New'),'did not confirm');
rejectsDriver('silent serial replacement failure fails readback',fn()=>$driver->replaceSn(0,1,0,0,'4857544300000002'),'did not confirm');
rejectsDriver('command newline injection rejected',fn()=>$driver->raw("display version\nreboot"),'printable line');
rejectsDriver('confirmed command injection rejected',fn()=>$driver->confirm("save\nreboot"),'printable line');
rejectsDriver('login newline rejected before opening a connection',fn()=>Huawei::connect('127.0.0.1',23,"admin\nreboot",'test'),'one line');
foreach (['reject_delete','silent_delete'] as $failure) {
    $wire=new StatefulDriverWire(); $wire->failure=$failure; $driver=new Huawei($wire); $before=$wire->services;
    rejectsDriver('failed deletion '.$failure.' reports failure',fn()=>$driver->delete(0,1,0,0),'ONU removal failed');
    verifyDriver('failed deletion '.$failure.' restores subscriber services',$wire->services===$before && isset($wire->onts[0]));
}
$wire=new StatefulDriverWire(); $driver=new Huawei($wire);
verifyDriver('successful deletion confirms ONT absence',$driver->delete(0,1,0,0)===1 && !$wire->services && !$wire->onts);
$stats=Huawei::parseEthernetStats("Received right bytes : 9007199254740993\nSent right bytes : 0\nUp traffic (kbps) : 125.5\nDown traffic (kbps) : 0\n");
verifyDriver('ONU Ethernet counters preserve exact bytes and measured rates',$stats['received_bytes']==='9007199254740993' && $stats['up_kbps']===125.5 && $stats['down_kbps']===0.0);
$partial=Huawei::parseEthernetStats("Received right bytes : 10\nSent right bytes : -\n");
verifyDriver('unsupported Ethernet metrics remain unknown',$partial['sent_bytes']===null && $partial['up_kbps']===null);
rejectsDriver('unrecognized Ethernet output never reports zero',fn()=>Huawei::parseEthernetStats('Unknown output'),'recognized');
$mac=Huawei::parseMacAddresses("SRV-P BUNDLE TYPE MAC MAC TYPE F /S /P VPI VCI VLAN ID\n100 - gpon 1010-1010-1010 dynamic 0 / 2 / 0 1 1 1000\nTotal: 1\n");
verifyDriver('Huawei documented MAC table parser',$mac[0]['mac']==='10:10:10:10:10:10' && $mac[0]['service_port']===100 && $mac[0]['vlan']===1000);
rejectsDriver('partial MAC table fails instead of exporting incomplete data',fn()=>Huawei::parseMacAddresses("garbled\nTotal: 4\n"),'completely');
verifyDriver('Huawei deactive flag normalizes to disabled state',Huawei::controlFlag('deactive')==='deactivated');
verifyDriver('integer optical levels are accepted',Huawei::parseOpticalTable("0 -19 2 -21 48\n")[0]['rx_power']===-19.0);
verifyDriver('zero-success device acknowledgements are failures',Huawei::findError('Number of ONTs that can be deleted: 1, success: 0')!==null);
class RepliesWire extends Telnet {
    private array $replies;
    public function __construct(array $replies) { $this->replies=$replies; }
    public function write(string $text,bool $secret=false): void {}
    public function readUntil(string $pattern,?int $timeout=null): string { if(!$this->replies)throw new RuntimeException('Missing fixture response'); return "\n".array_shift($this->replies)."\nOLT(config)#"; }
}
$vlanOne="VLAN Type Attribute\n1 smart common 1 0 -\nTotal: 1";
$driver=new Huawei(new RepliesWire([$vlanOne,'',$vlanOne,'']));
rejectsDriver('silent VLAN creation cannot report success',fn()=>$driver->addVlan(200,''),'did not confirm');
$driver=new Huawei(new RepliesWire(['',"VLAN Type Attribute\n200 smart common 1 0 -\nTotal: 1"]));
rejectsDriver('silent VLAN deletion cannot report success',fn()=>$driver->deleteVlan(200),'did not confirm');
$driver=new Huawei(new RepliesWire(['',"TID CIR CBS PIR PBS\n0 1024 32768 1024 32768 0\nTotal: 1"]));
rejectsDriver('silent speed-table creation cannot report success',fn()=>$driver->createTrafficTable(10,10240),'did not confirm');
foreach(['Failure: The automatically found ONTs do not exist','Failure: The automatically found ONT does not exist','The number of GPON autofind ONT is 0'] as $empty) {
    $driver=new Huawei(new RepliesWire([$empty]));
    verifyDriver('recognized empty discovery: '.$empty,$driver->autofind()===[]);
}
$discovery="--------------------\nNumber : 1\nF/S/P : 0/ 1 /0\nOnt SN : 4857544300000001 (HWTC-00000001)\nVenderID : HWTC\nOnt EquipmentID : EG8145V5\n--------------------\nThe number of GPON autofind ONT is 1";
$driver=new Huawei(new RepliesWire([$discovery])); $found=$driver->autofind();
verifyDriver('discovery verifies row count and handles Huawei field variants',count($found)===1 && $found[0]['vendor']==='HWTC' && $found[0]['slot']===1);
foreach(['unknown response',str_replace('is 1','is 2',$discovery),str_replace('4857544300000001','invalidserial',$discovery),str_replace('The number of GPON autofind ONT is 1','',$discovery)] as $partial) {
    $driver=new Huawei(new RepliesWire([$partial]));
    rejectsDriver('unrecognized or incomplete discovery preserves cached list',fn()=>$driver->autofind(),'incomplete or unrecognized');
}
$driver=new Huawei(new RepliesWire(['Failure: The user profile does not exist']));
rejectsDriver('unrelated does-not-exist error is not empty discovery',fn()=>$driver->autofind(),'user profile');
exit($failed?1:0);
