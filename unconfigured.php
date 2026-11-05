<?php
require_once __DIR__ . '/backend/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        switch ($_POST['do'] ?? '') {
            case 'auto_toggle':
                $on = !$mgr->autoActionsOn();
                $mgr->setAutoActionsOn($on);
                back('unconfigured.php', 'success', $on ? 'Auto actions started.' : 'Auto actions stopped. Nothing is authorized automatically until you start them again.');
            case 'later':
                $mgr->savePending($_POST);
                back('unconfigured.php', 'success', 'Saved. The ONU is authorized with these details as soon as an OLT sees it.');
            case 'later_delete':
                $mgr->deletePending((int)($_POST['id'] ?? 0));
                back('unconfigured.php', 'success', 'Removed from the list.');
        }
    } catch (InvalidArgumentException $e) {
        back('unconfigured.php', 'error', $e->getMessage());
    }
}

$olts = $mgr->olts();
$oltFilter = (int)($_GET['olt'] ?? 0);
$ponFilter = trim((string)($_GET['pon'] ?? ''));
$shown = array_values(array_filter($olts, function ($o) use ($oltFilter) { return !$oltFilter || (int)$o['id'] === $oltFilter; }));

// Refresh asks the OLTs now (and authorizes what auto actions or the later list cover); otherwise the
// list is what the last 5 minute read saw, so the page opens at once.
$errors = [];
$autoDone = 0;
if (isset($_GET['refresh'])) {
    foreach ($shown as $o) {
        try {
            $mgr->autofind((int)$o['id']);
            $r = $mgr->runAuto($mgr->olt((int)$o['id']));
            $autoDone += $r['done'];
            foreach ($r['failed'] as $f) $errors[] = 'Auto action not done for ' . $f;
        } catch (Throwable $e) {
            $errors[] = $o['name'] . ': ' . $e->getMessage();
        }
    }
}
$waiting = $mgr->waiting();
$ports = [];
foreach ($waiting as $w) foreach ($w['onus'] as $u) $ports["{$u['frame']}/{$u['slot']}/{$u['port']}"] = true;
foreach ($olts as $o) foreach ($mgr->ports((int)$o['id']) as $p) $ports["{$p['frame']}/{$p['slot']}/{$p['port']}"] = true;
ksort($ports, SORT_NATURAL);
$pending = $mgr->pendingOnus();
$pendingSn = array_column($pending, null, 'sn');
$known = $mgr->knownSerials();
$autoOn = $mgr->autoActionsOn();
$rules = $mgr->autoActions();

$pageTitle = 'Unconfigured';
include __DIR__ . '/backend/templates/header.php';
?>
<form method="GET" class="so-inline" style="margin-bottom:12px">
    <label class="so-label">OLT</label>
    <select name="olt" class="so-select" style="min-width:200px"><option value="">Any</option><?php echo olt_options($olts, $oltFilter ?: ''); ?></select>
    <label class="so-label">PON</label>
    <select name="pon" class="so-select" style="min-width:140px"><option value="">Any</option>
        <?php foreach (array_keys($ports) as $p): ?><option <?php echo $p === $ponFilter ? 'selected' : ''; ?>><?php echo e($p); ?></option><?php endforeach; ?></select>
    <button class="so-btn so-btn-green" name="refresh" value="1" onclick="document.getElementById('soBusyText').textContent='Asking the OLT which ONUs are waiting...';document.getElementById('soBusy').classList.add('show')">Refresh</button>
</form>
<?php echo flash_messages(); ?>
<?php foreach ($errors as $err) echo alert($err, 'err'); ?>
<?php if ($autoDone) echo alert("Auto actions authorized {$autoDone} ONU" . ($autoDone > 1 ? 's' : '') . '.', 'ok'); ?>

<details class="so-panel" open style="padding:0">
    <summary class="so-panel-h" style="cursor:pointer;list-style:none"><span>Auto actions <?php echo $autoOn ? '' : badge('Stopped', 'red'); ?></span><i class="fa fa-chevron-right"></i></summary>
    <div class="so-panel-b so-inline" style="justify-content:space-between">
        <span class="so-inline">
            <?php echo btn('Configure actions', 'blue', 'auto_actions.php'); ?>
            <?php echo btn('Task history', 'blue', 'tasks.php', 'history'); ?>
            <span class="so-muted" style="font-size:13px"><?php echo count(array_filter($rules, function ($r) { return (int)$r['enabled'] === 1; })); ?> active, run every 5 minutes</span>
        </span>
        <?php echo post_btn('auto_toggle', [], $autoOn ? 'Stop auto actions' : 'Start auto actions', $autoOn ? 'dark' : 'green', '',
            $autoOn ? 'Stop all auto actions? Nothing is authorized automatically until you start them again.' : ''); ?>
    </div>
