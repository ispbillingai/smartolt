<?php
require_once __DIR__ . '/backend/bootstrap.php';

$g = function (string $k) { return trim((string)($_GET[$k] ?? '')); };
$filter = [
    'q' => $g('q'), 'olt_id' => (int)$g('olt'), 'board' => $g('board'), 'port_no' => $g('port'), 'zone_id' => (int)$g('zone'),
    'odb_id' => (int)$g('odb'), 'vlan' => (int)$g('vlan'), 'onu_type_id' => (int)$g('type'), 'speed_profile_id' => (int)$g('speed'),
    'sector_id' => (int)$g('sector'), 'pon' => $g('pon'), 'mode' => $g('mode'),
    'status' => in_array($g('status'), OltManager::STATUSES, true) ? $g('status') : '',
    'signal' => in_array($g('signal'), ['good', 'warning', 'critical', 'weak'], true) ? $g('signal') : '',
    'sort' => in_array($g('sort'), ['auth_asc', 'auth_desc', 'name', 'signal'], true) ? $g('sort') : 'auth_desc',
];
$selectMode = $g('select') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $return = 'onus.php' . (($_POST['return'] ?? '') !== '' ? '?' . preg_replace('/[^\w=&%.\/+-]/', '', $_POST['return']) : '');
    try {
        if (($_POST['do'] ?? '') === 'bulk') {
            $action = (string)($_POST['bulk_action'] ?? '');
            $r = $mgr->bulk((array)($_POST['ids'] ?? []), $action, (int)($_POST['bulk_speed'] ?? 0));
            $verb = ['reboot' => 'Rebooted', 'disable' => 'Disabled', 'enable' => 'Enabled', 'speed' => 'New speed set on'][$action];
            if ($r['failed']) {
                flash('error', count($r['failed']) . ' not done: ' . implode('; ', array_slice($r['failed'], 0, 5)) . (count($r['failed']) > 5 ? '; and more, see Events.' : ''));
            }
            back($return, 'success', "{$verb} {$r['done']} ONU" . ($r['done'] === 1 ? '' : 's') . '.');
        }
    } catch (Throwable $e) {
        back($return, 'error', $e->getMessage());
    }
}

$all = $mgr->listOnus($filter);
$per = 100;
$page = max(1, (int)$g('page'));
$onus = array_slice($all, ($page - 1) * $per, $per);
$olts = $mgr->olts();
$speeds = $mgr->speedProfiles();
$boards = [];
foreach ($olts as $o) foreach (json_decode((string)$o['boards_json'], true) ?: [] as $b) if ($b['gpon']) $boards[(int)$b['slot']] = true;
ksort($boards);
$qs = $_GET;
unset($qs['page']);
$returnQs = http_build_query($qs);

/** Link that sets or clears one filter, keeping the rest. */
$toggle = function (string $key, string $value, string $inner, string $title) use ($qs): string {
    $q = $qs;
    $on = ($q[$key] ?? '') === $value;
    if ($on) unset($q[$key]); else $q[$key] = $value;
    return '<a href="onus.php?' . e(http_build_query($q)) . '" class="' . ($on ? 'on' : '') . '" title="' . e($title) . '">' . $inner . '</a>';
};
$sel = function (string $name, string $label, string $opts) {
    return '<span class="so-inline"><label class="so-label">' . $label . '</label><select name="' . $name . '" class="so-select" onchange="this.form.submit()">' . $opts . '</select></span>';
};
$numOpts = function (array $nums, string $cur) {
    $h = '<option value="">Any</option>';
    foreach ($nums as $n) $h .= '<option' . ((string)$n === $cur ? ' selected' : '') . '>' . (int)$n . '</option>';
    return $h;
};

