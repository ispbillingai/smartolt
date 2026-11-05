<?php
require_once __DIR__ . '/backend/bootstrap.php';

$reports = [
    'auth' => 'Authorizations', 'export' => 'Export', 'import' => 'Import', 'traffic' => 'Traffic usage', 'wifi' => 'WiFi signal',
    'status' => 'ONU status changes', 'pon' => 'PON occupancy', 'splitters' => 'Splitter check', 'mismatch' => 'Find config mismatches (DB vs OLT)',
];
$r = isset($reports[$_GET['r'] ?? '']) ? $_GET['r'] : 'auth';
$self = 'reports.php?r=' . $r;
$olts = $mgr->olts();
$f = ['olt_id' => (int)($_GET['olt'] ?? 0), 'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? ''), 'state' => (string)($_GET['state'] ?? '')];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        if (($_POST['do'] ?? '') === 'import') {
            [$done, $skipped] = $mgr->importOnus(csv_upload('file'));
            if ($skipped) flash('error', count($skipped) . ' rows skipped: ' . implode('; ', array_slice($skipped, 0, 6)) . (count($skipped) > 6 ? '; and more.' : ''));
            back('reports.php?r=import', 'success', "Updated {$done} ONU" . ($done === 1 ? '' : 's') . '.');
        }
        if (($_POST['do'] ?? '') === 'resync') {
            $mgr->resync((int)($_POST['id'] ?? 0));
            back('reports.php?r=mismatch', 'success', 'The saved VLAN, mode and speed were put on the OLT. The report updates after the next read.');
        }
    } catch (Throwable $e) {
        back($r === 'import' ? 'reports.php?r=import' : 'reports.php?r=mismatch', 'error', $e->getMessage());
    }
}

// Export: the Configured list as a spreadsheet, with the same filters.
$exportFilter = [
    'olt_id' => (int)($_GET['olt'] ?? 0), 'zone_id' => (int)($_GET['zone'] ?? 0), 'odb_id' => (int)($_GET['odb'] ?? 0), 'onu_type_id' => (int)($_GET['type'] ?? 0),
    'speed_profile_id' => (int)($_GET['speed'] ?? 0), 'sector_id' => (int)($_GET['sector'] ?? 0), 'vlan' => (int)($_GET['vlan'] ?? 0),
    'board' => (string)($_GET['board'] ?? ''), 'port_no' => (string)($_GET['port'] ?? ''), 'pon' => (string)($_GET['pon'] ?? ''), 'q' => (string)($_GET['q'] ?? ''),
    'mode' => (string)($_GET['mode'] ?? ''), 'signal' => (string)($_GET['signal'] ?? ''),
    'status' => in_array($_GET['status'] ?? '', OltManager::STATUSES, true) ? $_GET['status'] : '',
];
if ($r === 'export' && isset($_GET['download'])) {
    $rows = [];
    $sectorNames = array_column($mgr->sectors(), 'name', 'id');
    foreach ($mgr->listOnus($exportFilter) as $u) {
        $rows[] = [$u['description'], $u['sn'], $u['type_name'] ?? '', $u['model'], $u['olt_name'], "{$u['frame']}/{$u['slot']}/{$u['port']}", $u['ont_id'],
            status_label($u['status_group']), $u['last_down_cause'], $u['rx_power'], $u['olt_rx_power'], $u['distance_m'], $u['vlan'], $u['speed_name'], $u['mode'],
            $u['zone_name'], $sectorNames[$u['sector_id']] ?? '', $u['odb_name'], $u['odb_port'], $u['customer_ref'], $u['address'], $u['lat'], $u['lng'], $u['authorized_at'], $u['last_down_at']];
    }
    csv_download('onus', ['Name', 'Serial number', 'ONU type', 'Model', 'OLT', 'PON port', 'ONU ID', 'Status', 'Down cause', 'Signal dBm', 'OLT receives dBm', 'Distance m',
        'VLAN', 'Speed', 'Mode', 'Zone', 'Sector', 'Splitter', 'Splitter port', 'External ID', 'Address', 'Latitude', 'Longitude', 'Authorized', 'Last down'], $rows);
}

$pageTitle = $reports[$r];
include __DIR__ . '/backend/templates/header.php';
$dateForm = function (bool $state = false) use ($olts, $f, $r) {
    $h = '<form method="GET" class="so-inline" style="margin-bottom:14px"><input type="hidden" name="r" value="' . $r . '">'
        . '<label class="so-label">OLT</label><select name="olt" class="so-select" onchange="this.form.submit()"><option value="">Any</option>' . olt_options($olts, $f['olt_id'] ?: '') . '</select>';
    if ($state) {
        $h .= '<label class="so-label">Became</label><select name="state" class="so-select" onchange="this.form.submit()"><option value="">Any</option>';
        foreach (STATUS_NAMES as $k => $l) $h .= '<option value="' . $k . '"' . ($f['state'] === $k ? ' selected' : '') . '>' . $l . '</option>';
        $h .= '</select>';
    }
    return $h . '<label class="so-label">From</label><input type="date" name="from" value="' . e($f['from']) . '" class="so-input" onchange="this.form.submit()">'
        . '<label class="so-label">To</label><input type="date" name="to" value="' . e($f['to']) . '" class="so-input" onchange="this.form.submit()"></form>';
};
?>
<?php echo flash_messages(); ?>