</details>

<?php if (!$olts): ?>
    <div class="so-box"><span class="so-empty">No OLT yet.</span> <a href="olts.php?add=1">Add OLT</a></div>
<?php endif; ?>
<?php foreach ($shown as $o): $w = $waiting[(int)$o['id']] ?? null;
    $list = array_values(array_filter($w['onus'] ?? [], function ($u) use ($ponFilter) { return $ponFilter === '' || "{$u['frame']}/{$u['slot']}/{$u['port']}" === $ponFilter; })); ?>
    <div class="so-box">
        <div style="font-size:19px"><?php echo (int)$o['id'] . ' - ' . e($o['name']); ?>
            <a href="unconfigured.php?olt=<?php echo (int)$o['id']; ?>&refresh=1" title="Scan this OLT now" onclick="document.getElementById('soBusy').classList.add('show')"><i class="fa fa-refresh"></i></a></div>
        <div class="so-muted" style="font-size:14px">Last scan: <?php echo $w ? ago($w['at']) : 'never'; ?></div>
        <?php if ($o['status'] === 'error'): ?><div class="so-alert a-err" style="margin:8px 0">Cannot connect: <?php echo e($o['last_error']); ?></div><?php endif; ?>
        <?php if (!$list): ?>
            <div class="so-empty">No unconfigured ONUs found</div>
        <?php else: ?>
            <div class="so-table-wrap" style="margin-top:8px"><table class="so-table">
                <thead><tr><th>PON type</th><th>Board</th><th>Port</th><th>PON description</th><th>SN</th><th>Type</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($list as $u): $p = $pendingSn[$u['sn']] ?? null; ?>
                    <tr>
                        <td>GPON</td><td><?php echo (int)$u['slot']; ?></td><td><?php echo (int)$u['port']; ?></td>
                        <td class="so-muted"><?php echo e("{$u['frame']}/{$u['slot']}/{$u['port']}"); ?></td>
                        <td class="so-mono"><?php echo e($u['sn']); ?>
                            <?php if (isset($known[$u['sn']])) echo ' ' . badge('Authorized before', 'orange'); ?>
                            <?php if ($p) echo ' ' . badge('Added for later', 'blue') . ($p['last_error'] !== '' ? '<div class="so-help" style="color:var(--red)">' . e($p['last_error']) . '</div>' : ''); ?></td>
                        <td><?php echo e($u['equipment'] ?: '-'); ?></td>
                        <td class="num"><?php echo btn('Authorize', 'blue', 'authorize.php?olt=' . (int)$o['id'] . '&sn=' . urlencode($u['sn'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
        <hr class="so-hr" style="margin-bottom:0">
    </div>
<?php endforeach; ?>

<?php if ($pending): ?>
<?php echo panel_open('Added for later authorization', '', 'clock-o'); ?>
    <div class="so-table-wrap"><table class="so-table">
        <thead><tr><th>SN</th><th>Name</th><th>OLT</th><th>Preset</th><th>Zone</th><th>Splitter</th><th>Added</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pending as $p): ?>
            <tr><td class="so-mono"><?php echo e($p['sn']); ?></td><td><?php echo e($p['description'] ?: '-'); ?></td><td><?php echo e($p['olt_name'] ?? 'Any'); ?></td>
                <td><?php echo e($p['preset_name'] ?? 'OLT defaults'); ?></td><td><?php echo e($p['zone_name'] ?? '-'); ?></td>
                <td><?php echo e(($p['odb_name'] ?? '-') . ($p['odb_port'] ? ' port ' . $p['odb_port'] : '')); ?></td><td><?php echo ago($p['created_at']); ?>
                    <?php if ($p['last_error'] !== '') echo '<div class="so-help" style="color:var(--red)">' . e($p['last_error']) . '</div>'; ?></td>
                <td class="num"><?php echo post_btn('later_delete', ['id' => $p['id']], '', 'red', 'trash', 'Remove ' . $p['sn'] . ' from the list?'); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php echo panel_close(); ?>
<?php endif; ?>

<p><?php echo btn('Authorization Presets', 'blue', 'catalog.php?k=presets', 'list'); ?></p>
<p class="so-muted" style="font-size:14px;margin-top:-6px"><i class="fa fa-info-circle"></i> Manage presets for quick ONU authorization</p>
<hr class="so-hr">
<a href="later.php" class="so-btn so-btn-green">Add ONU for later authorization</a>

<?php include __DIR__ . '/backend/templates/footer.php'; ?>
