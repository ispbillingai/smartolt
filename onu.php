<?php
require_once __DIR__ . '/backend/bootstrap.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$runningConfig = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $here = "onu.php?id={$id}";
    try {
        switch ($_POST['do'] ?? '') {
            case 'rename':
                $desc = $mgr->describe($id, (string)($_POST['description'] ?? ''), (string)($_POST['customer_ref'] ?? ''));
                $mgr->saveMeta($id, $_POST);
                back($here . '&live=0', 'success', "Saved as {$desc}.");
            case 'details':
                $mgr->saveMeta($id, $_POST);
                back($here . '&live=0', 'success', 'Details saved.');
            case 'service':
                $mgr->setService($id, $_POST);
                back($here, 'success', 'Service changed on the OLT.');
            case 'resync':
                $mgr->resync($id);
                back($here, 'success', 'The saved VLAN, mode and speed were put on the OLT again.');
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
            case 'config':
                $runningConfig = $mgr->runningConfig($id);
                break;
        }
    } catch (Throwable $e) {
        back($here . '&live=0', 'error', $e->getMessage());
    }
}

if (!$mgr->onu($id)) {
    back('onus.php', 'error', 'ONU not found.');
}
// Read live unless we just saved something kept only here, or showed the running config.
$liveError = '';
$detail = [];
if (($_GET['live'] ?? '1') !== '0' && $runningConfig === null) {
    try {
        [$onu, $detail] = $mgr->liveOnu($id);
    } catch (Throwable $e) {
        $liveError = $e->getMessage();
    }
}
$onu = $mgr->onu($id);
$olt = OltManager::publicOlt($mgr->olt((int)$onu['olt_id']));
$history = $mgr->signalHistory($id, 7);
$events = $mgr->statusEvents($id, 30);
$speeds = $mgr->speedProfiles();
$vlans = array_values(array_filter($olt['profiles']['vlans'] ?? [], function ($v) { return $v['id'] > 1; }));
$disabled = $onu['status_group'] === 'disabled';
$sectorName = '';
foreach ($mgr->sectors() as $s) if ((int)$s['id'] === (int)$onu['sector_id']) $sectorName = $s['name'];
$f = ['id' => $id];

$pageTitle = $onu['description'];
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title($onu['description'], (int)$onu['olt_id'] . ' - ' . $onu['olt_name'] . ', ' . onu_place($onu),
    btn('Get status', 'green', 'onu.php?id=' . $id, 'refresh', 'onclick="document.getElementById(\'soBusy\').classList.add(\'show\')"')
    . post_btn('config', $f, 'Show running-config', 'blue', 'file-text-o', '', 'Reading the ONU configuration from the OLT...')
    . btn('Back', 'grey', 'onus.php')); ?>
<?php echo flash_messages(); ?>
<?php if ($liveError !== '') echo alert('Could not read the ONU live, showing the last saved values. ' . $liveError, 'warn'); ?>
<?php if ($runningConfig !== null): ?>
    <?php echo panel_open('Running config on the OLT', '<a href="onu.php?id=' . $id . '&live=0"><i class="fa fa-times"></i></a>', 'file-text-o'); ?>
        <pre class="so-pre" id="runCfg"><?php echo e($runningConfig !== '' ? $runningConfig : 'The OLT returned nothing.'); ?></pre>
        <button type="button" class="so-btn so-btn-grey so-btn-sm" data-copy="runCfg"><i class="fa fa-copy"></i> Copy</button>
    <?php echo panel_close(); ?>
<?php endif; ?>

