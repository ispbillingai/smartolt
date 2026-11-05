<?php
require_once __DIR__ . '/backend/bootstrap.php';
require_once __DIR__ . '/backend/lib/EthernetMonitor.php';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$onu = $mgr->onu($id);
if (!$onu) back('onus.php', 'error', 'ONU not found.');
$types = array_column($mgr->onuTypes(), null, 'id');
$portCount = (int)($types[$onu['onu_type_id']]['eth_ports'] ?? 1);
if ($portCount < 1) back('onu.php?id='.$id,'error','This ONU type has no Ethernet ports configured.');
$port = (int)($_GET['port'] ?? $_POST['port'] ?? 1);
if ($port < 1 || $port > $portCount) back('traffic.php?id=' . $id, 'error', 'Choose a supported Ethernet port.');
$monitor = new EthernetMonitor($mgr);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    if (($_POST['do'] ?? '') === 'sample') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        try { $sample = $monitor->read($id,$port); echo json_encode(['ok'=>true,'sample'=>$sample,'label'=>AppTime::format('H:i:s',$sample['sampled_at'])]); }
        catch (Throwable $e) { http_response_code(502); echo json_encode(['ok'=>false,'error'=>$e->getMessage()]); }
        exit;
    }
    if (($_POST['do'] ?? '') === 'mac') {
        try { $rows = $mgr->onuMacAddresses($id); csv_download('onu-' . $id . '-mac', ['Service port','MAC','Type','Frame','Slot','Port','VLAN'], array_map(function($r) { return [$r['service_port'],$r['mac'],$r['type'],$r['frame'],$r['slot'],$r['port'],$r['vlan']]; },$rows)); }
        catch (Throwable $e) { back('traffic.php?id='.$id.'&port='.$port,'error',$e->getMessage()); }
    }
}
$history = $monitor->history($id,$port);
$pageTitle = 'ONU Ethernet traffic';
include __DIR__ . '/backend/templates/header.php';
echo page_title('ONU Ethernet traffic', ($onu['description'] ?: $onu['sn']) . ' · ' . $onu['olt_name'], btn('Back to ONU','grey','onu.php?id='.$id));
echo flash_messages();
?>
<p class="so-muted">Statistics for the selected physical ONU Ethernet port, as reported by the OLT. These counters can reset when equipment restarts; they are not a subscriber billing total. Reading does not change the ONU.</p>
<div class="so-panel"><div class="so-panel-b">
<form method="GET" class="so-inline"><input type="hidden" name="id" value="<?php echo $id; ?>"><label for="ethPort">Ethernet port</label><select id="ethPort" class="so-select" name="port" onchange="this.form.submit()"><?php for($i=1;$i<=$portCount;$i++): ?><option value="<?php echo $i; ?>" <?php echo $i===$port?'selected':''; ?>>ETH <?php echo $i; ?></option><?php endfor; ?></select></form>
<div class="so-inline" style="margin-top:16px"><button class="so-btn so-btn-blue" type="button" id="readTraffic">Read counters</button><button class="so-btn so-btn-grey" type="button" id="liveTraffic" aria-pressed="false">Start live readings</button><?php echo post_btn('mac',['id'=>$id,'port'=>$port],'Export MAC table','grey','download'); ?></div>
<p id="trafficResult" role="status" aria-live="polite">Choose Read counters to request current measurements.</p>
<div style="height:300px"><canvas id="trafficChart" aria-label="Observed traffic on the selected Ethernet port"></canvas></div>
<div class="so-table-wrap"><table class="so-table"><thead><tr><th>Read at</th><th>Received bytes</th><th>Sent bytes</th><th>Up kbps</th><th>Down kbps</th></tr></thead><tbody id="trafficRows"><?php foreach(array_reverse($history) as $point): ?><tr><?php foreach([AppTime::format('Y-m-d H:i:s',$point['at']),$point['received_bytes'],$point['sent_bytes'],$point['up_kbps'],$point['down_kbps']] as $cell): ?><td><?php echo e($cell === null ? 'Not reported' : (string)$cell); ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
</div></div>
<script>
(function(){
 const samples=<?php echo json_encode(array_map(function($p){return ['label'=>AppTime::format('H:i:s',$p['at']),'up'=>$p['up_kbps'],'down'=>$p['down_kbps']];},$history),JSON_HEX_TAG); ?>;
 const status=document.getElementById('trafficResult'),read=document.getElementById('readTraffic'),live=document.getElementById('liveTraffic');
 let busy=false,timer=null,chart=null;
 if(typeof Chart!=='undefined') chart=new Chart(document.getElementById('trafficChart'),{type:'line',data:{labels:samples.map(p=>p.label),datasets:[{label:'Up kbps',data:samples.map(p=>p.up),borderColor:'#56cbb6',pointRadius:1},{label:'Down kbps',data:samples.map(p=>p.down),borderColor:'#8aaef1',pointRadius:1}]},options:{responsive:true,maintainAspectRatio:false,spanGaps:false,scales:{y:{beginAtZero:true}}}});
 function stop(){clearInterval(timer);timer=null;live.textContent='Start live readings';live.setAttribute('aria-pressed','false');}
 async function poll(){if(busy)return;busy=true;read.disabled=true;try{
 const body=new URLSearchParams({do:'sample',id:<?php echo json_encode((string)$id); ?>,port:<?php echo json_encode((string)$port); ?>,csrf:<?php echo json_encode($_SESSION['csrf']); ?>});
 const response=await fetch('traffic.php',{method:'POST',body,credentials:'same-origin'});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.error||'Could not read counters.');
 const s=result.sample;status.textContent='Last successful read: '+result.label;
 const row=document.createElement('tr');[result.label,s.received_bytes,s.sent_bytes,s.up_kbps,s.down_kbps].forEach(v=>{const td=document.createElement('td');td.textContent=v===null?'Not reported':String(v);row.append(td);});const rows=document.getElementById('trafficRows');rows.prepend(row);while(rows.children.length>500)rows.lastElementChild.remove();
 if(chart){chart.data.labels.push(result.label);chart.data.datasets[0].data.push(s.up_kbps);chart.data.datasets[1].data.push(s.down_kbps);if(chart.data.labels.length>120){chart.data.labels.shift();chart.data.datasets.forEach(d=>d.data.shift());}chart.update();}
 }catch(error){status.textContent=error.message;stop();}finally{busy=false;read.disabled=false;}}
 read.addEventListener('click',poll);live.addEventListener('click',()=>{if(timer){stop();return;}live.textContent='Stop live readings';live.setAttribute('aria-pressed','true');poll();timer=setInterval(()=>{if(!document.hidden)poll();},10000);});window.addEventListener('pagehide',stop);
})();
</script>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
