<?php
require_once __DIR__ . '/backend/bootstrap.php';
require_once __DIR__ . '/backend/lib/ConfigBackups.php';

$backups = new ConfigBackups($mgr);
$oltId = max(0, (int)($_POST['olt_id'] ?? $_GET['olt'] ?? 0));
$self = 'backups.php' . ($oltId ? '?olt=' . $oltId : '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        switch ($_POST['do'] ?? '') {
            case 'schedule':
                $backups->schedule($oltId, (int)($_POST['hours'] ?? 0));
                back($self, 'success', 'Backup schedule saved. The polling task runs scheduled backups.');
            case 'capture':
                $backups->capture($oltId);
                back($self, 'success', 'Configuration backup created.');
            case 'download':
                $file = $backups->download((int)($_POST['id'] ?? 0));
                header('Content-Type: text/plain; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $file['filename'] . '"');
                header('Cache-Control: no-store');
                header('X-Content-Type-Options: nosniff');
                echo $file['content'];
                exit;
            case 'delete':
                $backups->delete((int)($_POST['id'] ?? 0));
                back($self, 'success', 'Configuration backup removed.');
        }
    } catch (Throwable $e) {
        back($self, 'error', $e->getMessage());
    }
}
$rows = $backups->listing($oltId);
$olts = $mgr->olts();
$pageTitle = 'Configuration backups';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title('Configuration backups', 'Capture and download the running configuration of an OLT. Backups are encrypted in this site’s database.'); ?>
<?php echo flash_messages(); ?>
<div class="so-box so-inline" style="justify-content:space-between;margin-bottom:16px">
    <form method="GET" class="so-inline">
        <label class="so-label">Show OLT</label>
        <select name="olt" class="so-select" onchange="this.form.submit()"><?php echo olt_options($olts, $oltId ?: '', 'All OLTs'); ?></select>
    </form>
    <?php if ($olts): ?>
    <form method="POST" class="so-inline" data-busy="Reading the OLT configuration…">
        <?php echo csrf_field(); ?><input type="hidden" name="do" value="capture">
        <label class="so-label">Capture OLT</label>
        <select name="olt_id" class="so-select" required><?php echo olt_options($olts, $oltId ?: $olts[0]['id']); ?></select>
        <button class="so-btn so-btn-green"><i class="fa fa-download"></i> Create backup</button>
    </form>
    <?php endif; ?>
</div>
<p class="so-muted">Downloads include the OLT configuration and may contain network credentials. To keep running changes after an OLT reboot, use Save config separately. Restore is performed through the OLT’s own administration tools.</p>
<?php if ($olts): ?>
<?php echo panel_open('Automatic backups'); ?>
<div class="so-table-wrap"><table class="so-table"><thead><tr><th>OLT</th><th>Schedule</th><th>Last successful backup</th><th>Last error</th></tr></thead><tbody>
<?php foreach ($olts as $device): ?><tr><td><?php echo e($device['name']); ?></td><td>
<form method="POST" class="so-inline"><?php echo csrf_field(); ?><input type="hidden" name="do" value="schedule"><input type="hidden" name="olt_id" value="<?php echo (int)$device['id']; ?>">
<select name="hours" class="so-select" aria-label="Backup interval for <?php echo e($device['name']); ?>"><?php foreach ([0 => 'Disabled', 6 => 'Every 6 hours', 12 => 'Every 12 hours', 24 => 'Daily', 168 => 'Weekly'] as $hours => $label): ?><option value="<?php echo $hours; ?>" <?php echo (int)$device['backup_interval_hours'] === $hours ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select><button class="so-btn so-btn-blue">Save schedule</button></form>
</td><td><?php echo $device['last_backup_at'] ? e(when($device['last_backup_at'])) : 'Never'; ?></td><td><?php echo e($device['backup_error']); ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php echo panel_close(); endif; ?>
<?php if (!$rows): ?><div class="so-empty">No configuration backups yet.</div><?php else: ?>
<div class="so-table-wrap"><table class="so-table">
    <thead><tr><th>OLT</th><th>Captured</th><th>By</th><th>Size</th><th>SHA-256</th><th>Actions</th></tr></thead>
    <tbody><?php foreach ($rows as $row): ?>
        <tr><td><a href="olt.php?id=<?php echo (int)$row['olt_id']; ?>"><?php echo e($row['olt_name']); ?></a></td>
            <td><?php echo e(when($row['created_at'])); ?></td><td><?php echo e($row['created_by'] ?: 'System'); ?></td>
            <td><?php echo number_format((int)$row['bytes']); ?> bytes</td>
            <td class="so-mono" title="<?php echo e($row['sha256']); ?>"><?php echo e(substr($row['sha256'], 0, 16)); ?>…</td>
            <td><?php echo post_btn('download', ['id' => $row['id']], 'Download', 'blue', 'download') . ' '
                . post_btn('delete', ['id' => $row['id']], 'Delete', 'red', 'trash', 'Delete this saved configuration backup?'); ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