<div class="so-half">
    <div>
        <?php echo panel_open('ONU details', '<a href="#" data-open="editDetails"><i class="fa fa-pencil"></i> Update</a>', 'info-circle'); ?>
            <dl class="so-dl">
                <dt>OLT</dt><dd><a href="olt.php?id=<?php echo (int)$onu['olt_id']; ?>"><?php echo (int)$onu['olt_id'] . ' - ' . e($onu['olt_name']); ?></a></dd>
                <dt>Board / Port / ONU</dt><dd><?php echo (int)$onu['slot'] . ' / ' . (int)$onu['port'] . ' / ' . (int)$onu['ont_id']; ?></dd>
                <dt>GPON channel</dt><dd>GPON</dd>
                <dt>SN</dt><dd class="so-mono"><?php echo e($onu['sn']); ?></dd>
                <dt>ONU type</dt><dd><?php echo e($onu['type_name'] ?? '-'); ?><?php echo $onu['model'] !== '' ? ' <span class="so-muted">(OLT reports ' . e($onu['model']) . ')</span>' : ''; ?></dd>
                <dt>Zone</dt><dd><?php echo e($onu['zone_name'] ?? '-'); ?></dd>
                <dt>Sector</dt><dd><?php echo e($sectorName ?: '-'); ?></dd>
                <dt>ODB (Splitter)</dt><dd><?php echo e(($onu['odb_name'] ?? '-') . ($onu['odb_port'] ? ', port ' . $onu['odb_port'] : '')); ?></dd>
                <dt>Name</dt><dd><?php echo e($onu['description']); ?></dd>
                <dt>Address or comment</dt><dd><?php echo e($onu['address'] ?: '-'); ?></dd>
                <dt>GPS</dt><dd><?php echo $onu['lat'] !== null ? '<a href="https://www.openstreetmap.org/?mlat=' . e($onu['lat']) . '&mlon=' . e($onu['lng']) . '#map=18/' . e($onu['lat']) . '/' . e($onu['lng']) . '" target="_blank" rel="noopener">' . e($onu['lat'] . ', ' . $onu['lng']) . '</a>' : '-'; ?></dd>
                <dt>Authorization date</dt><dd><?php echo $onu['authorized_at'] ? e(when($onu['authorized_at'])) : 'Before SmartOLT'; ?></dd>
                <dt>ONU external ID</dt><dd><?php echo e($onu['customer_ref'] ?: '-'); ?></dd>
            </dl>
        <?php echo panel_close(); ?>
    </div>
    <div>
        <?php echo panel_open('Status', '', 'heartbeat'); ?>
            <dl class="so-dl">
                <dt>Status</dt><dd><?php echo status_icon($onu['status_group']) . ' ' . status_badge($onu['status_group'], $onu['run_state']); ?>
                    <?php if ($onu['status_group'] !== 'online' && $onu['last_down_cause'] !== '') echo ' <span class="so-muted">' . e($onu['last_down_cause']) . '</span>'; ?></dd>
                <dt>ONU / OLT Rx signal</dt><dd><?php echo signal_html($onu['rx_power']); ?> <span class="so-muted"> / <?php echo $onu['olt_rx_power'] !== null ? number_format((float)$onu['olt_rx_power'], 2) . ' dBm' : '-'; ?></span></dd>
                <dt>Distance</dt><dd><?php echo fmt_distance($onu['distance_m']); ?></dd>
                <dt>Temperature</dt><dd><?php echo $onu['temperature'] === null ? '-' : (int)$onu['temperature'] . ' °C'; ?></dd>
                <dt>Last up</dt><dd><?php echo $onu['last_up_at'] ? e(when($onu['last_up_at'])) : '-'; ?></dd>
                <dt>Last down</dt><dd><?php echo $onu['last_down_at'] ? e(when($onu['last_down_at'])) : '-'; ?></dd>
            </dl>
        <?php echo panel_close(); ?>
        <?php echo panel_open('Service', '<a href="#" data-open="editService"><i class="fa fa-pencil"></i> Update</a>', 'exchange'); ?>
            <dl class="so-dl">
                <dt>Mode</dt><dd><?php echo $onu['mode'] ? badge($onu['mode'] === 'router' ? 'Router' : 'Bridge', 'blue') : '-'; ?></dd>
                <dt>User VLAN-ID</dt><dd><?php echo $onu['vlan'] ? (int)$onu['vlan'] : '-'; ?></dd>
                <dt>Speed profile</dt><dd><?php echo $onu['speed_name'] !== null ? e($onu['speed_name']) . ' <span class="so-muted">(' . fmt_speed($onu['down_kbps']) . ' down, ' . fmt_speed($onu['up_kbps']) . ' up)</span>' : 'No speed limit'; ?></dd>
            </dl>
        <?php echo panel_close(); ?>
        <div class="so-inline" style="margin-bottom:18px">
            <?php echo post_btn('reboot', $f, 'Reboot', 'orange', 'power-off', 'Reboot this ONU? The customer is offline for about two minutes.', 'Sending the reboot...');
            echo post_btn('resync', $f, 'Resync config', 'blue', 'refresh', 'Put the saved VLAN, mode and speed on the OLT again? The customer drops for a few seconds.', 'Resyncing the ONU on the OLT...');
            echo $disabled ? post_btn('enable', $f, 'Enable ONU', 'green', 'check', 'Enable this ONU?', 'Saving on the OLT...')
                : post_btn('disable', $f, 'Disable ONU', 'grey', 'ban', 'Disable this ONU? The customer loses internet until you enable it.', 'Saving on the OLT...'); ?>
            <button type="button" class="so-btn so-btn-grey" data-open="replaceSn"><i class="fa fa-exchange"></i> Replace by SN</button>
            <?php echo post_btn('delete', $f, 'Delete', 'red', 'trash', 'Delete this ONU from the OLT with its service ports? The customer loses internet until it is authorized again.', 'Deleting the ONU...'); ?>
        </div>
    </div>
