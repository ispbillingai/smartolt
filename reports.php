<?php
require_once __DIR__ . '/backend/bootstrap.php';

$reports = [
    'auth' => 'Authorizations', 'export' => 'Export', 'import' => 'Import', 'traffic' => 'Traffic usage', 'wifi' => 'WiFi signal',
    'status' => 'ONU status changes', 'pon' => 'PON occupancy', 'splitters' => 'Splitter check', 'mismatch' => 'Find config mismatches (DB vs OLT)',
];
$r = isset($reports[$_GET['r'] ?? '']) ? $_GET['r'] : 'auth';
$olts = $mgr->olts();
$g = function (string $k) { return trim((string)($_GET[$k] ?? '')); };
$scanned = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $do = $_POST['do'] ?? '';
    try {
        if ($do === 'import') {
            [$done, $skipped] = $mgr->importOnus(csv_upload('file'));
            if ($skipped) flash('error', count($skipped) . ' rows skipped: ' . implode('; ', array_slice($skipped, 0, 6)) . (count($skipped) > 6 ? '; and more.' : ''));
            back('reports.php?r=import', 'success', "Updated {$done} ONU" . ($done === 1 ? '' : 's') . '.');
        }
        if ($do === 'resync') {
            $mgr->resync((int)($_POST['id'] ?? 0));
            back('reports.php?r=mismatch', 'success', 'The saved VLAN, mode and speed were put on the OLT. The report updates after the next read.');
        }
        if ($do === 'auto_sync') {
            $mgr->setAutoSync(($_POST['on'] ?? '') === '1');
            back('reports.php?r=mismatch', 'success', ($_POST['on'] ?? '') === '1' ? 'Keep in sync is on. Every day SmartOLT puts the saved setup back on ONUs that drifted.' : 'Keep in sync is off.');
        }
        if ($do === 'scan') {
            // Read the chosen OLTs now, then compare.
            $ids = array_map('intval', (array)($_POST['olts'] ?? []));
            if (!$ids) throw new InvalidArgumentException('Select the OLTs to scan.');
            foreach ($ids as $id) if ($o = $mgr->olt($id)) $mgr->refresh($o);
            $scanned = $ids;
            $r = 'mismatch';
        }
    } catch (Throwable $e) {
        back('reports.php?r=' . ($do === 'import' ? 'import' : 'mismatch'), 'error', $e->getMessage());
    }
}

// Export: the Configured list as a spreadsheet, with the same filters.
$exportFilter = [
    'olt_id' => (int)$g('olt'), 'zone_id' => (int)$g('zone'), 'odb_id' => (int)$g('odb'), 'onu_type_id' => (int)$g('type'),
    'speed_profile_id' => (int)$g('speed'), 'sector_id' => (int)$g('sector'), 'vlan' => (int)$g('vlan'),
    'board' => $g('board'), 'port_no' => $g('port'), 'pon' => $g('pon'), 'q' => $g('q'), 'mode' => $g('mode'), 'signal' => $g('signal'),
    'status' => in_array($g('status'), OltManager::STATUSES, true) ? $g('status') : '',
];
if ($r === 'export' && isset($_GET['download'])) {
    $rows = [];
    foreach ($mgr->listOnus($exportFilter) as $u) {
        $line = [$u['description'], $u['sn'], $u['type_name'] ?? '', $u['model'], $u['olt_name'], "{$u['frame']}/{$u['slot']}/{$u['port']}", $u['ont_id'],
            status_label($u['status_group']), $u['last_down_cause'], $u['rx_power'], $u['olt_rx_power'], $u['distance_m'], $u['vlan'], $u['speed_name'], $u['mode'],
            $u['zone_name'], $u['sector_name'], $u['odb_name'], $u['odb_port'], $u['customer_ref'], $u['address'], $u['lat'], $u['lng'], $u['authorized_at'], $u['last_down_at']];
        if (isset($_GET['versions'])) $line[] = $u['model'];
        $rows[] = $line;
    }
    $mgr->log(null, 'report.export', count($rows) . ' ONUs', true, 'ok');
    csv_download('onus', array_merge(['Name', 'Serial number', 'ONU type', 'Model', 'OLT', 'PON port', 'ONU ID', 'Status', 'Down cause', 'Signal dBm', 'OLT receives dBm', 'Distance m',
        'VLAN', 'Speed', 'Mode', 'Zone', 'Sector', 'Splitter', 'Splitter port', 'External ID', 'Address', 'Latitude', 'Longitude', 'Authorized', 'Last down'], isset($_GET['versions']) ? ['Hardware'] : []), $rows);
}
if ($r === 'pon' && isset($_GET['csv'])) {
    csv_download('pon-occupancy', ['OLT', 'PON port', 'PON type', 'ONUs', 'Max ONUs', 'Load %', 'Splitters', 'Empty splitter ports'], array_map(function ($p) {
        return [$p['olt_name'], "{$p['frame']}/{$p['slot']}/{$p['port']}", 'GPON', $p['total'], $p['max'], round($p['total'] * 100 / $p['max']), $p['splitters'], $p['empty']];
    }, $mgr->ponOccupancy((int)$g('olt'))));
}

