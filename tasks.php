<?php
require_once __DIR__ . '/backend/bootstrap.php';

$f = ['tasks' => 1, 'olt_id' => (int)($_GET['olt'] ?? 0), 'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? '')];
$rows = $mgr->actionsFiltered($f, 500);

$pageTitle = 'Task history';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title('Task history', 'What auto actions did, bulk actions on many ONUs and OLT configuration saves.',
    btn('Auto actions', 'blue', 'auto_actions.php', 'magic') . btn('Bulk actions', 'grey', 'onus.php?select=1', 'list-ul')); ?>
<form method="GET" class="so-inline" style="margin-bottom:14px">
    <label class="so-label">OLT</label><select name="olt" class="so-select" onchange="this.form.submit()"><option value="">Any</option><?php echo olt_options($mgr->olts(), $f['olt_id'] ?: ''); ?></select>
    <label class="so-label">From</label><input type="date" name="from" value="<?php echo e($f['from']); ?>" class="so-input" onchange="this.form.submit()">
    <label class="so-label">To</label><input type="date" name="to" value="<?php echo e($f['to']); ?>" class="so-input" onchange="this.form.submit()">
</form>
<?php if (!$rows): ?><div class="so-empty">No tasks yet</div><?php else: ?>
<div class="so-table-wrap"><table class="so-table">
    <thead><tr><th>Date</th><th>OLT</th><th>Task</th><th>On</th><th>By</th><th>Result</th></tr></thead>
    <tbody><?php foreach ($rows as $a): ?>
        <tr><td style="white-space:nowrap"><?php echo e(when($a['created_at'])); ?></td><td><?php echo e($a['olt_name'] ?? ''); ?></td>
            <td><?php echo e(ACTION_LABELS[$a['action']] ?? $a['action']); ?></td><td class="so-mono" style="font-size:13px"><?php echo e($a['target']); ?></td>
            <td><?php echo $a['source'] === 'auto' ? 'Auto action' : e($a['user'] ?: 'Panel'); ?></td>
            <td><?php echo $a['ok'] ? badge('Done', 'green') : badge('Failed', 'red') . '<div class="so-help" style="color:var(--red)">' . e($a['message']) . '</div>'; ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
