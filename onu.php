<?php
require_once __DIR__ . '/backend/bootstrap.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $here = "onu.php?id={$id}";
    try {
        switch ($_POST['do'] ?? '') {
            case 'rename':
                $desc = $mgr->describe($id, (string)($_POST['description'] ?? ''), (string)($_POST['customer_ref'] ?? ''));
                back($here, 'success', "Saved as {$desc}.");
            case 'details':
                $mgr->saveMeta($id, $_POST);
                back($here . '&live=0', 'success', 'Details saved.');
            case 'service':
                $mgr->setService($id, $_POST);
                back($here, 'success', 'Service changed on the OLT.');
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
        }
    } catch (Throwable $e) {
        back($here . '&live=0', 'error', $e->getMessage());
    }
}

if (!$mgr->onu($id)) {
    back('onus.php', 'error', 'ONU not found.');
}
// Read live unless we just came back from saving something stored only here.
$liveError = '';
$detail = [];
if (($_GET['live'] ?? '1') !== '0') {
    try {
        [$onu, $detail] = $mgr->liveOnu($id);
    } catch (Throwable $e) {
        $liveError = $e->getMessage();
    }
}
$onu = $mgr->onu($id) + ($onu ?? []);
$olt = OltManager::publicOlt($mgr->olt((int)$onu['olt_id']));
$history = $mgr->signalHistory($id, 7);
$events = $mgr->statusEvents($id, 30);
$zones = $mgr->zones();
$odbs = $mgr->odbs();
$types = $mgr->onuTypes();
$speeds = $mgr->speedProfiles();
$vlans = array_values(array_filter($olt['profiles']['vlans'] ?? [], function ($v) { return $v['id'] > 1; }));
$disabled = $onu['status_group'] === 'disabled';