$pageTitle = $reports[$r];
include __DIR__ . '/backend/templates/header.php';
$reset = function () use ($r) { return '<a href="reports.php?r=' . $r . '" style="color:var(--text);font-weight:700;font-size:14px"><i class="fa fa-times"></i> Reset filters</a>'; };
$sel = function (string $name, string $label, string $opts, string $width = '140px') {
    return '<div><label class="so-label">' . $label . '</label><select name="' . $name . '" class="so-select" style="width:' . $width . '" onchange="this.form.submit()">' . $opts . '</select></div>';
};
$num = function (array $nums, string $cur) {
    $h = '<option value="">Any</option>';
    foreach ($nums as $n) $h .= '<option' . ((string)$n === $cur ? ' selected' : '') . '>' . (int)$n . '</option>';
    return $h;
};
?>
<?php echo flash_messages(); ?>

<?php if ($r === 'auth'):
    $pon = in_array($g('pon_type'), ['GPON', 'EPON'], true) ? $g('pon_type') : '';
    $rows = $pon === 'EPON' ? [] : $mgr->actionsFiltered(['action' => 'onu.authorize', 'ok' => 1, 'user' => $g('user'), 'q' => $g('q'), 'from' => $g('from'), 'to' => $g('to')], 2000);
    if (isset($_GET['csv'])) {
        csv_download('authorizations', ['User', 'SN', 'PON port', 'Name', 'PON', 'Date'], array_map(function ($a) { $p = explode(' ', $a['target']); return [$a['source'] === 'auto' ? 'Auto action' : $a['user'], $p[1] ?? '', $p[0], $a['message'] === 'ok' ? '' : $a['message'], 'GPON', $a['created_at']]; }, $rows));
    }
    $snName = [];
    foreach ($mgr->listOnus([]) as $u) $snName[$u['sn']] = $u; ?>
    <div class="so-tabbar" style="margin-top:0"><a class="on"><i class="fa fa-list"></i> Authorizations</a></div>
    <form method="GET" class="so-inline" style="margin-bottom:10px"><input type="hidden" name="r" value="auth">
        <label class="so-label">Search</label><input name="q" value="<?php echo e($g('q')); ?>" class="so-input" style="width:150px" placeholder="SN or MAC">
        <label class="so-label">User</label><select name="user" class="so-select" onchange="this.form.submit()"><option value="">Any</option><?php foreach ($mgr->actionUsers() as $u): ?><option <?php echo $u === $g('user') ? 'selected' : ''; ?>><?php echo e($u); ?></option><?php endforeach; ?></select>
        <span class="so-pills"><?php foreach (['EPON' => 'EPON', 'GPON' => 'GPON', '' => 'All'] as $k => $l): ?><a href="?<?php echo e(http_build_query(array_merge($_GET, ['r' => 'auth', 'pon_type' => $k]))); ?>" class="<?php echo $pon === $k ? 'on' : ''; ?>"><?php echo $l; ?></a><?php endforeach; ?></span>
        <label class="so-label">From</label><input type="date" name="from" value="<?php echo e($g('from')); ?>" class="so-input" onchange="this.form.submit()">
        <label class="so-label">To</label><input type="date" name="to" value="<?php echo e($g('to')); ?>" class="so-input" onchange="this.form.submit()">
        <button class="so-btn so-btn-green" name="csv" value="1"><i class="fa fa-upload"></i> Export</button>
    </form>
    <div class="so-count" style="text-align:left">1-<?php echo count($rows); ?> authorizations of <?php echo count($rows); ?> displayed</div>
    <div class="so-table-wrap"><table class="so-table" style="font-size:14px"><thead><tr><th>User</th><th>SN / MAC</th><th>Name</th><th>PON</th><th>Date <i class="fa fa-caret-down"></i></th></tr></thead><tbody>
        <?php if (!$rows) echo '<tr><td colspan="5" class="so-muted">No info available</td></tr>'; ?>
        <?php foreach ($rows as $a): $p = explode(' ', $a['target']); $sn = $p[1] ?? ''; $u = $snName[$sn] ?? null; ?>
            <tr><td><?php echo $a['source'] === 'auto' ? 'Auto action' : ($a['source'] === 'api' ? 'Billing dashboard' : e($a['user'] ?: 'Panel')); ?></td>
                <td class="so-mono"><?php echo $u ? '<a href="onu.php?id=' . (int)$u['id'] . '">' . e($sn) . '</a>' : e($sn); ?></td>
                <td><?php echo $u ? '<a href="onu.php?id=' . (int)$u['id'] . '">' . e($u['description']) . '</a>' : '<span class="so-muted">Deleted</span>'; ?></td>
                <td>GPON</td><td><?php echo e(date('d-M-Y H:i:s', strtotime($a['created_at']))); ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>