$pageTitle = 'Configured';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo flash_messages(); ?>
<form method="GET" id="filters">
    <?php if ($selectMode): ?><input type="hidden" name="select" value="1"><?php endif; ?>
    <?php foreach (['status', 'signal', 'mode', 'sort'] as $k) if ($filter[$k] !== '') echo '<input type="hidden" name="' . $k . '" value="' . e($filter[$k]) . '">'; ?>
    <div class="so-inline" style="margin-bottom:10px">
        <span class="so-inline"><label class="so-label">Search</label><input name="q" value="<?php echo e($filter['q']); ?>" class="so-input" style="width:190px" placeholder="SN, name, external ID, address"></span>
        <?php echo $sel('olt', 'OLT', '<option value="">Any</option>' . olt_options($olts, $filter['olt_id'] ?: '')); ?>
        <?php echo $sel('board', 'Board', $numOpts(array_keys($boards), $filter['board'])); ?>
        <?php echo $sel('port', 'Port', $numOpts(range(0, 15), $filter['port_no'])); ?>
        <?php echo $sel('zone', 'Zone', options($mgr->zones(), $filter['zone_id'] ?: '', 'Any')); ?>
        <?php echo $sel('odb', 'Splitter', options($mgr->odbs(), $filter['odb_id'] ?: '', 'Any')); ?>
        <span class="so-inline"><label class="so-label">VLAN</label><input name="vlan" type="number" min="1" max="4094" value="<?php echo $filter['vlan'] ?: ''; ?>" class="so-input" style="width:90px" placeholder="Any"></span>
    </div>
    <div class="so-inline" style="margin-bottom:6px">
        <?php echo $sel('type', 'ONU type', options($mgr->onuTypes(), $filter['onu_type_id'] ?: '', 'Any')); ?>
        <?php echo $sel('speed', 'Profile', options($speeds, $filter['speed_profile_id'] ?: '', 'Any')); ?>
        <?php echo $sel('pon_type', 'PON type', '<option value="">Any</option><option>GPON</option>'); ?>
        <?php echo $sel('sector', 'Sector', options($mgr->sectors(), $filter['sector_id'] ?: '', 'Any')); ?>
        <span class="so-toggles">
            <?php echo $toggle('status', 'online', '<i class="fa fa-globe st-online"></i>', 'Online')
                . $toggle('status', 'power_fail', '<i class="fa fa-plug st-power_fail"></i>', 'Power fail')
                . $toggle('status', 'los', '<i class="fa fa-chain-broken st-los"></i>', 'Signal loss (LOS)')
                . $toggle('status', 'other', '<i class="fa fa-globe st-other"></i>', 'Offline, cause not known')
                . $toggle('status', 'disabled', '<i class="fa fa-ban st-disabled"></i>', 'Disabled'); ?>
        </span>
        <span class="so-toggles">
            <?php echo $toggle('signal', 'good', '<i class="fa fa-signal sig-good"></i>', 'Good signal')
                . $toggle('signal', 'warning', '<i class="fa fa-signal sig-warning"></i>', 'Signal warning')
                . $toggle('signal', 'critical', '<i class="fa fa-signal sig-critical"></i>', 'Signal critical'); ?>
        </span>
        <span class="so-toggles"><?php echo $toggle('mode', 'bridge', 'B', 'Bridge mode') . $toggle('mode', 'router', 'R', 'Router mode'); ?></span>
        <span class="so-toggles"><?php echo $toggle('select', '1', '<i class="fa fa-list-ul"></i>', 'Select ONUs for bulk actions'); ?></span>
        <button class="so-btn so-btn-blue so-btn-sm hidden" id="applyFilters">Filter</button>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center">
        <span></span>
        <a href="#" id="moreFilters" style="font-size:14px"><i class="fa fa-chevron-down"></i> More filters</a>
        <span style="font-size:14px"><a href="reports.php?r=export&<?php echo e($returnQs); ?>">Import / Export</a>
            <?php if ($returnQs !== '' && $returnQs !== 'select=1'): ?> &nbsp;<a href="onus.php" style="color:var(--text)"><i class="fa fa-times"></i> Reset filters</a><?php endif; ?></span>
    </div>
    <div id="more" class="so-inline <?php echo $filter['pon'] === '' ? 'hidden' : ''; ?>" style="margin:10px 0">
        <span class="so-inline"><label class="so-label">PON port</label><input name="pon" value="<?php echo e($filter['pon']); ?>" class="so-input" style="width:120px" placeholder="0/1/0"></span>
        <button class="so-btn so-btn-blue">Filter</button>
    </div>
</form>

<?php if ($selectMode): ?>
<form method="POST" id="bulkForm" data-busy="Working through the ticked ONUs on the OLT..." data-confirm="Apply this to the ticked ONUs?">
    <?php echo csrf_field(); ?><input type="hidden" name="do" value="bulk"><input type="hidden" name="return" value="<?php echo e($returnQs); ?>">
    <div class="so-box so-inline">
        <b><span id="bulkCount">0</span> ticked</b>
        <select name="bulk_action" id="bulkAction" class="so-select"><option value="reboot">Reboot</option><option value="disable">Disable</option><option value="enable">Enable</option><option value="speed">Set speed profile</option></select>
        <select name="bulk_speed" id="bulkSpeed" class="so-select hidden"><?php echo options($speeds, '', 'Choose speed'); ?></select>
        <button class="so-btn so-btn-orange">Apply</button>
        <span class="so-muted" style="font-size:13px">Tick ONUs in the list. Disable cuts their internet until enabled.</span>
    </div>
