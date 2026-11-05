<?php
require_once __DIR__ . '/backend/bootstrap.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$runningConfig = null;
$swInfo = null;

// The status box asks for a live read after the page has opened, so the page itself never waits on the OLT.
if (($_GET['ajax'] ?? '') === 'status') {
    header('Content-Type: application/json');
    try {
        [$u] = $mgr->liveOnu($id);
        echo json_encode(['ok' => true, 'group' => $u['status_group'], 'status' => status_label($u['status_group']),
            'cause' => $u['status_group'] === 'online' ? '' : (string)$u['last_down_cause'],
            'rx' => $u['rx_power'] === null ? null : number_format((float)$u['rx_power'], 2),
            'olt_rx' => $u['olt_rx_power'] === null ? null : number_format((float)$u['olt_rx_power'], 2),
            'level' => strtolower(signal_level($u['rx_power'])), 'temperature' => $u['temperature']]);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $here = "onu.php?id={$id}";
    try {
        switch ($_POST['do'] ?? '') {
            case 'rename':
                $desc = $mgr->describe($id, (string)($_POST['description'] ?? ''), (string)($_POST['customer_ref'] ?? ''));
                $mgr->saveMeta($id, $_POST);
                back($here, 'success', "Saved as {$desc}.");
            case 'details':
                $mgr->saveMeta($id, $_POST);
                back($here, 'success', 'Details saved.');
            case 'vip':
                $mgr->saveMeta($id, ['vip' => $_POST['vip'] ?? '']);
                back($here, 'success', !empty($_POST['vip']) ? 'Marked as VIP.' : 'No longer VIP.');
            case 'service':
                $mgr->setService($id, $_POST);
                back($here, 'success', 'Service changed on the OLT.');
            case 'resync':
                $mgr->resync($id);
                back($here, 'success', 'The saved VLAN, mode and speed were put on the OLT again.');
            case 'restore':
                $mgr->restoreDefaults($id);
                back($here, 'success', 'Factory defaults restored. The ONU restarts and gets its configuration from the OLT again.');
            case 'disable':
                $mgr->setActive($id, false);
                back($here, 'success', 'ONU disabled. It stays authorized but gets no service until you enable it.');
            case 'enable':
                $mgr->setActive($id, true);
                back($here, 'success', 'ONU enabled.');
            case 'replace':
                $sn = $mgr->replaceSn($id, (string)($_POST['new_sn'] ?? ''));
                back($here, 'success', "Serial changed to {$sn}. Plug in the new ONU; it gets the same settings.");
            case 'reboot':
                $mgr->reboot($id);
                back($here, 'success', 'Reboot sent. The ONU is back in about two minutes.');
            case 'delete':
                $mgr->deleteOnu($id);
                back('onus.php', 'success', 'ONU deleted from the OLT.');
            case 'note':
                $mgr->addNote($id, (string)($_POST['note'] ?? ''));
                back($here, 'success', 'Note added.');
            case 'config':
                $runningConfig = $mgr->runningConfig($id);
                break;
            case 'sw_info':
                $swInfo = $mgr->swInfo($id);
                break;
        }
    } catch (Throwable $e) {
        back($here, 'error', $e->getMessage());
    }
}

$onu = $mgr->onu($id);
if (!$onu) {
    back('onus.php', 'error', 'ONU not found.');
}
$olt = OltManager::publicOlt($mgr->olt((int)$onu['olt_id']));
$type = null;
foreach ($mgr->onuTypes() as $t) if ((int)$t['id'] === (int)$onu['onu_type_id']) $type = $t;
$history = $mgr->signalHistory($id, 7);
$events = $mgr->statusEvents($id, 30);
$notes = $mgr->notes($id);
$tickets = $mgr->tickets(['onu_id' => $id]);
$speeds = $mgr->speedProfiles();
$vlans = array_values(array_filter($olt['profiles']['vlans'] ?? [], function ($v) { return $v['id'] > 1; }));
$disabled = $onu['status_group'] === 'disabled';
$f = ['id' => $id];
$none = '<i>None</i>';
$edit = function (string $href) { return ' <a href="' . e($href) . '" title="Open"><i class="fa fa-external-link"></i></a>'; };
$router = $onu['mode'] === 'router';

$pageTitle = 'View ONU';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo flash_messages(); ?>
<?php if ($runningConfig !== null): ?>
    <?php echo panel_open('Running config on the OLT', '<a href="onu.php?id=' . $id . '"><i class="fa fa-times"></i></a>', 'file-text-o'); ?>
        <pre class="so-pre" id="runCfg"><?php echo e($runningConfig !== '' ? $runningConfig : 'The OLT returned nothing.'); ?></pre>
        <button type="button" class="so-btn so-btn-grey so-btn-sm" data-copy="runCfg"><i class="fa fa-copy"></i> Copy</button>
    <?php echo panel_close(); ?>
<?php endif; ?>
<?php if ($swInfo !== null): ?>
    <?php echo panel_open('SW info', '<a href="onu.php?id=' . $id . '"><i class="fa fa-times"></i></a>', 'microchip'); ?>
        <dl class="so-kvl"><?php foreach ($swInfo as $k => $v): if ($v === '') continue; ?><dt style="text-transform:capitalize"><?php echo e($k); ?></dt><dd><?php echo e($v); ?></dd><?php endforeach; ?></dl>
        <?php if (!$swInfo) echo '<p class="so-muted" style="margin:0">The OLT returned no version information for this ONU.</p>'; ?>
    <?php echo panel_close(); ?>
<?php endif; ?>

<div class="so-half" style="gap:20px">
    <dl class="so-kvl" style="align-self:start;margin-top:6px">
        <dt>OLT<?php echo $edit('olt.php?id=' . (int)$onu['olt_id']); ?></dt><dd><?php echo (int)$onu['olt_id'] . ' - ' . e($onu['olt_name']); ?></dd>
        <dt>Board<?php echo $edit('olt.php?id=' . (int)$onu['olt_id'] . '&tab=cards'); ?></dt><dd><?php echo (int)$onu['slot']; ?></dd>
        <dt>Port<?php echo $edit('onus.php?olt=' . (int)$onu['olt_id'] . '&pon=' . $onu['frame'] . '/' . $onu['slot'] . '/' . $onu['port']); ?></dt>
            <dd><?php echo (int)$onu['port']; ?> <a href="graphs.php?olt=<?php echo (int)$onu['olt_id']; ?>&pon=<?php echo e($onu['frame'] . '/' . $onu['slot'] . '/' . $onu['port']); ?>"><i class="fa fa-line-chart"></i></a></dd>
        <dt>ONU</dt><dd><?php echo e(onu_place($onu)); ?></dd>
        <dt>GPON channel</dt><dd>GPON</dd>
        <dt>SN</dt><dd class="so-mono"><?php echo e($onu['sn']); ?></dd>
        <dt>ONU type<?php echo $edit('catalog.php?k=types'); ?></dt><dd><?php echo $onu['type_name'] !== null ? e($onu['type_name']) : ($onu['model'] !== '' ? e($onu['model']) : $none); ?></dd>
        <dt>Configuration Preset</dt><dd><i class="fa fa-sliders"></i> <?php echo $onu['preset_name'] !== null ? e($onu['preset_name']) : 'None'; ?></dd>
        <dt>Zone<?php echo $edit('catalog.php?k=zones'); ?></dt><dd><?php echo $onu['zone_name'] !== null ? e($onu['zone_name']) : $none; ?></dd>
        <dt>Splitter</dt><dd><?php echo $onu['odb_name'] !== null ? e($onu['odb_name']) . ($onu['odb_port'] ? ', port ' . (int)$onu['odb_port'] : '') : $none; ?></dd>
        <dt>Sector</dt><dd><?php echo $onu['sector_name'] !== null ? e($onu['sector_name']) : $none; ?></dd>
        <dt>Name</dt><dd><?php echo e($onu['description']); ?></dd>
        <dt>Address or comment</dt><dd><?php echo $onu['address'] !== '' ? e($onu['address']) : $none; ?>
            <?php if ($onu['lat'] !== null): ?> <a href="https://www.openstreetmap.org/?mlat=<?php echo e($onu['lat']); ?>&mlon=<?php echo e($onu['lng']); ?>#map=18/<?php echo e($onu['lat'] . '/' . $onu['lng']); ?>" target="_blank" rel="noopener" style="color:var(--red)"><i class="fa fa-map-marker"></i></a>
            <?php else: ?> <i class="fa fa-map-marker" style="color:var(--red)"></i><?php endif; ?></dd>
        <dt>Contact</dt><dd><?php echo $onu['contact'] !== '' ? e($onu['contact']) : $none; ?></dd>
        <dt>Authorization date</dt><dd style="color:var(--text)"><?php echo $onu['authorized_at'] ? e(date('d-M-Y H:i:s', strtotime($onu['authorized_at']))) : 'Before SmartOLT'; ?> <a href="#" data-open="historyModal">History</a></dd>
        <dt>ONU external ID</dt><dd><?php echo $onu['customer_ref'] !== '' ? e($onu['customer_ref']) : e($onu['sn']); ?></dd>
        <dt>VIP customer <i class="fa fa-info-circle" title="VIP ONUs stand out in lists and filters"></i></dt>
        <dd><form method="POST" style="display:inline"><?php echo csrf_field(); ?><input type="hidden" name="do" value="vip"><input type="hidden" name="id" value="<?php echo $id; ?>"><input type="hidden" name="vip" value="<?php echo $onu['vip'] ? '' : '1'; ?>">
            <button class="so-link-btn" style="color:<?php echo $onu['vip'] ? 'var(--orange)' : 'var(--link)'; ?>;font-weight:400;padding:0"><i class="fa fa-star<?php echo $onu['vip'] ? '' : '-o'; ?>"></i> <?php echo $onu['vip'] ? 'VIP' : 'Mark as VIP'; ?></button></form></dd>
        <dt></dt><dd><a href="#" data-open="editDetails" style="font-size:13px"><i class="fa fa-pencil"></i> Update details</a></dd>
    </dl>
    <div>
        <div style="display:flex;justify-content:center;margin-bottom:6px">
            <svg width="300" height="96" viewBox="0 0 300 96" role="img" aria-label="ONU"><rect x="40" y="20" width="250" height="70" rx="8" fill="#eef0f3" stroke="#c9ced6"/><rect x="20" y="4" width="8" height="70" rx="3" fill="#e8eaee" stroke="#c9ced6"/>
                <rect x="62" y="48" width="18" height="16" fill="#2f6fd6"/><rect x="96" y="48" width="18" height="16" fill="#3b3f46"/>
                <?php $ports = max(1, min(4, (int)($type['eth_ports'] ?? 2))); for ($i = 0; $i < $ports; $i++): ?><rect x="<?php echo 124 + $i * 26; ?>" y="46" width="22" height="20" fill="#e3b623" stroke="#9a7a12"/><?php endfor; ?>
                <rect x="240" y="48" width="8" height="16" fill="#3b3f46"/><circle cx="262" cy="56" r="7" fill="#b9bec7" stroke="#6b717b"/></svg>
        </div>
        <dl class="so-kvl" style="grid-template-columns:150px 1fr">
            <dt>Status</dt><dd><div class="so-statusbox" id="statusBox"><?php echo status_icon($onu['status_group']) . ' ' . e(status_label($onu['status_group'])); ?>
                <?php if ($onu['status_group'] !== 'online' && $onu['last_down_cause'] !== '') echo '<span class="so-muted"> (' . e($onu['last_down_cause']) . ')</span>'; ?></div></dd>
            <dt>Rx signal levels</dt><dd><div class="so-rx"><div>ONU Rx 1490nm<b id="rxOnu" class="sig-<?php echo strtolower(signal_level($onu['rx_power'])); ?>"><?php echo $onu['rx_power'] !== null ? number_format((float)$onu['rx_power'], 2) . ' dBm' : '-'; ?></b></div>
                <div>OLT Rx 1310nm<b id="rxOlt"><?php echo $onu['olt_rx_power'] !== null ? number_format((float)$onu['olt_rx_power'], 2) . ' dBm' : '-'; ?></b></div></div></dd>
            <dt style="margin-top:8px">Distance</dt><dd style="margin-top:8px"><?php echo fmt_distance($onu['distance_m']); ?></dd>
            <dt>TR069 Profile</dt><dd>Inactive</dd>
            <dt>Mgmt IP</dt><dd>Inactive</dd>
            <dt>Attached VLANs</dt><dd><?php echo $onu['vlan'] ? (int)$onu['vlan'] : '-'; ?></dd>
            <dt>ONU mode</dt><dd><?php echo $onu['mode'] ? ($router ? 'Routing' : 'Bridging') . ($onu['vlan'] ? ' - WAN vlan: ' . (int)$onu['vlan'] : '') : '-'; ?></dd>
            <dt>WAN setup mode</dt><dd><?php echo $router ? 'Setup via ONU webpage' : 'Bridge to the router behind it'; ?></dd>
        </dl>
    </div>
</div>

<div class="so-row" style="grid-template-columns:170px 1fr;margin-top:18px">
    <label>Status</label>
    <div class="so-inline">
        <button type="button" class="so-btn so-btn-blue" id="getStatus">Get status</button>
        <?php echo post_btn('config', $f, 'Show running-config', 'blue', '', '', 'Reading the ONU configuration from the OLT...'); ?>
        <?php echo post_btn('sw_info', $f, 'SW info', 'blue', '', '', 'Reading the ONU version from the OLT...'); ?>
        <button type="button" class="so-btn so-btn-grey" disabled title="TR069 is not connected to SmartOLT yet">TR-069</button>
        <button type="button" class="so-btn so-btn-green" id="live">LIVE!</button>
        <span class="so-muted" id="liveText" style="font-size:13px"></span>
    </div>
</div>
<div class="so-row" style="grid-template-columns:170px 1fr;align-items:start">
    <label>Traffic/Signal</label>
    <div class="so-half" style="gap:12px">
        <div class="so-graph-empty"><i class="fa fa-picture-o"></i>Traffic graph data is not yet available for this device.</div>
        <div><?php if (count($history) < 2): ?><div class="so-graph-empty"><i class="fa fa-picture-o"></i>Signal graph fills in as the OLT is read every 5 minutes.</div>
            <?php else: ?><div style="border:1px solid var(--border);border-radius:4px;padding:6px;height:170px"><canvas id="signalHistory"></canvas></div><?php endif; ?></div>
    </div>
</div>
<div class="so-row" style="grid-template-columns:170px 1fr;align-items:start">
    <label>Speed profiles</label>
    <table class="so-table" style="font-size:14px"><thead><tr><th>Service</th><th>Service ports</th><th>User-VLAN</th><th>Download</th><th>Upload</th><th>Action</th></tr></thead>
        <tbody><tr><td>Internet</td><td><?php echo $onu['service_ports'] !== null ? (int)$onu['service_ports'] : '-'; ?></td><td><?php echo $onu['vlan'] ? (int)$onu['vlan'] : '-'; ?></td>
            <td><?php echo $onu['speed_name'] !== null ? e(fmt_speed($onu['down_kbps'])) : ($onu['olt_down_kbps'] ? e(fmt_speed($onu['olt_down_kbps'])) : 'Unlimited'); ?></td>
            <td><?php echo $onu['speed_name'] !== null ? e(fmt_speed($onu['up_kbps'])) : ($onu['olt_up_kbps'] ? e(fmt_speed($onu['olt_up_kbps'])) : 'Unlimited'); ?></td>
            <td><a href="#" data-open="editService"><i class="fa fa-cog"></i> Configure</a></td></tr></tbody></table>
</div>
<div class="so-row" style="grid-template-columns:170px 1fr;align-items:start">
    <label>Ethernet ports</label>
    <table class="so-table" style="font-size:14px"><thead><tr><th>Port</th><th>Admin state</th><th>Mode</th><th>DHCP</th></tr></thead>
        <tbody><?php for ($i = 1; $i <= max(1, (int)($type['eth_ports'] ?? 1)); $i++): ?>
            <tr><td>eth_0/<?php echo $i; ?></td><td>Enabled</td><td>LAN</td><td>No control</td></tr><?php endfor; ?></tbody></table>
</div>
<div class="so-row" style="grid-template-columns:170px 1fr">
    <label>CATV</label><div style="font-style:italic;font-size:14px"><?php echo !empty($type['catv']) ? 'Supported, not managed by SmartOLT yet' : 'Not supported by ONU-Type'; ?></div>
</div>
<div class="so-row" style="grid-template-columns:170px 1fr">
    <label></label>
    <div class="so-inline">
        <?php echo post_btn('reboot', $f, 'Reboot', 'orange', 'refresh', 'Reboot this ONU? The customer is offline for about two minutes.', 'Sending the reboot...');
        echo post_btn('resync', $f, 'Resync config', 'yellow', 'refresh', 'Put the saved VLAN, mode and speed on the OLT again? The customer drops for a few seconds.', 'Resyncing the ONU on the OLT...');
        echo post_btn('restore', $f, 'Restore defaults', 'yellow', 'undo', 'Restore this ONU to factory settings? Settings made on the ONU itself (WiFi name, password) are lost.', 'Restoring defaults...');
        echo $disabled ? post_btn('enable', $f, 'Enable ONU', 'green', 'check', 'Enable this ONU?', 'Saving on the OLT...')
            : post_btn('disable', $f, 'Disable ONU', 'yellow', 'ban', 'Disable this ONU? The customer loses internet until you enable it.', 'Saving on the OLT...'); ?>
        <button type="button" class="so-btn so-btn-grey" data-open="replaceSn"><i class="fa fa-exchange"></i> Replace by SN</button>
        <?php echo post_btn('delete', $f, 'Delete', 'red', 'trash', 'Delete this ONU from the OLT with its service ports? The customer loses internet until it is authorized again.', 'Deleting the ONU...'); ?>
    </div>
</div>
<div class="so-row" style="grid-template-columns:170px 1fr;align-items:start">
    <label>Notes &amp; tickets</label>
    <div>
        <div class="so-inline"><button type="button" class="so-btn so-btn-grey so-btn-sm" data-open="noteModal"><i class="fa fa-pencil-square-o"></i> Add a note</button>
            <?php echo btn('New ticket', 'grey', 'tickets.php?add=1&onu=' . $id, 'ticket', 'style="padding:4px 10px;font-size:13px"'); ?></div>
        <?php foreach ($notes as $n): ?>
            <div class="so-box" style="margin:8px 0 0;padding:8px 12px;font-size:14px"><div style="white-space:pre-wrap"><?php echo e($n['note']); ?></div>
                <div class="so-muted" style="font-size:12px;margin-top:4px"><?php echo e(($n['user'] ?: 'Panel') . ', ' . when($n['created_at'])); ?></div></div>
        <?php endforeach; ?>
        <?php foreach ($tickets as $t): ?>
            <div class="so-box" style="margin:8px 0 0;padding:8px 12px;font-size:14px"><i class="fa fa-ticket"></i> <b><?php echo e($t['subject']); ?></b>
                <?php echo badge($t['status'] === 'open' ? 'Open' : 'Closed', $t['status'] === 'open' ? 'orange' : 'grey'); ?>
                <span class="so-muted" style="font-size:12px"><?php echo e(OltManager::TICKET_TYPES[$t['type']] ?? $t['type']); ?>, <?php echo e(when($t['created_at'])); ?></span></div>
        <?php endforeach; ?>
    </div>
</div>

<div class="so-modal" id="historyModal">
    <div class="so-modal-box">
        <div class="so-modal-h">Status history <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b">
            <?php if (!$events): ?><p class="so-muted" style="margin:0">No changes since SmartOLT started watching this ONU.</p><?php else: ?>
            <table class="so-table"><thead><tr><th>When</th><th>Became</th><th>Cause</th></tr></thead><tbody>
                <?php foreach ($events as $ev): ?><tr><td><?php echo e(when($ev['at'])); ?></td><td><?php echo status_badge($ev['state']); ?></td><td><?php echo e($ev['cause'] ?: '-'); ?></td></tr><?php endforeach; ?>
            </tbody></table><?php endif; ?>
        </div>
    </div>
</div>
<div class="so-modal" id="noteModal">
    <form method="POST" class="so-modal-box">
        <div class="so-modal-h">Add a note <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b"><?php echo csrf_field(); ?><input type="hidden" name="do" value="note"><input type="hidden" name="id" value="<?php echo $id; ?>">
            <textarea name="note" class="so-textarea" required maxlength="5000" placeholder="For example: customer asked for a visit on Monday"></textarea></div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button class="so-btn so-btn-green">Save</button></div>
    </form>
</div>
<div class="so-modal" id="editDetails">
    <form method="POST" class="so-modal-box wide" data-busy="Saving...">
        <div class="so-modal-h">Update ONU details <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b so-grid2">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="rename"><input type="hidden" name="id" value="<?php echo $id; ?>">
            <div class="so-field"><label class="so-label">Name</label><input name="description" maxlength="60" required class="so-input" value="<?php echo e($onu['description']); ?>"><div class="so-help">Also changed on the OLT.</div></div>
            <div class="so-field"><label class="so-label">ONU external ID</label><input name="customer_ref" maxlength="100" class="so-input" value="<?php echo e($onu['customer_ref']); ?>"></div>
            <div class="so-field"><label class="so-label">ONU type</label><select name="onu_type_id" class="so-select"><?php echo options($mgr->onuTypes(), $onu['onu_type_id'] ?? '', 'Not set'); ?></select></div>
            <div class="so-field"><label class="so-label">Contact</label><input name="contact" maxlength="100" class="so-input" value="<?php echo e($onu['contact']); ?>" placeholder="Phone or name"></div>
            <div class="so-field"><label class="so-label">Zone</label><select name="zone_id" class="so-select"><?php echo options($mgr->zones(), $onu['zone_id'] ?? '', 'None'); ?></select></div>
            <div class="so-field"><label class="so-label">Sector</label><select name="sector_id" class="so-select"><?php echo options($mgr->sectors(), $onu['sector_id'] ?? '', 'None'); ?></select></div>
            <div class="so-field"><label class="so-label">Splitter</label><select name="odb_id" class="so-select"><?php echo options($mgr->odbs(), $onu['odb_id'] ?? '', 'None'); ?></select></div>
            <div class="so-field"><label class="so-label">Splitter port</label><input name="odb_port" type="number" min="1" max="64" class="so-input" value="<?php echo e($onu['odb_port'] ?? ''); ?>"></div>
            <div class="so-field"><label class="so-label">Address or comment</label><input name="address" maxlength="255" class="so-input" value="<?php echo e($onu['address']); ?>"></div>
            <div class="so-field"><label class="so-label">GPS</label><div class="so-inline"><input name="lat" class="so-input" style="width:120px" value="<?php echo e($onu['lat'] ?? ''); ?>" placeholder="Latitude">
                <input name="lng" class="so-input" style="width:120px" value="<?php echo e($onu['lng'] ?? ''); ?>" placeholder="Longitude">
                <button type="button" id="useHere" class="so-btn so-btn-green so-btn-sm">Get current location</button></div></div>
        </div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button class="so-btn so-btn-green"><i class="fa fa-floppy-o"></i> Save</button></div>
    </form>
</div>
<div class="so-modal" id="editService">
    <form method="POST" class="so-modal-box" data-busy="Changing the service on the OLT..." data-confirm="Change the service? The customer drops for a few seconds.">
        <div class="so-modal-h">Configure speed profile <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="service"><input type="hidden" name="id" value="<?php echo $id; ?>">
            <div class="so-field"><label class="so-label">ONU mode</label><select name="mode" class="so-select"><option value="router">Routing</option><option value="bridge" <?php echo !$router ? 'selected' : ''; ?>>Bridging</option></select></div>
            <div class="so-field"><label class="so-label">User VLAN-ID</label><input name="vlan" type="number" min="1" max="4094" required list="vlans" class="so-input" value="<?php echo $onu['vlan'] ? (int)$onu['vlan'] : ''; ?>">
                <datalist id="vlans"><?php foreach ($vlans as $v): ?><option value="<?php echo (int)$v['id']; ?>"><?php endforeach; ?></datalist></div>
            <div class="so-field"><label class="so-label">Speed profile</label><select name="speed_profile_id" class="so-select"><?php echo options($speeds, $onu['speed_profile_id'] ?? '', 'Unlimited'); ?></select></div>
        </div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button class="so-btn so-btn-blue">Apply on OLT</button></div>
    </form>
</div>
<div class="so-modal" id="replaceSn">
    <form method="POST" class="so-modal-box" data-busy="Changing the serial on the OLT..." data-confirm="Put the new serial in place of the old one? The old ONU stops working.">
        <div class="so-modal-h">Replace by SN <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="replace"><input type="hidden" name="id" value="<?php echo $id; ?>">
            <p class="so-muted" style="margin-top:0">Same customer, new device: the new ONU takes this one's place, profiles, VLAN and speed.</p>
            <label class="so-label">New SN</label><input name="new_sn" required maxlength="20" class="so-input so-mono" placeholder="For example HWTC1A2B3C4D">
        </div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button class="so-btn so-btn-blue">Replace</button></div>
    </form>
</div>
<script>
(function () {
    var id = <?php echo $id; ?>, box = document.getElementById('statusBox'), liveTimer = null;
    var icons = { online: 'globe st-online', power_fail: 'plug st-power_fail', los: 'chain-broken st-los', other: 'globe st-other', disabled: 'ban st-disabled' };
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
    function read() {
        box.innerHTML = '<i class="fa fa-refresh fa-spin"></i>';
        fetch('onu.php?id=' + id + '&ajax=status', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) { box.innerHTML = '<span style="color:var(--red)">' + esc(d.error) + '</span>'; return; }
            box.innerHTML = '<i class="fa fa-' + (icons[d.group] || 'question-circle') + ' fa-lg"></i> ' + esc(d.status) + (d.cause ? '<span class="so-muted"> (' + esc(d.cause) + ')</span>' : '');
            var rx = document.getElementById('rxOnu');
            rx.textContent = d.rx !== null ? d.rx + ' dBm' : '-';
            rx.className = d.level ? 'sig-' + d.level : '';
            document.getElementById('rxOlt').textContent = d.olt_rx !== null ? d.olt_rx + ' dBm' : '-';
        }).catch(function () { box.textContent = 'Could not read the ONU.'; });
    }
    read();
    document.getElementById('getStatus').addEventListener('click', read);
    document.getElementById('live').addEventListener('click', function () {
        if (liveTimer) { clearInterval(liveTimer); liveTimer = null; this.textContent = 'LIVE!'; document.getElementById('liveText').textContent = ''; return; }
        liveTimer = setInterval(read, 15000);
        this.textContent = 'Stop live';
        document.getElementById('liveText').textContent = 'Reading the ONU every 15 seconds';
    });
    document.getElementById('useHere').addEventListener('click', function () {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function (p) {
            document.querySelector('#editDetails input[name=lat]').value = p.coords.latitude.toFixed(7);
            document.querySelector('#editDetails input[name=lng]').value = p.coords.longitude.toFixed(7);
        });
    });
    var el = document.getElementById('signalHistory');
    if (!el) return;
    var points = <?php echo json_encode(array_map(function ($h) { return ['t' => date('j M H:i', strtotime($h['at'])), 'rx' => $h['rx_power'] === null ? null : (float)$h['rx_power'], 'olt' => $h['olt_rx_power'] === null ? null : (float)$h['olt_rx_power']]; }, $history)); ?>;
    var chart;
    function build() {
        if (chart) chart.destroy();
        chart = new Chart(el.getContext('2d'), { type: 'line',
            data: { labels: points.map(function (p) { return p.t; }), datasets: [
                { label: 'ONU Rx', data: points.map(function (p) { return p.rx; }), borderColor: '#2ecc40', pointRadius: 0, tension: 0.2, spanGaps: true },
                { label: 'OLT Rx', data: points.map(function (p) { return p.olt; }), borderColor: '#4e8cff', pointRadius: 0, tension: 0.2, spanGaps: true }] },
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { color: soChart.tick(), boxWidth: 10 } } },
                scales: { x: { ticks: { color: soChart.tick(), maxTicksLimit: 6 }, grid: { display: false } }, y: { ticks: { color: soChart.tick() }, grid: { color: soChart.grid() } } } } });
    }
    build();
    window.addEventListener('themechange', build);
})();
</script>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