<?php elseif ($r === 'export'): $count = count($mgr->listOnus($exportFilter));
    $history = $mgr->actionsFiltered(['action' => 'report.export', 'from' => date('Y-m-d', time() - 7 * 86400)], 50); ?>
    <form method="GET" class="so-panel">
        <div class="so-panel-h"><span>ONU database export</span></div>
        <div class="so-panel-b">
            <input type="hidden" name="r" value="export"><input type="hidden" name="download" value="1">
            <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
                <div><label class="so-label">OLT</label><select name="olt" class="so-select" style="width:180px"><option value="">Any</option><?php echo olt_options($olts, $exportFilter['olt_id'] ?: ''); ?></select></div>
                <div><label class="so-label">Board</label><select name="board" class="so-select" style="width:100px"><?php echo $num(range(0, 21), $exportFilter['board']); ?></select></div>
                <div><label class="so-label">Port</label><select name="port" class="so-select" style="width:100px"><?php echo $num(range(0, 15), $exportFilter['port_no']); ?></select></div>
                <div><label class="so-label">Zone</label><select name="zone" class="so-select" style="width:140px"><?php echo options($mgr->zones(), $exportFilter['zone_id'] ?: '', 'Any'); ?></select></div>
                <button class="so-btn so-btn-green" style="margin-left:auto">Export ONUs</button>
            </div>
            <p style="margin:12px 0 0;font-size:14px"><label class="so-check"><input type="checkbox" name="versions" value="1"> Include ONU hardware versions</label></p>
            <p class="so-muted" style="font-size:13px;margin:6px 0 0"><?php echo $count; ?> ONUs<?php echo array_filter($exportFilter) ? ' match the filters you had on the Configured list' : ''; ?>. The file opens in Excel or Google Sheets; edit it and bring changes back with Import.</p>
        </div>
    </form>
    <?php echo panel_open('Export History - Last 7 days'); ?>
        <?php if (!$history): ?><p class="so-muted" style="text-align:center;margin:0">No exports found. Create your first export above.</p><?php else: ?>
        <table class="so-table" style="font-size:14px"><thead><tr><th>Date</th><th>User</th><th>ONUs</th></tr></thead><tbody>
            <?php foreach ($history as $h): ?><tr><td><?php echo e(when($h['created_at'])); ?></td><td><?php echo e($h['user'] ?: 'Panel'); ?></td><td><?php echo e($h['target']); ?></td></tr><?php endforeach; ?></tbody></table>
        <?php endif; ?>
    <?php echo panel_close(); ?>

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
    <form method="GET" class="so-box" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end"><input type="hidden" name="r" value="<?php echo $r; ?>">
        <div><label class="so-label">Search</label><div class="so-search"><i class="fa fa-search"></i><input name="q" class="so-input" style="width:190px" placeholder="SN, MAC, IP, name, address"></div></div>
        <?php if ($r === 'traffic'): ?><div><label class="so-label">Period</label><select class="so-select" style="width:150px"><option>Last month</option><option>Last week</option><option>Last 24 hours</option></select></div>
        <div><label class="so-label">Unit</label><select class="so-select" style="width:110px"><option>Auto</option><option>MB</option><option>GB</option></select></div><?php endif; ?>
        <?php echo $sel('olt', 'OLT', '<option value="">Any</option>' . olt_options($olts, $g('olt'))); ?>
        <?php echo $sel('zone', 'Zone', options($mgr->zones(), $g('zone'), 'Any')); ?>
        <span style="margin-left:auto"><?php echo $reset(); ?></span>
    </form>
    <div class="so-box so-muted">No data. <?php echo $r === 'traffic'
        ? 'Traffic per ONU is not read from the OLT yet; your billing dashboard shows each customer\'s data use from the router.'
        : 'WiFi signal is read from the ONU itself over TR069, which is not connected to SmartOLT yet.'; ?></div>