</form>
<?php endif; ?>

<div class="so-count"><?php echo $all ? (($page - 1) * $per + 1) . '-' . (($page - 1) * $per + count($onus)) : 0; ?> ONUs of <?php echo count($all); ?> displayed</div>
<div class="so-table-wrap">
<table class="so-table">
    <thead><tr>
        <?php if ($selectMode): ?><th><input type="checkbox" id="tickAll" aria-label="Tick all"></th><?php endif; ?>
        <th>Status</th><th>View</th><th><?php echo sort_th('Name', 'name', $filter['sort']); ?></th><th>SN / MAC</th><th>ONU</th><th>Zone</th><th>Splitter</th>
        <th><?php echo sort_th('Signal', 'signal', $filter['sort']); ?></th><th>B/R</th><th>VLAN</th><th>VoIP</th><th>TV</th><th>Type</th>
        <th>Date<br><a href="?<?php echo e(http_build_query(array_merge($qs, ['sort' => $filter['sort'] === 'auth_desc' ? 'auth_asc' : 'auth_desc']))); ?>" class="so-badge" style="font-weight:400">Auth <span class="so-caret"></span></a></th>
    </tr></thead>
    <tbody>
    <?php if (!$onus): ?>
        <tr><td colspan="16" class="so-muted" style="padding:24px"><?php echo $olts ? 'No ONUs match.' : 'Add your OLT first under Settings, OLTs.'; ?></td></tr>
    <?php endif; ?>
    <?php foreach ($onus as $u): ?>
        <tr>
            <?php if ($selectMode): ?><td><input type="checkbox" class="tick" name="ids[]" value="<?php echo (int)$u['id']; ?>" form="bulkForm" aria-label="Tick <?php echo e($u['description']); ?>"></td><?php endif; ?>
            <td class="center"><?php echo status_icon($u['status_group']); ?></td>
            <td><?php echo btn('View', 'blue', 'onu.php?id=' . (int)$u['id']); ?></td>
            <td><?php echo e($u['description']); ?></td>
            <td class="so-mono"><?php echo e($u['sn']); ?></td>
            <td style="font-size:14px"><?php echo (int)$u['olt_id'] . ' - ' . e($u['olt_name']); ?><br><?php echo e(onu_place($u)); ?></td>
            <td><?php echo e($u['zone_name'] ?? ''); ?></td>
            <td class="center"><?php echo $u['odb_name'] !== null ? '<a href="onus.php?odb=' . (int)$u['odb_id'] . '">' . e($u['odb_name']) . '</a>' : '<span class="so-muted">None</span>'; ?>
                <?php if ($u['odb_port']): ?><br><small><?php echo (int)$u['odb_port']; ?></small><?php endif; ?></td>
            <td class="center"><?php echo signal_html($u['rx_power']); ?></td>
            <td><?php echo $u['mode'] ? badge($u['mode'] === 'router' ? 'Router' : 'Bridge', 'blue') : ''; ?></td>
            <td><?php echo $u['vlan'] ? (int)$u['vlan'] : ''; ?></td>
            <td></td><td></td>
            <td><?php echo e($u['type_name'] ?? $u['model']); ?></td>
            <td style="font-size:14px"><?php echo $u['authorized_at'] ? str_replace(' ', '<br>', e(when($u['authorized_at']))) : '<span class="so-muted">Before SmartOLT</span>'; ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php echo pager(count($all), $page, $per); ?>
<script>
(function () {
    document.getElementById('moreFilters').addEventListener('click', function (e) { e.preventDefault(); document.getElementById('more').classList.toggle('hidden'); });
    document.querySelectorAll('#filters input[name=q], #filters input[name=vlan]').forEach(function (i) {
        i.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); document.getElementById('filters').submit(); } });
    });
    var form = document.getElementById('bulkForm');
    if (!form) return;
    var ticks = Array.prototype.slice.call(document.querySelectorAll('.tick')), all = document.getElementById('tickAll');
    var action = document.getElementById('bulkAction'), speed = document.getElementById('bulkSpeed');
    function count() { document.getElementById('bulkCount').textContent = ticks.filter(function (t) { return t.checked; }).length; }
    ticks.forEach(function (t) { t.addEventListener('change', count); });
    all.addEventListener('change', function () { ticks.forEach(function (t) { t.checked = all.checked; }); count(); });
    action.addEventListener('change', function () { speed.classList.toggle('hidden', action.value !== 'speed'); });
})();
</script>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