<?php if ($r === 'auth'): $rows = $mgr->actionsFiltered($f + ['action' => 'onu.authorize', 'ok' => 1], 1000); ?>
    <?php echo page_title('Authorizations', 'Every ONU authorized through SmartOLT, by whom and when.'); ?>
    <?php echo $dateForm(); ?>
    <?php if (!$rows): ?><div class="so-empty">No info available</div><?php else: ?>
    <div class="so-count"><?php echo count($rows); ?> authorizations</div>
    <div class="so-table-wrap"><table class="so-table"><thead><tr><th>Date</th><th>OLT</th><th>PON port and SN</th><th>By</th></tr></thead><tbody>
        <?php foreach ($rows as $a): ?><tr><td><?php echo e(when($a['created_at'])); ?></td><td><?php echo e($a['olt_name'] ?? ''); ?></td><td class="so-mono"><?php echo e($a['target']); ?></td>
            <td><?php echo $a['source'] === 'auto' ? 'Auto action' : ($a['source'] === 'api' ? 'Billing dashboard' : e($a['user'] ?: 'Panel')); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>

<?php elseif ($r === 'export'): $count = count($mgr->listOnus($exportFilter)); $qs = $_GET; unset($qs['r']); ?>
    <?php echo page_title('Export', 'Download your ONUs as a CSV spreadsheet: name, serial, place on the OLT, status, signal, service, zone, sector, splitter, location and more.',
        btn('Import', 'grey', 'reports.php?r=import', 'download')); ?>
    <div class="so-box">
        <p style="margin-top:0"><?php echo $count; ?> ONUs<?php echo array_filter($exportFilter) ? ' match the filters you had on the Configured list' : ''; ?>.</p>
        <?php echo btn('Download CSV', 'green', 'reports.php?' . http_build_query(['r' => 'export', 'download' => 1] + $qs), 'upload'); ?>
        <?php if (array_filter($exportFilter)): ?> <a href="reports.php?r=export">Export every ONU instead</a><?php endif; ?>
    </div>

<?php elseif ($r === 'import'): ?>
    <?php echo page_title('Import', 'Update ONU details in bulk from a CSV file, matched by serial number.', btn('Export', 'grey', 'reports.php?r=export', 'upload')); ?>
    <form method="POST" enctype="multipart/form-data" class="so-box" data-busy="Importing...">
        <?php echo csrf_field(); ?><input type="hidden" name="do" value="import">
        <p style="margin-top:0">The first row names the columns. <b>Serial number</b> is required; any of these update the ONU: <b>Zone, Sector, Splitter, Splitter port, ONU type, External ID, Address, Latitude, Longitude</b>.
            Zones, sectors, splitters and types are written by name and must exist already. A file from Export can be edited and imported again.</p>
        <p class="so-muted">Only details kept in SmartOLT change. Names, VLANs and speeds on the OLT are not touched by an import.</p>
        <input type="file" name="file" accept=".csv,text/csv" required> <button class="so-btn so-btn-blue"><i class="fa fa-download"></i> Import</button>
    </form>

<?php elseif ($r === 'traffic' || $r === 'wifi'): ?>
    <?php echo page_title($reports[$r], $r === 'traffic' ? 'Data each ONU used, by day and month.' : 'The WiFi signal each ONU\'s clients get.'); ?>
    <div class="so-box">
        <div class="so-empty"><?php echo $r === 'traffic' ? 'No traffic counters collected yet' : 'No WiFi readings yet'; ?></div>
        <p class="so-muted" style="margin:0"><?php echo $r === 'traffic'
            ? 'Traffic per ONU is not read from the OLT yet. Until then, your billing dashboard shows each customer\'s data use from the router.'
            : 'WiFi signal is read from the ONU itself over TR069, which is not connected to SmartOLT yet. See Settings, VPN & TR069.'; ?></p>
    </div>

<?php elseif ($r === 'status'): $rows = $mgr->statusChanges($f, 1000); ?>
    <?php echo page_title('ONU status changes', 'When ONUs went offline or came back, and why, as seen on each 5 minute read.'); ?>
    <?php echo $dateForm(true); ?>
    <?php if (!$rows): ?><div class="so-empty">No info available</div><?php else: ?>
    <div class="so-table-wrap"><table class="so-table"><thead><tr><th>Date</th><th>ONU</th><th>OLT</th><th>Became</th><th>Cause</th></tr></thead><tbody>
        <?php foreach ($rows as $ev): ?><tr><td><?php echo e(when($ev['at'])); ?></td><td><a href="onu.php?id=<?php echo (int)$ev['onu_id']; ?>&live=0"><?php echo e($ev['description']); ?></a><br><small class="so-mono"><?php echo e($ev['sn']); ?></small></td>
            <td><?php echo e($ev['olt_name'] . ' ' . onu_place($ev)); ?></td><td><?php echo status_icon($ev['state']) . ' ' . e(status_label($ev['state'])); ?></td><td><?php echo e($ev['cause'] ?: '-'); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>

