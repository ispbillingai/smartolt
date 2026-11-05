<?php
// Info: everything done on the OLTs, by whom and from where. The dashboard's Info box opens this.
require_once __DIR__ . '/backend/bootstrap.php';

$f = [
    'olt_id' => (int)($_GET['olt'] ?? 0),
    'user'   => (string)($_GET['user'] ?? ''),
    'action' => isset(ACTION_LABELS[$_GET['action'] ?? '']) ? $_GET['action'] : '',
    'q'      => trim((string)($_GET['q'] ?? '')),
    'from'   => (string)($_GET['from'] ?? ''),
    'to'     => (string)($_GET['to'] ?? ''),
];
$rows = $mgr->actionsFiltered($f, 1000);
$who = function (array $a): string {
    if ($a['source'] === 'api') return 'Billing dashboard';
    if ($a['source'] === 'auto') return 'Auto action';
    if ($a['source'] === 'cron') return 'System';
    return $a['user'] !== '' ? $a['user'] : 'Panel';
};
if (isset($_GET['export'])) {
    csv_download('info', ['Action', 'OLT', 'On', 'User', 'IP address', 'Date', 'Result'], array_map(function ($a) use ($who) {
        return [ACTION_LABELS[$a['action']] ?? $a['action'], $a['olt_name'], $a['target'], $who($a), $a['ip'], $a['created_at'], $a['ok'] ? 'Done' : 'Failed: ' . $a['message']];
    }, $rows));
}

$pageTitle = 'Info';
include __DIR__ . '/backend/templates/header.php';
?>
<form method="GET" class="so-inline" style="margin:6px 0 14px">
    <label class="so-label">OLT</label><select name="olt" class="so-select" onchange="this.form.submit()"><option value="">Any</option><?php echo olt_options($mgr->olts(), $f['olt_id'] ?: ''); ?></select>
    <label class="so-label">User</label><select name="user" class="so-select" onchange="this.form.submit()"><option value="">Any</option>
        <?php foreach ($mgr->actionUsers() as $u): ?><option <?php echo $u === $f['user'] ? 'selected' : ''; ?>><?php echo e($u); ?></option><?php endforeach; ?></select>
    <label class="so-label">Action</label><select name="action" class="so-select" onchange="this.form.submit()"><option value="">Any</option>
        <?php foreach (ACTION_LABELS as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $k === $f['action'] ? 'selected' : ''; ?>><?php echo e($l); ?></option><?php endforeach; ?></select>
    <label class="so-label">Search</label><input name="q" value="<?php echo e($f['q']); ?>" class="so-input" style="width:170px" placeholder="ONU SN/MAC or Action">
    <label class="so-label">From</label><input type="date" name="from" value="<?php echo e($f['from']); ?>" class="so-input" onchange="this.form.submit()">
    <label class="so-label">To</label><input type="date" name="to" value="<?php echo e($f['to']); ?>" class="so-input" onchange="this.form.submit()">
    <button class="so-btn so-btn-green" name="export" value="1"><i class="fa fa-upload"></i> Export</button>
</form>
<?php if (!$rows): ?>
    <div class="so-empty">No info available</div>
<?php else: ?>
<div class="so-table-wrap"><table class="so-table" style="font-size:14px">
    <thead><tr><th>Action</th><th>OLT</th><th>ONU</th><th>User</th><th>IP address</th><th>Date</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
        <tr><td><?php echo e(action_text($a)); ?><?php if (!$a['ok']) echo '<div class="so-help" style="color:var(--red)">' . e($a['message']) . '</div>'; ?></td>
            <td><?php echo e($a['olt_name'] !== null ? $a['olt_name'] : ''); ?></td>
            <td class="so-mono" style="font-size:13px"><?php echo strpos($a['action'], 'onu.') === 0 ? e($a['target']) : ''; ?></td>
            <td><?php echo e($who($a)); ?></td><td><?php echo e($a['ip']); ?></td>
            <td style="white-space:nowrap"><?php echo e(AppTime::format('d-M-Y H:i', $a['created_at'])); ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
