<?php
require_once __DIR__ . '/backend/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        switch ($_POST['do'] ?? '') {
            case 'add':
                $mgr->saveTicket($_POST);
                $onu = (int)($_POST['onu_id'] ?? 0);
                back($onu ? "onu.php?id={$onu}" : 'tickets.php', 'success', 'Ticket added.');
            case 'close':
            case 'reopen':
                $mgr->closeTicket((int)($_POST['id'] ?? 0), $_POST['do'] === 'close');
                back('tickets.php', 'success', $_POST['do'] === 'close' ? 'Ticket closed.' : 'Ticket opened again.');
        }
    } catch (InvalidArgumentException $e) {
        back('tickets.php?add=1' . (!empty($_POST['onu_id']) ? '&onu=' . (int)$_POST['onu_id'] : ''), 'error', $e->getMessage());
    }
}

$adding = isset($_GET['add']);
$onu = $adding && !empty($_GET['onu']) ? $mgr->onu((int)$_GET['onu']) : null;
$status = in_array($_GET['status'] ?? '', ['open', 'closed'], true) ? $_GET['status'] : '';

$pageTitle = $adding ? 'New ticket' : 'Tickets';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo flash_messages(); ?>
<?php if ($adding): ?>
<div class="so-pagehead"><h1><i class="fa fa-ticket"></i> New ticket</h1><?php echo btn('Back', 'grey', $onu ? 'onu.php?id=' . (int)$onu['id'] : 'tickets.php', 'list'); ?></div>
<p class="so-muted" style="margin-top:0;font-size:14px">A ticket is about an ONU, a customer or both. Whoever it is assigned to sees it on the Tickets list.</p>
<form method="POST" class="so-form" style="max-width:none">
    <?php echo csrf_field(); ?><input type="hidden" name="do" value="add">
    <div class="so-section"><h3>About</h3>
        <div class="so-row"><label>ONU</label>
            <select name="onu_id" class="so-select"><option value="">None, a customer only</option>
                <?php foreach ($mgr->listOnus([]) as $u): ?><option value="<?php echo (int)$u['id']; ?>" <?php echo $onu && (int)$onu['id'] === (int)$u['id'] ? 'selected' : ''; ?>><?php echo e($u['description'] . ' (' . $u['sn'] . ')'); ?></option><?php endforeach; ?>
            </select></div>
        <div class="so-row"><label></label><div class="so-help" style="margin:0">Select an ONU, a customer or both.</div></div>
    </div>
    <div class="so-section"><h3>Ticket</h3>
        <div class="so-row"><label>Subject</label><input name="subject" class="so-input" required maxlength="150"></div>
        <div class="so-row" style="align-items:start"><label>Description</label><textarea name="description" class="so-textarea" maxlength="5000"></textarea></div>
        <div class="so-row"><label>Type</label><select name="type" class="so-select"><?php foreach (OltManager::TICKET_TYPES as $k => $l): ?><option value="<?php echo $k; ?>"><?php echo $l; ?></option><?php endforeach; ?></select></div>
        <div class="so-row"><label>Priority</label><select name="priority" class="so-select"><?php foreach (OltManager::TICKET_PRIORITIES as $k => $l): ?><option value="<?php echo $k; ?>" <?php echo $k === 'normal' ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?></select></div>
        <div class="so-row"><label>Assignee</label><input name="assignee" class="so-input" maxlength="60" placeholder="Technician name"></div>
    </div>
    <div class="so-section"><h3>Schedule</h3>
        <div class="so-row"><label>Start</label><input type="datetime-local" name="start_at" class="so-input" style="max-width:260px"></div>
        <div class="so-row"><label></label><div class="so-help" style="margin:0">The date and time of the visit, when there is one.</div></div>
        <div class="so-row"><label>Duration (minutes)</label><input type="number" name="duration_min" min="5" max="1440" value="60" class="so-input"></div>
        <div class="so-row"><label></label><div><button class="so-btn so-btn-green">Add the ticket</button></div></div>
    </div>
</form>
<?php else: $rows = $mgr->tickets(['status' => $status]); ?>
<?php echo page_title('Tickets', 'Support visits, installations and repairs, with the ONU they are about.', btn('New ticket', 'green', 'tickets.php?add=1', 'plus')); ?>
<div class="so-pills" style="margin-bottom:14px"><a href="tickets.php" class="<?php echo $status === '' ? 'on' : ''; ?>">All</a><a href="tickets.php?status=open" class="<?php echo $status === 'open' ? 'on' : ''; ?>">Open</a><a href="tickets.php?status=closed" class="<?php echo $status === 'closed' ? 'on' : ''; ?>">Closed</a></div>
<?php if (!$rows): ?><div class="so-empty">No tickets found</div><?php else: ?>
<div class="so-table-wrap"><table class="so-table" style="font-size:14px">
    <thead><tr><th>#</th><th>Subject</th><th>ONU</th><th>Type</th><th>Priority</th><th>Assignee</th><th>Start</th><th>Status</th><th>Created</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $t): ?>
        <tr><td><?php echo (int)$t['id']; ?></td><td><b><?php echo e($t['subject']); ?></b><?php if ($t['description'] !== '') echo '<div class="so-muted" style="font-size:13px;white-space:pre-wrap">' . e(mb_substr($t['description'], 0, 200)) . '</div>'; ?></td>
            <td><?php echo $t['onu_id'] ? '<a href="onu.php?id=' . (int)$t['onu_id'] . '">' . e($t['onu_name'] ?? 'ONU') . '</a>' : '-'; ?></td>
            <td><?php echo e(OltManager::TICKET_TYPES[$t['type']] ?? $t['type']); ?></td>
            <td><?php echo badge(OltManager::TICKET_PRIORITIES[$t['priority']] ?? $t['priority'], in_array($t['priority'], ['high', 'urgent'], true) ? 'red' : 'grey'); ?></td>
            <td><?php echo e($t['assignee'] ?: '-'); ?></td><td><?php echo $t['start_at'] ? e(date('d-M-Y H:i', strtotime($t['start_at']))) . ' <small class="so-muted">' . (int)$t['duration_min'] . ' min</small>' : '-'; ?></td>
            <td><?php echo $t['status'] === 'open' ? badge('Open', 'orange') : badge('Closed', 'grey'); ?></td><td><?php echo e(when($t['created_at'])); ?></td>
            <td><?php echo $t['status'] === 'open' ? post_btn('close', ['id' => $t['id']], 'Close', 'green') : post_btn('reopen', ['id' => $t['id']], 'Reopen', 'grey'); ?></td></tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php endif; ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