</div>

<?php echo panel_open('Signal, last 7 days', '', 'signal'); ?>
    <?php if (count($history) < 2): ?><p class="so-muted" style="text-align:center">The graph fills in as SmartOLT reads the OLT every 5 minutes.</p>
    <?php else: ?><canvas id="signalHistory" height="90"></canvas><?php endif; ?>
<?php echo panel_close(); ?>

<div class="so-half">
    <div><?php echo panel_open('Status history', '', 'history'); ?>
        <?php if (!$events): ?><p class="so-muted" style="margin:0">No changes since SmartOLT started watching this ONU.</p><?php else: ?>
        <table class="so-table"><thead><tr><th>When</th><th>Became</th><th>Cause</th></tr></thead><tbody>
            <?php foreach ($events as $ev): ?><tr><td><?php echo e(when($ev['at'])); ?></td><td><?php echo status_badge($ev['state']); ?></td><td><?php echo e($ev['cause'] ?: '-'); ?></td></tr><?php endforeach; ?>
        </tbody></table><?php endif; ?>
    <?php echo panel_close(); ?></div>
    <div><?php echo panel_open('What the OLT reports', '', 'terminal'); ?>
        <?php if (!$detail): ?><p class="so-muted" style="margin:0">No live details. Press Get status.</p><?php else: ?>
        <dl class="so-dl"><?php foreach ($detail as $k => $v): if ($v === '') continue; ?><dt style="text-transform:capitalize"><?php echo e($k); ?></dt><dd><?php echo e($v); ?></dd><?php endforeach; ?></dl>
        <?php endif; ?>
    <?php echo panel_close(); ?></div>
</div>