<?php elseif ($r === 'status'):
    $period = (int)($g('period') ?: 24);
    $from = $g('from') ?: date('Y-m-d', time() - $period * 3600);
    $rows = $mgr->statusChanges(['olt_id' => (int)$g('olt'), 'state' => $g('state'), 'from' => $from, 'to' => $g('to')], 2000);
    $zoneId = (int)$g('zone'); $q = $g('q');
    if ($zoneId || $q !== '' || $g('board') !== '' || $g('port') !== '') {
        $ids = array_flip(array_column($mgr->listOnus(['zone_id' => $zoneId, 'q' => $q, 'board' => $g('board'), 'port_no' => $g('port')]), 'id'));
        $rows = array_values(array_filter($rows, function ($e) use ($ids) { return isset($ids[$e['onu_id']]); }));
    }
    if (isset($_GET['csv'])) csv_download('status-changes', ['Date', 'ONU', 'SN', 'OLT', 'Place', 'Became', 'Cause'], array_map(function ($e) { return [$e['at'], $e['description'], $e['sn'], $e['olt_name'], onu_place($e), status_label($e['state']), $e['cause']]; }, $rows)); ?>
    <form method="GET" class="so-box"><input type="hidden" name="r" value="status">
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
            <div><label class="so-label">Search</label><div class="so-search"><i class="fa fa-search"></i><input name="q" value="<?php echo e($q); ?>" class="so-input" style="width:170px" placeholder="SN, MAC, IP, name"></div></div>
            <?php echo $sel('period', 'Period', implode('', array_map(function ($h, $l) use ($period) { return '<option value="' . $h . '"' . ($h === $period ? ' selected' : '') . '>' . $l . '</option>'; }, [24, 168, 720], ['Last 24 hours', 'Last week', 'Last month'])), '150px'); ?>
            <?php echo $sel('olt', 'OLT', '<option value="">Any</option>' . olt_options($olts, $g('olt'))); ?>
            <?php echo $sel('board', 'Board', $num(range(0, 21), $g('board')), '90px'); ?>
            <?php echo $sel('port', 'Port', $num(range(0, 15), $g('port')), '90px'); ?>
            <?php echo $sel('zone', 'Zone', options($mgr->zones(), $zoneId ?: '', 'Any')); ?>
            <?php echo $sel('state', 'Became', '<option value="">Any</option>' . implode('', array_map(function ($k, $l) use ($g) { return '<option value="' . $k . '"' . ($g('state') === $k ? ' selected' : '') . '>' . $l . '</option>'; }, array_keys(STATUS_NAMES), STATUS_NAMES))); ?>
        </div>
        <div style="display:flex;justify-content:flex-end;gap:18px;margin-top:10px"><?php echo $reset(); ?><button class="so-link-btn" name="csv" value="1"><i class="fa fa-upload"></i> Export to CSV</button></div>
    </form>
    <?php if (!$rows): ?><div class="so-box so-muted">No data</div><?php else: ?>
    <div class="so-table-wrap"><table class="so-table" style="font-size:14px"><thead><tr><th>Date</th><th>ONU</th><th>OLT</th><th>Became</th><th>Cause</th></tr></thead><tbody>
        <?php foreach ($rows as $ev): ?><tr><td><?php echo e(when($ev['at'])); ?></td><td><a href="onu.php?id=<?php echo (int)$ev['onu_id']; ?>"><?php echo e($ev['description']); ?></a><br><small class="so-mono"><?php echo e($ev['sn']); ?></small></td>
            <td><?php echo e($ev['olt_name'] . ' ' . onu_place($ev)); ?></td><td><?php echo status_icon($ev['state']) . ' ' . e(status_label($ev['state'])); ?></td><td><?php echo e($ev['cause'] ?: '-'); ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>

