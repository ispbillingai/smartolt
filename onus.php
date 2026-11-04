<?php
require_once __DIR__ . '/backend/bootstrap.php';

$filter = [
    'olt_id'           => (int)($_GET['olt'] ?? 0),
    'pon'              => trim((string)($_GET['pon'] ?? '')),
    'zone_id'          => (int)($_GET['zone'] ?? 0),
    'odb_id'           => (int)($_GET['odb'] ?? 0),
    'onu_type_id'      => (int)($_GET['type'] ?? 0),
    'speed_profile_id' => (int)($_GET['speed'] ?? 0),
    'vlan'             => (int)($_GET['vlan'] ?? 0),
    'status'           => in_array($_GET['status'] ?? '', OltManager::STATUSES, true) ? $_GET['status'] : '',
    'q'                => trim((string)($_GET['q'] ?? '')),
];
$returnQs = http_build_query(array_filter([
    'olt' => $filter['olt_id'], 'pon' => $filter['pon'], 'zone' => $filter['zone_id'], 'odb' => $filter['odb_id'], 'type' => $filter['onu_type_id'],
    'speed' => $filter['speed_profile_id'], 'vlan' => $filter['vlan'], 'status' => $filter['status'], 'q' => $filter['q'],
]));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $return = 'onus.php' . (($_POST['return'] ?? '') !== '' ? '?' . preg_replace('/[^\w=&%.\/+-]/', '', $_POST['return']) : '');
    $do = $_POST['do'] ?? '';
    try {
        if ($do === 'reboot') {
            $mgr->reboot((int)($_POST['id'] ?? 0));
            back($return, 'success', 'Reboot sent. The ONU is back in about two minutes.');
        }
        if ($do === 'delete') {
            $n = $mgr->deleteOnu((int)($_POST['id'] ?? 0));
            back($return, 'success', 'ONU deleted from the OLT' . ($n ? " with its {$n} service port" . ($n > 1 ? 's' : '') : '') . '.');
        }
        if ($do === 'bulk') {
            $action = (string)($_POST['bulk_action'] ?? '');
            $r = $mgr->bulk((array)($_POST['ids'] ?? []), $action, (int)($_POST['bulk_speed'] ?? 0));
            $verb = ['reboot' => 'Rebooted', 'disable' => 'Disabled', 'enable' => 'Enabled', 'speed' => 'New speed set on'][$action];
            $msg = "{$verb} {$r['done']} ONU" . ($r['done'] === 1 ? '' : 's') . '.';
            if ($r['failed']) {
                flash('error', count($r['failed']) . ' not done: ' . implode('; ', array_slice($r['failed'], 0, 5)) . (count($r['failed']) > 5 ? '; and more, see the Activity Log.' : ''));
            }
            back($return, 'success', $msg);
        }
    } catch (Throwable $e) {
        back($return, 'error', $e->getMessage());
    }
}

$onus = $mgr->listOnus($filter);

// Same list as a spreadsheet.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="onus-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Serial number', 'Model', 'OLT', 'PON port', 'ONU ID', 'Status', 'Down cause', 'Signal dBm', 'OLT receives dBm', 'Distance m', 'VLAN', 'Speed', 'Mode', 'Zone', 'ODB', 'ODB port', 'Customer', 'Address', 'Latitude', 'Longitude', 'Authorized', 'Last down']);
    foreach ($onus as $u) {
        fputcsv($out, [$u['description'], $u['sn'], $u['type_name'] ?? $u['model'], $u['olt_name'], "{$u['frame']}/{$u['slot']}/{$u['port']}", $u['ont_id'],
            status_label($u['status_group']), $u['last_down_cause'], $u['rx_power'], $u['olt_rx_power'], $u['distance_m'], $u['vlan'], $u['speed_name'], $u['mode'],
            $u['zone_name'], $u['odb_name'], $u['odb_port'], $u['customer_ref'], $u['address'], $u['lat'], $u['lng'], $u['authorized_at'], $u['last_down_at']]);
    }
    exit;
}

$olts = $mgr->olts();
$zones = $mgr->zones();
$odbs = $mgr->odbs();
$types = $mgr->onuTypes();
$speeds = $mgr->speedProfiles();
$statusNames = ['online' => 'Online', 'offline' => 'Any offline', 'los' => 'Signal loss (LOS)', 'power_fail' => 'Power fail', 'other' => 'Offline, other cause',
    'disabled' => 'Disabled', 'warning' => 'Signal warning', 'critical' => 'Signal critical', 'weak' => 'Low signal'];