<div class="so-modal" id="editDetails">
    <form method="POST" class="so-modal-box wide" data-busy="Saving...">
        <div class="so-modal-h">Update ONU details <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b so-grid2">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="rename"><input type="hidden" name="id" value="<?php echo $id; ?>">
            <div class="so-field"><label class="so-label">Name</label><input name="description" maxlength="60" required class="so-input" value="<?php echo e($onu['description']); ?>"><div class="so-help">Also changed on the OLT.</div></div>
            <div class="so-field"><label class="so-label">ONU external ID</label><input name="customer_ref" maxlength="100" class="so-input" value="<?php echo e($onu['customer_ref']); ?>"></div>
            <div class="so-field"><label class="so-label">ONU type</label><select name="onu_type_id" class="so-select"><?php echo options($mgr->onuTypes(), $onu['onu_type_id'] ?? '', 'Not set'); ?></select></div>
            <div class="so-field"><label class="so-label">Zone</label><select name="zone_id" class="so-select"><?php echo options($mgr->zones(), $onu['zone_id'] ?? '', 'None'); ?></select></div>
            <div class="so-field"><label class="so-label">Sector</label><select name="sector_id" class="so-select"><?php echo options($mgr->sectors(), $onu['sector_id'] ?? '', 'None'); ?></select></div>
            <div class="so-field"><label class="so-label">ODB (Splitter)</label><select name="odb_id" class="so-select"><?php echo options($mgr->odbs(), $onu['odb_id'] ?? '', 'None'); ?></select></div>
            <div class="so-field"><label class="so-label">ODB port</label><input name="odb_port" type="number" min="1" max="64" class="so-input" value="<?php echo e($onu['odb_port'] ?? ''); ?>"></div>
            <div class="so-field"><label class="so-label">Address or comment</label><input name="address" maxlength="255" class="so-input" value="<?php echo e($onu['address']); ?>"></div>
            <div class="so-field"><label class="so-label">Latitude</label><input name="lat" class="so-input" value="<?php echo e($onu['lat'] ?? ''); ?>" placeholder="-1.2921"></div>
            <div class="so-field"><label class="so-label">Longitude</label><input name="lng" class="so-input" value="<?php echo e($onu['lng'] ?? ''); ?>" placeholder="36.8219">
                <button type="button" id="useHere" class="so-btn so-btn-grey so-btn-sm" style="margin-top:6px"><i class="fa fa-map-marker"></i> Use GPS</button></div>
        </div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button class="so-btn so-btn-blue">Save</button></div>
    </form>
</div>
<div class="so-modal" id="editService">
    <form method="POST" class="so-modal-box" data-busy="Changing the service on the OLT..." data-confirm="Change the service? The customer drops for a few seconds.">
        <div class="so-modal-h">Update service <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="service"><input type="hidden" name="id" value="<?php echo $id; ?>">
            <div class="so-field"><label class="so-label">ONU mode</label><select name="mode" class="so-select"><option value="bridge">Bridging</option><option value="router" <?php echo $onu['mode'] === 'router' ? 'selected' : ''; ?>>Routing</option></select></div>
            <div class="so-field"><label class="so-label">User VLAN-ID</label><input name="vlan" type="number" min="1" max="4094" required list="vlans" class="so-input" value="<?php echo $onu['vlan'] ? (int)$onu['vlan'] : ''; ?>">
                <datalist id="vlans"><?php foreach ($vlans as $v): ?><option value="<?php echo (int)$v['id']; ?>"><?php endforeach; ?></datalist></div>
            <div class="so-field"><label class="so-label">Speed profile</label><select name="speed_profile_id" class="so-select"><?php echo options($speeds, $onu['speed_profile_id'] ?? '', 'No speed limit'); ?></select></div>
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
                { label: 'ONU Rx (dBm)', data: points.map(function (p) { return p.rx; }), borderColor: '#2ecc40', pointRadius: 0, tension: 0.2, spanGaps: true },
                { label: 'OLT Rx (dBm)', data: points.map(function (p) { return p.olt; }), borderColor: '#4e8cff', pointRadius: 0, tension: 0.2, spanGaps: true }] },
            options: { responsive: true, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { color: soChart.tick() } } },
                scales: { x: { ticks: { color: soChart.tick(), maxTicksLimit: 8 }, grid: { display: false } }, y: { ticks: { color: soChart.tick() }, grid: { color: soChart.grid() } } } } });
    }
    build();
    window.addEventListener('themechange', build);
})();
</script>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