<?php elseif ($r === 'pon'): $oltF = (int)$g('olt'); $rows = $mgr->ponOccupancy($oltF);
    $onus = array_sum(array_column($rows, 'total'));
    $p70 = count(array_filter($rows, function ($p) { return $p['total'] * 100 / $p['max'] >= 70; }));
    $p90 = count(array_filter($rows, function ($p) { return $p['total'] * 100 / $p['max'] >= 90; })); ?>
    <form method="GET" class="so-box" style="display:flex;justify-content:space-between;align-items:end"><input type="hidden" name="r" value="pon">
        <?php echo $sel('olt', 'OLT', '<option value="">Any</option>' . olt_options($olts, $oltF ?: ''), '180px'); ?>
        <span style="display:flex;gap:18px"><?php echo $reset(); ?><a href="reports.php?r=pon&csv=1&olt=<?php echo $oltF ?: ''; ?>" style="color:var(--text);font-weight:700;font-size:14px"><i class="fa fa-upload"></i> Export to CSV</a></span>
    </form>
    <div class="so-inline" style="gap:12px;margin-bottom:12px">
        <div class="so-stat"><i class="fa fa-sitemap"></i><div><small>PON ports</small><b><?php echo count($rows); ?></b></div></div>
        <div class="so-stat"><i class="fa fa-hdd-o"></i><div><small>ONUs</small><b><?php echo $onus; ?></b></div></div>
        <div class="so-stat"><i class="fa fa-exclamation-circle" style="color:var(--orange)"></i><div><small>PON ports at 70% or more</small><b><?php echo $p70; ?></b></div></div>
        <div class="so-stat"><i class="fa fa-exclamation-triangle" style="color:var(--red)"></i><div><small>PON ports at 90% or more</small><b><?php echo $p90; ?></b></div></div>
    </div>
    <p class="so-muted" style="font-size:13px">The load compares the ONUs on each PON port with its Max ONUs value. Empty ports are the free ports of the splitters that have ONUs on this PON port.</p>
    <?php if (!$rows): ?><div class="so-box so-muted">No PON ports read yet.</div><?php else: ?>
    <div class="so-table-wrap"><table class="so-table" style="font-size:14px"><thead><tr><th>#</th><th>OLT</th><th>PON port</th><th>PON type</th><th>ONUs</th><th>Max ONUs</th><th>Load</th><th>Splitters</th><th>Empty ports</th></tr></thead><tbody>
        <?php foreach ($rows as $i => $p): $pon = "{$p['frame']}/{$p['slot']}/{$p['port']}"; $pct = round($p['total'] * 100 / $p['max']); ?>
            <tr><td><?php echo $i + 1; ?></td><td><?php echo (int)$p['olt_id'] . ' - ' . e($p['olt_name']); ?></td>
                <td><a href="onus.php?olt=<?php echo (int)$p['olt_id']; ?>&pon=<?php echo $pon; ?>"><?php echo (int)$p['slot'] . '/' . (int)$p['port']; ?></a></td><td>GPON</td>
                <td><?php echo (int)$p['total']; ?></td><td><?php echo (int)$p['max']; ?><br><small class="so-muted">auto (by type)</small></td>
                <td><div class="so-bar" style="width:120px;height:14px;position:relative"><span style="height:14px;width:<?php echo max(1, $pct); ?>%;background:<?php echo $pct >= 90 ? 'var(--red)' : ($pct >= 70 ? 'var(--orange)' : 'var(--green)'); ?>"></span>
                    <small style="position:absolute;top:0;left:0;right:0;text-align:center;font-size:10px;line-height:14px"><?php echo (int)$p['total']; ?> / <?php echo (int)$p['max']; ?> (<?php echo $pct; ?>%)</small></div></td>
                <td><?php echo (int)$p['splitters']; ?></td><td><?php echo $p['empty'] === null ? '-' : (int)$p['empty']; ?></td></tr>
        <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>

