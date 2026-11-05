<?php
require_once __DIR__ . '/backend/bootstrap.php';

$f = [
    'olt_id' => (int)($_GET['olt'] ?? 0),
    'user'   => (string)($_GET['user'] ?? ''),
    'action' => isset(ACTION_LABELS[$_GET['action'] ?? '']) ? $_GET['action'] : '',
    'from'   => (string)($_GET['from'] ?? ''),
    'to'     => (string)($_GET['to'] ?? ''),
];
$rows = $mgr->actionsFiltered($f, 500);
$who = function (array $a): string {
    if ($a['source'] === 'api') return 'Billing dashboard';
    if ($a['source'] === 'auto') return 'Auto action';
    if ($a['source'] === 'cron') return 'System';
    return $a['user'] !== '' ? $a['user'] : 'Panel';
};

$pageTitle = 'Events';
include __DIR__ . '/backend/templates/header.php';
?>
<form method="GET" class="so-inline" style="justify-content:center;margin:6px 0 14px">
    <label class="so-label">OLT</label><select name="olt" class="so-select" style="min-width:180px" onchange="this.form.submit()"><option value="">Any</option><?php echo olt_options($mgr->olts(), $f['olt_id'] ?: ''); ?></select>
    <label class="so-label">User</label><select name="user" class="so-select" style="min-width:180px" onchange="this.form.submit()"><option value="">Any</option>
        <?php foreach ($mgr->actionUsers() as $u): ?><option <?php echo $u === $f['user'] ? 'selected' : ''; ?>><?php echo e($u); ?></option><?php endforeach; ?></select>
    <label class="so-label">Action</label><select name="action" class="so-select" style="min-width:180px" onchange="this.form.submit()"><option value="">Any</option>
        <?php foreach (ACTION_LABELS as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $k === $f['action'] ? 'selected' : ''; ?>><?php echo e($l); ?></option><?php endforeach; ?></select>
    <label class="so-label">From</label><input type="date" name="from" value="<?php echo e($f['from']); ?>" class="so-input" onchange="this.form.submit()">
    <label class="so-label">To</label><input type="date" name="to" value="<?php echo e($f['to']); ?>" class="so-input" onchange="this.form.submit()">
</form>
<?php if (!$rows): ?>
    <div class="so-empty">No info available</div>
<?php else: ?>
<div class="so-table-wrap"><table class="so-table">
    <thead><tr><th>Date</th><th>OLT</th><th>User</th><th>Action</th><th>On</th><th>Result</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $a): ?>
        <tr><td style="white-space:nowrap"><?php echo e(when($a['created_at'])); ?></td><td><?php echo e($a['olt_name'] ?? ''); ?></td><td><?php echo e($who($a)); ?></td>
            <td><?php echo e(ACTION_LABELS[$a['action']] ?? $a['action']); ?></td><td class="so-mono" style="font-size:13px"><?php echo e($a['target']); ?></td>
            <td><?php echo $a['ok'] ? badge('Done', 'green') : badge('Failed', 'red') . '<div class="so-help" style="color:var(--red)">' . e($a['message']) . '</div>'; ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
