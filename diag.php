<?php
// Diagnostics: ONUs side by side with their light both ways, distance and when their status last
// changed, so a bad drop or a splitter fault shows up at a glance.
require_once __DIR__ . '/backend/bootstrap.php';

$g = function (string $k) { return trim((string)($_GET[$k] ?? '')); };
$filter = [
    'q' => $g('q'), 'olt_id' => (int)$g('olt'), 'board' => $g('board'), 'port_no' => $g('port'), 'zone_id' => (int)$g('zone'),
    'odb_id' => (int)$g('odb'), 'onu_type_id' => (int)$g('type'), 'sector_id' => (int)$g('sector'), 'vip' => $g('vip'),
    'status' => in_array($g('status'), OltManager::STATUSES, true) ? $g('status') : '',
    'signal' => in_array($g('signal'), ['good', 'warning', 'critical'], true) ? $g('signal') : '',
    'changed_from' => $g('from'), 'changed_to' => $g('to'),
    'sort' => $g('sort') === 'signal' ? 'signal' : 'changed',
];
$rows = $mgr->listOnus($filter, 2000);
if (isset($_GET['export'])) {
    csv_download('diagnostics', ['Status', 'Rx ONU dBm', 'Rx OLT dBm', 'Distance m', 'Name', 'SN', 'Zone', 'Splitter', 'OLT', 'ONU', 'Changed'], array_map(function ($u) {
        return [status_label($u['status_group']), $u['rx_power'], $u['olt_rx_power'], $u['distance_m'], $u['description'], $u['sn'], $u['zone_name'], $u['odb_name'],
            $u['olt_name'], onu_place($u), max((string)$u['last_up_at'], (string)$u['last_down_at'])];
    }, $rows));
}
$olts = $mgr->olts();
$qs = $_GET;
$toggle = function (string $key, string $value, string $inner, string $title) use ($qs): string {
    $q = $qs;
    $on = ($q[$key] ?? '') === $value;
    if ($on) unset($q[$key]); else $q[$key] = $value;
    return '<a href="diag.php?' . e(http_build_query($q)) . '" class="' . ($on ? 'on' : '') . '" title="' . e($title) . '">' . $inner . '</a>';
};
$sel = function (string $name, string $label, string $opts) {
    return '<span class="so-inline"><label class="so-label">' . $label . '</label><select name="' . $name . '" class="so-select" onchange="this.form.submit()">' . $opts . '</select></span>';
};
$num = function (array $nums, string $cur) {
    $h = '<option value="">Any</option>';
    foreach ($nums as $n) $h .= '<option' . ((string)$n === $cur ? ' selected' : '') . '>' . (int)$n . '</option>';
    return $h;
};
$bar = function ($rx) {
    if ($rx === null) return '<span class="so-muted">-</span>';
    $v = (float)$rx;
    $pct = max(5, min(100, (int)round(($v + 32) * 100 / 24)));
    $lvl = strtolower(signal_level($rx));
    $color = $lvl === 'good' ? 'var(--green)' : ($lvl === 'warning' ? 'var(--orange)' : 'var(--red)');
    return '<div style="min-width:70px"><div class="so-bar" style="height:5px;min-width:0"><span style="height:5px;width:' . $pct . '%;background:' . $color . '"></span></div><span class="sig-' . $lvl . '">' . number_format($v, 2) . '</span></div>';
};