$pageTitle = 'ONU ' . $onu['sn'];
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
$num = function ($v, $unit) { return $v === null ? '--' : number_format((float)$v, 2) . ' ' . $unit; };
$row = function (string $k, string $v) { return '<div class="py-2 flex justify-between gap-4"><dt class="text-slate-500 dark:text-slate-400">' . e($k) . '</dt><dd class="text-slate-900 dark:text-slate-100 text-right">' . $v . '</dd></div>'; };
?>
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading($onu['description'], $onu['olt_name'] . ' · PON port ' . $onu['frame'] . '/' . $onu['slot'] . '/' . $onu['port'] . ' · ONU ID ' . $onu['ont_id'],
            '<div class="flex gap-2"><a href="onu.php?id=' . $id . '" class="' . UI_BTN_GHOST . '">Read Again</a><a href="onus.php" class="' . UI_BTN_GHOST . '">Back to ONUs</a></div>'); ?>
        <?php echo flash_messages(); ?>
        <?php if ($liveError !== ''): ?>
            <div class="mb-4 p-3 border rounded-lg text-sm bg-amber-50 dark:bg-amber-900/30 border-amber-200 dark:border-amber-800/50 text-amber-800 dark:text-amber-300">Could not read the ONU live, showing the last saved values. <?php echo e($liveError); ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <?php
            $tone = ['online' => 'emerald', 'power_fail' => 'amber', 'disabled' => 'slate'][$onu['status_group']] ?? 'rose';
            echo stat_card('Status now', status_label($onu['status_group']), $tone, 'M13 10V3L4 14h7v7l9-11h-7z');
            echo stat_card('ONU receives', $num($onu['rx_power'], 'dBm'), 'brand', 'M19 14l-7 7m0 0l-7-7m7 7V3');
            echo stat_card('OLT receives', $num($onu['olt_rx_power'], 'dBm'), 'violet', 'M5 10l7-7m0 0l7 7m-7-7v18');
            echo stat_card('Distance', fmt_distance($onu['distance_m']), 'amber', 'M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z');
            ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
            <div class="lg:col-span-2">
                <?php echo panel_open('ONU'); ?>
                    <dl class="text-sm divide-y divide-slate-200 dark:divide-slate-700 mb-5">
                        <?php
                        echo $row('Serial Number', '<span class="font-mono">' . e($onu['sn']) . '</span>');
                        echo $row('Model', e($onu['type_name'] ?? ($onu['model'] ?: '--')));
                        echo $row('Signal', signal_html($onu['rx_power']) . ($onu['rx_power'] !== null ? ' <span class="text-xs text-slate-400">' . signal_level($onu['rx_power']) . '</span>' : ''));
                        echo $row('Temperature', $onu['temperature'] === null ? '--' : (int)$onu['temperature'] . ' °C');
                        if ($onu['status_group'] !== 'online') echo $row('Down cause', e($onu['last_down_cause'] !== '' ? $onu['last_down_cause'] : '--'));
                        echo $row('Last up', $onu['last_up_at'] ? e(date('j M Y H:i', strtotime($onu['last_up_at']))) : '--');
                        echo $row('Last down', $onu['last_down_at'] ? e(date('j M Y H:i', strtotime($onu['last_down_at']))) : '--');
                        echo $row('Authorized', $onu['authorized_at'] ? e(date('j M Y H:i', strtotime($onu['authorized_at']))) : '--');
                        ?>
                    </dl>
                    <form method="POST" class="space-y-3" data-busy="Saving on the OLT...">
                        <?php echo csrf_field(); ?><input type="hidden" name="do" value="rename"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <div><label class="<?php echo UI_LABEL; ?>">Name on the OLT</label><input name="description" maxlength="60" class="<?php echo UI_INPUT; ?>" value="<?php echo e($onu['description']); ?>" required></div>
                        <div><label class="<?php echo UI_LABEL; ?>">Customer</label><input name="customer_ref" maxlength="100" class="<?php echo UI_INPUT; ?>" value="<?php echo e($onu['customer_ref']); ?>" placeholder="Customer username"></div>
                        <button class="<?php echo UI_BTN_PRIMARY; ?>">Save</button>
                    </form>
                <?php echo panel_close(); ?>

                <?php echo panel_open('Service'); ?>
                    <form method="POST" class="space-y-3" data-busy="Changing the service on the OLT..." onsubmit="return confirm('Change the service? The customer drops for a few seconds.');">
                        <?php echo csrf_field(); ?><input type="hidden" name="do" value="service"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="<?php echo UI_LABEL; ?>">Internet VLAN</label><input name="vlan" type="number" min="1" max="4094" required list="vlans" class="<?php echo UI_INPUT; ?>" value="<?php echo $onu['vlan'] ? (int)$onu['vlan'] : ''; ?>"></div>
                            <div><label class="<?php echo UI_LABEL; ?>">Mode</label><select name="mode" class="<?php echo UI_INPUT; ?>">
                                <option value="bridge">Bridge</option><option value="router" <?php echo $onu['mode'] === 'router' ? 'selected' : ''; ?>>Router</option></select></div>
                        </div>
                        <div><label class="<?php echo UI_LABEL; ?>">Speed</label><select name="speed_profile_id" class="<?php echo UI_INPUT; ?>"><?php echo options($speeds, $onu['speed_profile_id'] ?? '', 'No speed limit'); ?></select></div>
                        <button class="<?php echo UI_BTN_PRIMARY; ?>">Apply on OLT</button>
                    </form>
                    <datalist id="vlans"><?php foreach ($vlans as $v): ?><option value="<?php echo (int)$v['id']; ?>"><?php endforeach; ?></datalist>
                <?php echo panel_close(); ?>

                <?php echo panel_open('Actions'); ?>
                    <div class="flex flex-wrap gap-2 mb-5">
                        <form method="POST" class="inline" data-busy="Sending the reboot..." onsubmit="return confirm('Reboot this ONU? The customer is offline for about two minutes.');">
                            <?php echo csrf_field(); ?><input type="hidden" name="do" value="reboot"><input type="hidden" name="id" value="<?php echo $id; ?>">
                            <button class="<?php echo UI_BTN_AMBER; ?>">Reboot</button>
                        </form>
                        <form method="POST" class="inline" data-busy="Saving on the OLT..." onsubmit="return confirm('<?php echo $disabled ? 'Enable this ONU?' : 'Disable this ONU? The customer loses internet until you enable it.'; ?>');">
                            <?php echo csrf_field(); ?><input type="hidden" name="do" value="<?php echo $disabled ? 'enable' : 'disable'; ?>"><input type="hidden" name="id" value="<?php echo $id; ?>">
                            <button class="<?php echo $disabled ? UI_BTN_GREEN : UI_BTN_GHOST; ?>"><?php echo $disabled ? 'Enable' : 'Disable'; ?></button>
                        </form>
                        <form method="POST" class="inline" data-busy="Deleting the ONU..." onsubmit="return confirm('Delete this ONU from the OLT with its service ports? The customer loses internet until it is authorized again.');">
                            <?php echo csrf_field(); ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?php echo $id; ?>">
                            <button class="<?php echo UI_BTN_RED; ?>">Delete</button>
                        </form>
                    </div>
                    <form method="POST" class="space-y-3 pt-4 border-t border-slate-200 dark:border-slate-700" data-busy="Changing the serial on the OLT..." onsubmit="return confirm('Put the new serial in place of the old one? The old ONU stops working.');">
                        <?php echo csrf_field(); ?><input type="hidden" name="do" value="replace"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <label class="<?php echo UI_LABEL; ?>">Replace the ONU <span class="font-normal text-slate-400">(same customer, new device)</span></label>
                        <div class="flex gap-2"><input name="new_sn" required maxlength="20" class="<?php echo UI_INPUT; ?> font-mono" placeholder="New serial, for example HWTC1A2B3C4D">
                            <button class="<?php echo UI_BTN_GHOST; ?>">Replace</button></div>
                    </form>
                <?php echo panel_close(); ?>
            </div>

            <div class="lg:col-span-3">
                <?php echo panel_open('Signal, last 7 days'); ?>
                    <?php if (count($history) < 2): ?>
                        <p class="py-6 text-center text-sm text-slate-400">The graph fills in as SmartOLT reads the OLT every 5 minutes.</p>
                    <?php else: ?>
                        <canvas id="signalHistory" height="140"></canvas>
                    <?php endif; ?>
                <?php echo panel_close(); ?>

                <?php echo panel_open('Location and details'); ?>
                    <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <?php echo csrf_field(); ?><input type="hidden" name="do" value="details"><input type="hidden" name="id" value="<?php echo $id; ?>">
                        <div><label class="<?php echo UI_LABEL; ?>">Zone</label><select name="zone_id" class="<?php echo UI_INPUT; ?>"><?php echo options($zones, $onu['zone_id'] ?? '', 'No zone'); ?></select></div>
                        <div><label class="<?php echo UI_LABEL; ?>">ONU Type</label><select name="onu_type_id" class="<?php echo UI_INPUT; ?>"><?php echo options($types, $onu['onu_type_id'] ?? '', 'Not set'); ?></select></div>
                        <div><label class="<?php echo UI_LABEL; ?>">ODB</label><select name="odb_id" class="<?php echo UI_INPUT; ?>"><?php echo options($odbs, $onu['odb_id'] ?? '', 'No ODB'); ?></select></div>
                        <div><label class="<?php echo UI_LABEL; ?>">ODB Port</label><input name="odb_port" type="number" min="1" max="64" class="<?php echo UI_INPUT; ?>" value="<?php echo e($onu['odb_port'] ?? ''); ?>"></div>
                        <div class="md:col-span-2"><label class="<?php echo UI_LABEL; ?>">Address</label><input name="address" maxlength="255" class="<?php echo UI_INPUT; ?>" value="<?php echo e($onu['address']); ?>" placeholder="House, building or landmark"></div>
                        <div><label class="<?php echo UI_LABEL; ?>">Latitude</label><input name="lat" class="<?php echo UI_INPUT; ?>" value="<?php echo e($onu['lat'] ?? ''); ?>" placeholder="-1.2921"></div>
                        <div><label class="<?php echo UI_LABEL; ?>">Longitude</label><input name="lng" class="<?php echo UI_INPUT; ?>" value="<?php echo e($onu['lng'] ?? ''); ?>" placeholder="36.8219"></div>
                        <div class="md:col-span-2 flex gap-2"><button class="<?php echo UI_BTN_PRIMARY; ?>">Save Details</button>
                            <button type="button" id="useHere" class="<?php echo UI_BTN_GHOST; ?>">Use my location</button></div>
                    </form>
                <?php echo panel_close(); ?>

                <?php echo panel_open('Status history'); ?>
                    <?php if (!$events): ?>
                        <p class="py-6 text-center text-sm text-slate-400">No changes since SmartOLT started watching this ONU.</p>
                    <?php else: ?>
                    <table class="w-full text-sm text-left">
                        <thead class="<?php echo UI_THEAD; ?>"><tr><th class="<?php echo UI_TH; ?>">When</th><th class="<?php echo UI_TH; ?>">Became</th><th class="<?php echo UI_TH; ?>">Cause</th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <?php foreach ($events as $ev): ?>
                            <tr><td class="<?php echo UI_TD; ?> whitespace-nowrap text-slate-600 dark:text-slate-300"><?php echo e(date('j M H:i', strtotime($ev['at']))); ?></td>
                                <td class="<?php echo UI_TD; ?>"><?php echo status_badge($ev['state']); ?></td>
                                <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo $ev['cause'] !== '' ? e($ev['cause']) : '--'; ?></td></tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                <?php echo panel_close(); ?>

                <?php echo panel_open('What the OLT reports'); ?>
                    <?php if (!$detail): ?>
                        <p class="py-6 text-center text-sm text-slate-400">No live details. Press Read Again.</p>
                    <?php else: ?>
                    <dl class="text-sm divide-y divide-slate-200 dark:divide-slate-700">
                        <?php foreach ($detail as $k => $v): if ($v === '') continue; ?>
                            <div class="py-2 grid grid-cols-5 gap-4"><dt class="col-span-2 text-slate-500 dark:text-slate-400 capitalize"><?php echo e($k); ?></dt><dd class="col-span-3 text-slate-900 dark:text-slate-100"><?php echo e($v); ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                    <?php endif; ?>
                <?php echo panel_close(); ?>
            </div>
        </div>
    </main>
    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
