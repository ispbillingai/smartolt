<?php
require_once __DIR__ . '/backend/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        switch ($_POST['do'] ?? '') {
            case 'save':
                $mgr->saveAutoAction($_POST);
                back('auto_actions.php', 'success', 'Auto action saved. It runs on the next read of the OLT, within 5 minutes.');
            case 'toggle':
                $a = null;
                foreach ($mgr->autoActions() as $r) if ((int)$r['id'] === (int)$_POST['id']) $a = $r;
                if ($a) $mgr->saveAutoAction(['id' => $a['id'], 'olt_id' => $a['olt_id'], 'pon' => $a['pon'], 'onu_type_id' => $a['onu_type_id'], 'preset_id' => $a['preset_id'], 'enabled' => (int)$a['enabled'] ? '' : 1]);
                back('auto_actions.php', 'success', 'Saved.');
            case 'delete':
                $mgr->deleteAutoAction((int)($_POST['id'] ?? 0));
                back('auto_actions.php', 'success', 'Auto action removed.');
            case 'auto_toggle':
                $mgr->setAutoActionsOn(!$mgr->autoActionsOn());
                back('auto_actions.php', 'success', $mgr->autoActionsOn() ? 'Auto actions started.' : 'Auto actions stopped.');
        }
    } catch (InvalidArgumentException $e) {
        back('auto_actions.php', 'error', $e->getMessage());
    }
}

$rules = $mgr->autoActions();
$presets = $mgr->presets();
$on = $mgr->autoActionsOn();

$pageTitle = 'Auto actions';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title('Auto actions', 'Authorize new ONUs by themselves: when an ONU that matches a rule waits on an OLT, SmartOLT authorizes it with the rule\'s preset at the next read, within 5 minutes. The first matching rule wins.',
    ($presets ? '<button type="button" class="so-btn so-btn-green" data-open="ruleModal"><i class="fa fa-plus"></i> Add auto action</button>' : '')
    . post_btn('auto_toggle', [], $on ? 'Stop auto actions' : 'Start auto actions', $on ? 'dark' : 'green')
    . btn('Task history', 'grey', 'tasks.php', 'history')); ?>
<?php echo flash_messages(); ?>
<?php if (!$on) echo alert('Auto actions are stopped. No ONU is authorized automatically until you start them.', 'warn'); ?>
<?php if (!$presets) echo alert('Make a preset first under Settings, Presets. An auto action authorizes ONUs with a preset.', 'info'); ?>

<?php if (!$rules): ?><div class="so-empty">No auto actions found</div><?php else: ?>
<div class="so-table-wrap"><table class="so-table">
    <thead><tr><th>#</th><th>OLT</th><th>PON port</th><th>ONU type</th><th>Authorize with preset</th><th>Status</th><th class="center">Action</th></tr></thead>
    <tbody><?php foreach ($rules as $i => $r): ?>
        <tr><td><?php echo $i + 1; ?></td><td><?php echo e($r['olt_name'] ?? 'Any'); ?></td><td><?php echo e($r['pon'] ?: 'Any'); ?></td><td><?php echo e($r['type_name'] ?? 'Any'); ?></td>
            <td><?php echo e($r['preset_name']); ?></td><td><?php echo (int)$r['enabled'] ? badge('Active', 'green') : badge('Paused', 'grey'); ?></td>
            <td class="center"><?php echo post_btn('toggle', ['id' => $r['id']], (int)$r['enabled'] ? 'Pause' : 'Resume', 'grey') . ' '
                . post_btn('delete', ['id' => $r['id']], '', 'red', 'trash', 'Remove this auto action?'); ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>

<div class="so-modal" id="ruleModal">
    <form method="POST" class="so-modal-box">
        <div class="so-modal-h">Add auto action <button type="button" data-close>&times;</button></div>
        <div class="so-modal-b">
            <?php echo csrf_field(); ?><input type="hidden" name="do" value="save"><input type="hidden" name="enabled" value="1">
            <p class="so-muted" style="margin-top:0">When an ONU waits for authorization and matches all of these:</p>
            <div class="so-field"><label class="so-label">OLT</label><select name="olt_id" class="so-select"><option value="">Any OLT</option><?php echo olt_options($mgr->olts(), ''); ?></select></div>
            <div class="so-field"><label class="so-label">PON port</label><input name="pon" class="so-input" placeholder="Any, or for example 0/1/0"></div>
            <div class="so-field"><label class="so-label">ONU type</label><select name="onu_type_id" class="so-select"><?php echo options($mgr->onuTypes(), '', 'Any type'); ?></select></div>
            <div class="so-field"><label class="so-label">Authorize it with preset</label><select name="preset_id" class="so-select" required><?php echo options($presets, ''); ?></select></div>
        </div>
        <div class="so-modal-f"><button type="button" class="so-btn so-btn-dark" data-close>Cancel</button><button class="so-btn so-btn-green">Save</button></div>
    </form>
</div>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