$pageTitle = 'Diagnostics';
include __DIR__ . '/backend/templates/header.php';
?>
<form method="GET" id="filters">
    <?php foreach (['status', 'signal', 'sort'] as $k) if (($qs[$k] ?? '') !== '') echo '<input type="hidden" name="' . $k . '" value="' . e($qs[$k]) . '">'; ?>
    <div class="so-inline" style="margin-bottom:10px">
        <span class="so-inline"><label class="so-label">Search</label><input name="q" value="<?php echo e($filter['q']); ?>" class="so-input" style="width:180px" placeholder="SN, MAC, IP, name, address"></span>
        <?php echo $sel('olt', 'OLT', '<option value="">Any</option>' . olt_options($olts, $filter['olt_id'] ?: '')); ?>
        <?php echo $sel('board', 'Board', $num(range(0, 21), $filter['board'])); ?>
        <?php echo $sel('port', 'Port', $num(range(0, 15), $filter['port_no'])); ?>
        <?php echo $sel('zone', 'Zone', options($mgr->zones(), $filter['zone_id'] ?: '', 'Any')); ?>
        <?php echo $sel('odb', 'Splitter', options($mgr->odbs(), $filter['odb_id'] ?: '', 'Any')); ?>
        <?php echo $sel('type', 'ONU type', options($mgr->onuTypes(), $filter['onu_type_id'] ?: '', 'Any')); ?>
    </div>
    <div class="so-inline" style="margin-bottom:10px">
        <?php echo $sel('pon_type', 'PON type', '<option value="">Any</option><option>GPON</option>'); ?>
        <?php echo $sel('sector', 'Sector', options($mgr->sectors(), $filter['sector_id'] ?: '', 'Any')); ?>
        <span class="so-inline"><label class="so-label">VIP</label><select name="vip" class="so-select" onchange="this.form.submit()">
            <option value="">Any</option><option value="1" <?php echo $filter['vip'] === '1' ? 'selected' : ''; ?>>VIP</option><option value="0" <?php echo $filter['vip'] === '0' ? 'selected' : ''; ?>>Not VIP</option></select></span>
        <span class="so-toggles"><?php echo $toggle('status', 'online', '<i class="fa fa-globe st-online"></i>', 'Online') . $toggle('status', 'power_fail', '<i class="fa fa-plug st-power_fail"></i>', 'Power fail')
            . $toggle('status', 'los', '<i class="fa fa-chain-broken st-los"></i>', 'LOS') . $toggle('status', 'other', '<i class="fa fa-globe st-other"></i>', 'Offline')
            . $toggle('status', 'disabled', '<i class="fa fa-ban st-disabled"></i>', 'Disabled'); ?></span>
        <span class="so-toggles"><?php echo $toggle('signal', 'good', '<i class="fa fa-signal sig-good"></i>', 'Good signal') . $toggle('signal', 'warning', '<i class="fa fa-signal sig-warning"></i>', 'Warning')
            . $toggle('signal', 'critical', '<i class="fa fa-signal sig-critical"></i>', 'Critical'); ?></span>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:end;gap:10px;flex-wrap:wrap">
        <span class="so-inline"><label class="so-label">Status changed <i class="fa fa-info-circle" title="When the ONU last went offline or came back"></i></label>
            <input type="date" name="from" value="<?php echo e($g('from')); ?>" class="so-input" onchange="this.form.submit()"><input type="date" name="to" value="<?php echo e($g('to')); ?>" class="so-input" onchange="this.form.submit()"></span>
        <span class="so-inline"><button class="so-btn so-btn-blue"><i class="fa fa-refresh"></i> Refresh</button><button class="so-btn so-btn-blue" name="export" value="1"><i class="fa fa-upload"></i> Export</button></span>
    </div>
</form>
<div class="so-count"><span class="so-dot"></span><span class="so-muted" style="font-weight:400">Read every 5 minutes</span> | 1-<?php echo count($rows); ?> ONUs of <?php echo count($rows); ?> displayed</div>
<div class="so-table-wrap"><table class="so-table" style="font-size:14px">
    <thead><tr><th>Status</th><th><i class="fa fa-signal"></i></th><th><?php echo sort_th('Rx ONU', 'signal', $filter['sort']); ?></th><th>Rx OLT</th><th>Distance</th><th>Name</th><th>SN / MAC</th><th>Zone</th><th>Splitter</th><th>ONU</th><th>Changed</th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="11" class="so-muted">No ONUs match.</td></tr><?php endif; ?>
    <?php foreach ($rows as $u): $changed = max((string)$u['last_up_at'], (string)$u['last_down_at']); $lvl = strtolower(signal_level($u['rx_power'])); ?>
        <tr><td class="center"><?php echo status_icon($u['status_group']); ?><?php echo $u['vip'] ? ' <i class="fa fa-star" style="color:var(--orange)" title="VIP"></i>' : ''; ?></td>
            <td><i class="fa fa-signal sig-<?php echo $lvl ?: 'unknown'; ?>"></i></td>
            <td><?php echo $bar($u['rx_power']); ?></td><td><?php echo $bar($u['olt_rx_power']); ?></td>
            <td><?php echo $u['distance_m'] !== null ? (int)$u['distance_m'] : '-'; ?></td>
            <td><a href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description']); ?></a></td>
            <td class="so-mono"><a href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['sn']); ?></a></td>
            <td><?php echo e($u['zone_name'] ?? '-'); ?></td><td><?php echo e($u['odb_name'] ?? '-'); ?></td>
            <td style="font-size:13px"><?php echo (int)$u['olt_id'] . ' - ' . e($u['olt_name']); ?><br><?php echo e(onu_place($u)); ?></td>
            <td style="white-space:nowrap"><?php echo $changed !== '' ? str_replace(' ', '<br>', e(when($changed))) : '-'; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