<script>
(function () {
    var btn = document.getElementById('useHere');
    if (btn) btn.addEventListener('click', function () {
        if (!navigator.geolocation) { alert('This browser cannot share its location.'); return; }
        navigator.geolocation.getCurrentPosition(function (p) {
            document.querySelector('input[name=lat]').value = p.coords.latitude.toFixed(7);
            document.querySelector('input[name=lng]').value = p.coords.longitude.toFixed(7);
        }, function () { alert('Location not available. Allow it in the browser, or type it in.'); });
    });

    var el = document.getElementById('signalHistory');
    if (!el) return;
    var points = <?php echo json_encode(array_map(function ($h) { return ['t' => date('j M H:i', strtotime($h['at'])), 'rx' => $h['rx_power'] === null ? null : (float)$h['rx_power'], 'olt' => $h['olt_rx_power'] === null ? null : (float)$h['olt_rx_power']]; }, $history)); ?>;
    var chart;
    function dark() { return document.documentElement.classList.contains('dark'); }
    function build() {
        if (chart) chart.destroy();
        var tick = dark() ? '#cbd5e1' : '#475569', grid = dark() ? 'rgba(148,163,184,0.15)' : 'rgba(148,163,184,0.25)';
        chart = new Chart(el.getContext('2d'), {
            type: 'line',
            data: { labels: points.map(function (p) { return p.t; }), datasets: [
                { label: 'ONU receives (dBm)', data: points.map(function (p) { return p.rx; }), borderColor: '#3b63f6', pointRadius: 0, tension: 0.25, spanGaps: true },
                { label: 'OLT receives (dBm)', data: points.map(function (p) { return p.olt; }), borderColor: '#8b5cf6', pointRadius: 0, tension: 0.25, spanGaps: true }
            ] },
            options: { responsive: true, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { labels: { color: tick } } },
                scales: { x: { ticks: { color: tick, maxTicksLimit: 8 }, grid: { display: false } }, y: { ticks: { color: tick }, grid: { color: grid } } } }
        });
    }
    build();
    window.addEventListener('themechange', build);
})();
</script>