<?php elseif ($r === 'splitters'): $far = max(1, (int)($g('far') ?: 300));
    $res = $mgr->onuSplitterCheck(['reason' => $g('reason'), 'olt_id' => (int)$g('olt'), 'board' => $g('board'), 'port_no' => $g('port'), 'zone_id' => (int)$g('zone'), 'sector_id' => (int)$g('sector')], $far);
    if (isset($_GET['csv'])) csv_download('splitter-check', ['Name', 'SN', 'OLT', 'ONU', 'Zone', 'Splitter', 'Problem', 'Distance m'], array_map(function ($u) { return [$u['description'], $u['sn'], $u['olt_name'], onu_place($u), $u['zone_name'], $u['odb_name'], $u['reason'] === 'none' ? 'No splitter' : 'Far from splitter', $u['distance']]; }, $res['rows']));
    $bad = array_filter($mgr->splitterCheck(), function ($d) { return $d['issues']; }); ?>
    <form method="GET" class="so-box" style="display:flex;gap:12px;flex-wrap:wrap;align-items:end"><input type="hidden" name="r" value="splitters">
        <?php echo $sel('reason', 'Reason', '<option value="">Any</option><option value="none"' . ($g('reason') === 'none' ? ' selected' : '') . '>Without a splitter</option><option value="far"' . ($g('reason') === 'far' ? ' selected' : '') . '>Far from the splitter</option>', '170px'); ?>
        <div><label class="so-label">Far above (m)</label><input name="far" type="number" min="1" value="<?php echo $far; ?>" class="so-input" style="width:90px" onchange="this.form.submit()"></div>
        <?php echo $sel('olt', 'OLT', '<option value="">Any</option>' . olt_options($olts, $g('olt'))); ?>
        <?php echo $sel('board', 'Board', $num(range(0, 21), $g('board')), '90px'); ?>
        <?php echo $sel('port', 'Port', $num(range(0, 15), $g('port')), '90px'); ?>
        <?php echo $sel('zone', 'Zone', options($mgr->zones(), $g('zone'), 'Any'), '120px'); ?>
        <?php echo $sel('sector', 'Sector', options($mgr->sectors(), $g('sector'), 'Any'), '120px'); ?>
        <span style="margin-left:auto;display:flex;gap:18px"><?php echo $reset(); ?><button class="so-link-btn" name="csv" value="1"><i class="fa fa-upload"></i> Export to CSV</button></span>
    </form>
    <div class="so-inline" style="gap:12px;margin-bottom:12px">
        <div class="so-stat"><i class="fa fa-chain-broken" style="color:var(--orange)"></i><div><small>ONUs without a Splitter</small><b><?php echo $res['none']; ?></b></div></div>
        <div class="so-stat"><i class="fa fa-arrows-h" style="color:var(--red)"></i><div><small>ONUs more than <?php echo $far; ?> m from their Splitter</small><b><?php echo $res['far']; ?></b></div></div>
    </div>
    <p class="so-muted" style="font-size:13px">This list shows up to 1000 ONUs. An ONU is far from its splitter when both have coordinates and the distance between them is above the limit.</p>
    <div class="so-table-wrap"><table class="so-table" style="font-size:14px"><thead><tr><th>#</th><th>Name</th><th>SN</th><th>OLT</th><th>ONU</th><th>Zone</th><th>Splitter</th><th>Distance to the Splitter (m)</th></tr></thead><tbody>
        <?php if (!$res['rows']) echo '<tr><td colspan="8" class="so-muted">No ONUs found</td></tr>'; ?>
        <?php foreach ($res['rows'] as $i => $u): ?><tr><td><?php echo $i + 1; ?></td><td><a href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description']); ?></a></td><td class="so-mono"><?php echo e($u['sn']); ?></td>
            <td><?php echo (int)$u['olt_id'] . ' - ' . e($u['olt_name']); ?></td><td><?php echo (int)$u['slot'] . '/' . (int)$u['port'] . ':' . (int)$u['ont_id']; ?></td><td><?php echo e($u['zone_name'] ?? '-'); ?></td>
            <td><?php echo e($u['odb_name'] ?? 'None'); ?></td><td style="<?php echo $u['distance'] !== null && $u['distance'] > $far ? 'color:var(--red)' : ''; ?>"><?php echo $u['distance'] ?? '-'; ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php if ($bad): ?>
        <h3 style="font-size:16px;margin-top:22px">Splitters with problems</h3>
        <div class="so-table-wrap"><table class="so-table" style="font-size:14px"><thead><tr><th>Splitter</th><th>Zone</th><th>Ports used</th><th>Problems</th></tr></thead><tbody>
            <?php foreach ($bad as $d): ?><tr><td><a href="onus.php?odb=<?php echo (int)$d['id']; ?>"><?php echo e($d['name']); ?></a></td><td><?php echo e($d['zone_name'] ?? '-'); ?></td>
                <td><?php echo (int)$d['used']; ?> of <?php echo (int)$d['ports']; ?></td><td><?php echo implode('<br>', array_map('e', $d['issues'])); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
    <?php endif; ?>