<?php elseif ($r === 'pon'): $rows = $mgr->ponOccupancy(); ?>
    <?php echo page_title('PON occupancy', 'How full each PON port is. A Huawei GPON port takes up to 128 ONUs; keep room for the splitters you plan.'); ?>
    <?php if (!$rows): ?><div class="so-empty">No info available</div><?php else: ?>
    <div class="so-table-wrap"><table class="so-table"><thead><tr><th>OLT</th><th>PON port</th><th>ONUs</th><th>Online</th><th>Occupancy</th></tr></thead><tbody>
        <?php foreach ($rows as $p): $pon = "{$p['frame']}/{$p['slot']}/{$p['port']}"; $pct = round($p['total'] * 100 / 128); ?>
            <tr><td><?php echo e($p['olt_name']); ?></td><td><a href="onus.php?olt=<?php echo (int)$p['olt_id']; ?>&pon=<?php echo $pon; ?>" class="so-mono"><?php echo $pon; ?></a></td>
                <td><?php echo (int)$p['total']; ?> / 128</td><td><?php echo (int)$p['online']; ?></td>
                <td><div class="so-inline"><div class="so-bar" style="width:160px"><span style="width:<?php echo max(2, $pct); ?>%;background:<?php echo $pct >= 90 ? 'var(--red)' : ($pct >= 70 ? 'var(--orange)' : 'var(--green)'); ?>"></span></div><?php echo $pct; ?>%</div></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>

<?php elseif ($r === 'splitters'): $rows = $mgr->splitterCheck(); $bad = array_filter($rows, function ($d) { return $d['issues']; }); ?>
    <?php echo page_title('Splitter check', 'Splitters with more ONUs than ports, a port given twice, ONUs without a port, or ONUs from different PON ports on one splitter.'); ?>
    <?php if (!$rows): ?><div class="so-empty">No splitters yet. Add them under Settings, Splitters.</div>
    <?php elseif (!$bad): ?><div class="so-alert a-ok">All <?php echo count($rows); ?> splitters look right.</div>
    <?php else: ?>
    <div class="so-table-wrap"><table class="so-table"><thead><tr><th>Splitter</th><th>Zone</th><th>Ports used</th><th>Problems</th></tr></thead><tbody>
        <?php foreach ($bad as $d): ?><tr><td><a href="onus.php?odb=<?php echo (int)$d['id']; ?>"><?php echo e($d['name']); ?></a></td><td><?php echo e($d['zone_name'] ?? '-'); ?></td>
            <td><?php echo (int)$d['used']; ?> of <?php echo (int)$d['ports']; ?></td><td><?php echo implode('<br>', array_map('e', $d['issues'])); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>

<?php else: $rows = $mgr->mismatches($f['olt_id']); ?>
    <?php echo page_title('Find config mismatches (DB vs OLT)', 'ONUs whose setup on the OLT differs from what SmartOLT has saved, for example a speed changed on the OLT by hand or a missing service port. Resync puts the saved VLAN, mode and speed back on the OLT.'); ?>
    <form method="GET" class="so-inline" style="margin-bottom:14px"><input type="hidden" name="r" value="mismatch">
        <label class="so-label">OLT</label><select name="olt" class="so-select" onchange="this.form.submit()"><option value="">Any</option><?php echo olt_options($olts, $f['olt_id'] ?: ''); ?></select></form>
    <?php if (!$rows): ?><div class="so-alert a-ok">No mismatches. Every ONU on the OLT matches what SmartOLT has saved.</div><?php else: ?>
    <div class="so-table-wrap"><table class="so-table"><thead><tr><th>ONU</th><th>OLT</th><th>Saved in SmartOLT</th><th>Problem</th><th></th></tr></thead><tbody>
        <?php foreach ($rows as $u): ?><tr><td><a href="onu.php?id=<?php echo (int)$u['id']; ?>&live=0"><?php echo e($u['description']); ?></a><br><small class="so-mono"><?php echo e($u['sn']); ?></small></td>
            <td><?php echo e($u['olt_name'] . ' ' . onu_place($u)); ?></td>
            <td style="font-size:14px">VLAN <?php echo $u['vlan'] ? (int)$u['vlan'] : '-'; ?>, <?php echo e($u['speed_name'] ?? 'no speed limit'); ?>, <?php echo e($u['mode'] ?: 'bridge'); ?></td>
            <td style="font-size:14px"><?php echo implode('<br>', array_map('e', $u['issues'])); ?></td>
            <td class="num"><?php echo $u['vlan'] ? post_btn('resync', ['id' => $u['id']], 'Resync', 'blue', 'refresh', 'Put the saved VLAN, mode and speed on the OLT for ' . $u['description'] . '? The customer drops for a few seconds.', 'Resyncing on the OLT...') : '<span class="so-muted" style="font-size:13px">Set its VLAN first</span>'; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