$pageTitle = 'Configured ONUs';
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading('Configured ONUs', 'Status and signal are read from the OLT every 5 minutes. Open an ONU to read it live.',
            '<a href="onus.php?' . e($returnQs . ($returnQs !== '' ? '&' : '') . 'export=csv') . '" class="' . UI_BTN_GHOST . '">Export CSV</a>'); ?>
        <?php echo flash_messages(); ?>

        <?php echo panel_open(count($onus) . ' ONUs'); ?>
            <form method="GET" class="mb-4 grid grid-cols-2 md:grid-cols-4 xl:grid-cols-8 gap-3 items-end">
                <select name="olt" class="<?php echo UI_INPUT; ?>"><option value="">All OLTs</option><?php foreach ($olts as $o): ?><option value="<?php echo (int)$o['id']; ?>" <?php echo (int)$o['id'] === $filter['olt_id'] ? 'selected' : ''; ?>><?php echo e($o['name']); ?></option><?php endforeach; ?></select>
                <input name="pon" value="<?php echo e($filter['pon']); ?>" placeholder="PON port 0/1/0" class="<?php echo UI_INPUT; ?>">
                <select name="status" class="<?php echo UI_INPUT; ?>"><option value="">Any status</option><?php foreach ($statusNames as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo $filter['status'] === $k ? 'selected' : ''; ?>><?php echo $v; ?></option><?php endforeach; ?></select>
                <select name="zone" class="<?php echo UI_INPUT; ?>"><?php echo options($zones, $filter['zone_id'] ?: '', 'Any zone'); ?></select>
                <select name="odb" class="<?php echo UI_INPUT; ?>"><?php echo options($odbs, $filter['odb_id'] ?: '', 'Any ODB'); ?></select>
                <select name="type" class="<?php echo UI_INPUT; ?>"><?php echo options($types, $filter['onu_type_id'] ?: '', 'Any ONU type'); ?></select>
                <select name="speed" class="<?php echo UI_INPUT; ?>"><?php echo options($speeds, $filter['speed_profile_id'] ?: '', 'Any speed'); ?></select>
                <input name="vlan" type="number" min="1" max="4094" value="<?php echo $filter['vlan'] ?: ''; ?>" placeholder="VLAN" class="<?php echo UI_INPUT; ?>">
                <input name="q" value="<?php echo e($filter['q']); ?>" placeholder="Serial, name, customer or address" class="<?php echo UI_INPUT; ?> col-span-2 md:col-span-2 xl:col-span-3">
                <div class="flex gap-2 col-span-2">
                    <button class="<?php echo UI_BTN_PRIMARY; ?>">Filter</button>
                    <?php if ($returnQs !== ''): ?><a href="onus.php" class="<?php echo UI_BTN_GHOST; ?>">Clear</a><?php endif; ?>
                </div>
            </form>

            <form method="POST" id="bulkForm" data-busy="Working through the ticked ONUs on the OLT...">
                <?php echo csrf_field(); ?><input type="hidden" name="do" value="bulk"><input type="hidden" name="return" value="<?php echo e($returnQs); ?>">
                <div id="bulkBar" class="hidden mb-3 p-3 rounded-xl border border-brand-200 dark:border-brand-800/50 bg-brand-50 dark:bg-brand-900/30 flex flex-wrap items-center gap-3 text-sm">
                    <span><b id="bulkCount">0</b> ticked</span>
                    <select name="bulk_action" id="bulkAction" class="<?php echo UI_INPUT; ?> w-auto">
                        <option value="reboot">Reboot</option><option value="disable">Disable</option><option value="enable">Enable</option><option value="speed">Set speed</option>
                    </select>
                    <select name="bulk_speed" id="bulkSpeed" class="<?php echo UI_INPUT; ?> w-auto hidden"><?php echo options($speeds, '', 'Choose speed'); ?></select>
                    <button type="submit" class="<?php echo UI_BTN_AMBER; ?>">Apply</button>
                </div>
            </form>

            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>">
                        <tr>
                            <th class="<?php echo UI_TH; ?> w-8"><input type="checkbox" id="tickAll" aria-label="Tick all"></th>
                            <th class="<?php echo UI_TH; ?>">Status</th><th class="<?php echo UI_TH; ?>">ONU</th><th class="<?php echo UI_TH; ?>">Serial / Model</th>
                            <th class="<?php echo UI_TH; ?>">PON Port</th><th class="<?php echo UI_TH; ?>">Signal</th><th class="<?php echo UI_TH; ?>">Service</th>
                            <th class="<?php echo UI_TH; ?>">Zone / ODB</th><th class="<?php echo UI_TH; ?> text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php if (!$onus): ?>
                        <tr><td colspan="9" class="px-4 py-8 text-center text-slate-400 dark:text-slate-500"><?php echo $olts ? 'No ONUs match. If you just added the OLT, press Refresh on it under OLTs.' : 'Add your OLT first.'; ?></td></tr>
                    <?php endif; ?>
                    <?php foreach ($onus as $u): ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                            <td class="<?php echo UI_TD; ?>"><input type="checkbox" class="tick" name="ids[]" value="<?php echo (int)$u['id']; ?>" form="bulkForm" aria-label="Tick <?php echo e($u['description']); ?>"></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo status_badge($u['status_group'], $u['run_state']); ?>
                                <?php if ($u['status_group'] !== 'online' && $u['last_down_at']): ?><div class="text-xs text-slate-400 mt-1"><?php echo ago($u['last_down_at']); ?></div><?php endif; ?></td>
                            <td class="<?php echo UI_TD; ?> font-medium"><a class="text-brand-600 dark:text-brand-400" href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description']); ?></a>
                                <?php if ($u['customer_ref'] !== ''): ?><div class="text-xs text-slate-500 dark:text-slate-400"><?php echo e($u['customer_ref']); ?></div><?php endif; ?></td>
                            <td class="<?php echo UI_TD; ?>"><span class="font-mono text-xs text-slate-600 dark:text-slate-300"><?php echo e($u['sn']); ?></span>
                                <div class="text-xs text-slate-400"><?php echo e($u['type_name'] ?? ($u['model'] ?: '')); ?></div></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300 whitespace-nowrap"><?php echo e($u['olt_name']); ?>
                                <div class="font-mono text-xs"><?php echo (int)$u['frame'] . '/' . (int)$u['slot'] . '/' . (int)$u['port'] . ' ID ' . (int)$u['ont_id']; ?></div></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo signal_html($u['rx_power']); ?>
                                <?php if ($u['distance_m'] !== null): ?><div class="text-xs text-slate-400"><?php echo fmt_distance($u['distance_m']); ?></div><?php endif; ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300 whitespace-nowrap"><?php echo $u['vlan'] ? 'VLAN ' . (int)$u['vlan'] : '--'; ?>
                                <div class="text-xs text-slate-400"><?php echo $u['speed_name'] !== null ? e($u['speed_name']) : 'No speed limit'; ?><?php echo $u['mode'] ? ', ' . e(ucfirst($u['mode'])) : ''; ?></div></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo $u['zone_name'] !== null ? e($u['zone_name']) : '--'; ?>
                                <?php if ($u['odb_name'] !== null): ?><div class="text-xs text-slate-400"><?php echo e($u['odb_name']) . ($u['odb_port'] ? ' port ' . (int)$u['odb_port'] : ''); ?></div><?php endif; ?></td>
                            <td class="<?php echo UI_TD; ?> text-right whitespace-nowrap">
                                <a href="onu.php?id=<?php echo (int)$u['id']; ?>" class="<?php echo UI_BTN_PRIMARY; ?>">View</a>
                                <form method="POST" class="inline" data-busy="Sending the reboot..." onsubmit="return confirm('Reboot this ONU? The customer is offline for about two minutes.');">
                                    <?php echo csrf_field(); ?><input type="hidden" name="do" value="reboot"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><input type="hidden" name="return" value="<?php echo e($returnQs); ?>">
                                    <button class="<?php echo UI_BTN_AMBER; ?>">Reboot</button>
                                </form>
                                <form method="POST" class="inline" data-busy="Deleting the ONU..." onsubmit="return confirm('Delete this ONU from the OLT? The customer loses internet until it is authorized again.');">
                                    <?php echo csrf_field(); ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>"><input type="hidden" name="return" value="<?php echo e($returnQs); ?>">
                                    <button class="<?php echo UI_BTN_RED; ?>">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php echo panel_close(); ?>
    </main>
    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
<script>
(function () {
    var ticks = Array.prototype.slice.call(document.querySelectorAll('.tick'));
    var all = document.getElementById('tickAll'), bar = document.getElementById('bulkBar');
    var action = document.getElementById('bulkAction'), speed = document.getElementById('bulkSpeed');
    function update() {
        var n = ticks.filter(function (t) { return t.checked; }).length;
        document.getElementById('bulkCount').textContent = n;
        bar.classList.toggle('hidden', n === 0);
        bar.classList.toggle('flex', n > 0);
    }
    ticks.forEach(function (t) { t.addEventListener('change', update); });
    if (all) all.addEventListener('change', function () { ticks.forEach(function (t) { t.checked = all.checked; }); update(); });
    action.addEventListener('change', function () { speed.classList.toggle('hidden', action.value !== 'speed'); });
    document.getElementById('bulkForm').addEventListener('submit', function (e) {
        var n = document.getElementById('bulkCount').textContent;
        if (action.value === 'speed' && !speed.value) { alert('Choose the speed first.'); e.preventDefault(); e.stopImmediatePropagation(); return; }
        var words = {reboot: 'Reboot', disable: 'Disable (they lose internet until enabled)', enable: 'Enable', speed: 'Set the new speed on'};
        if (!confirm(words[action.value] + ' ' + n + ' ONUs?')) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);
})();
</script>