<?php else: $on = $mgr->autoSyncOn(); $rows = $scanned !== null ? array_values(array_filter($mgr->mismatches(), function ($u) use ($scanned) { return in_array((int)$u['olt_id'], $scanned, true); })) : null; ?>
    <?php echo panel_open('Configuration Mismatch Check', '', 'cog'); ?>
        <p style="margin:0 0 6px">Detect and fix mismatches between Database and OLT.</p>
        <p style="margin:0" class="so-muted">Mismatches can occur when the OLT is restarted without fully saving the running configuration, or when settings are changed directly on the OLT.</p>
    <?php echo panel_close(); ?>
    <?php echo panel_open('Automatically keep the OLT config in sync with SmartOLT', '', 'clock-o'); ?>
        <p style="margin:0 0 8px">Status: <?php echo $on ? badge('Enabled', 'green') : badge('Disabled', 'red'); ?></p>
        <div style="font-size:14px;line-height:1.7"><i class="fa fa-check"></i> Daily automatic scan and compare<br><i class="fa fa-check"></i> Auto-fix: Database config goes back to the OLT config</div>
        <p style="font-size:14px;margin:8px 0"><i class="fa fa-info-circle"></i> What's being synced: ONU VLAN, ONU mode and speed profile (service ports).</p>
        <p style="font-size:14px"><?php if (!$on): ?><span style="color:var(--red)">Auto keep-in-sync is currently disabled for all OLTs.</span><?php endif; ?>
            <?php echo post_btn('auto_sync', ['on' => $on ? '' : '1'], $on ? 'Disable for all OLTs' : 'Enable for all OLTs', $on ? 'grey' : 'blue', 'magic',
                $on ? '' : 'Turn on keep in sync? Every day SmartOLT puts the saved VLAN, mode and speed back on any ONU whose setup on the OLT changed.'); ?></p>
        <p class="so-muted" style="font-size:13px;margin-bottom:0">Enable this if SmartOLT is where you manage your ONUs, so a change made on the OLT by hand is put back. If another system manages ONUs directly on the OLT, keep this disabled.</p>
    <?php echo panel_close(); ?>
    <?php echo panel_open('Manual Check', '', 'search'); ?>
        <p style="margin-top:0">Read the OLTs now and view the current mismatches between SmartOLT and the OLT config.</p>
        <form method="POST" class="so-inline" data-busy="Reading the OLTs and comparing..."><?php echo csrf_field(); ?><input type="hidden" name="do" value="scan">
            <select name="olts[]" multiple class="so-select" style="height:auto;min-height:40px;width:220px"><?php foreach ($olts as $o): ?><option value="<?php echo (int)$o['id']; ?>" selected><?php echo (int)$o['id'] . ' - ' . e($o['name']); ?></option><?php endforeach; ?></select>
            <button class="so-btn so-btn-grey"><i class="fa fa-refresh"></i> Scan &amp; Compare</button></form>
        <?php if ($rows === null): $rows = $mgr->mismatches(); ?>
            <p class="so-muted" style="font-size:13px">Showing the result of the last 5 minute read.</p>
        <?php endif; ?>
        <?php if (!$rows): ?>
            <div style="text-align:center;padding:20px 0" class="so-muted"><i class="fa fa-inbox fa-2x"></i><br>No mismatches. Every ONU on the OLT matches what SmartOLT has saved.</div>
        <?php else: ?>
        <div class="so-table-wrap" style="margin-top:12px"><table class="so-table" style="font-size:14px"><thead><tr><th>ONU</th><th>OLT</th><th>Saved in SmartOLT</th><th>Problem</th><th></th></tr></thead><tbody>
            <?php foreach ($rows as $u): ?><tr><td><a href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description']); ?></a><br><small class="so-mono"><?php echo e($u['sn']); ?></small></td>
                <td><?php echo e($u['olt_name'] . ' ' . onu_place($u)); ?></td>
                <td>VLAN <?php echo $u['vlan'] ? (int)$u['vlan'] : '-'; ?>, <?php echo e($u['speed_name'] ?? 'no speed limit'); ?>, <?php echo e($u['mode'] ?: 'bridge'); ?></td>
                <td><?php echo implode('<br>', array_map('e', $u['issues'])); ?></td>
                <td class="num"><?php echo $u['vlan'] ? post_btn('resync', ['id' => $u['id']], 'Resync', 'blue', 'refresh', 'Put the saved VLAN, mode and speed on the OLT for ' . $u['description'] . '? The customer drops for a few seconds.', 'Resyncing on the OLT...') : '<span class="so-muted" style="font-size:13px">Set its VLAN first</span>'; ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php endif; ?>
    <?php echo panel_close(); ?>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
