<?php
require_once __DIR__ . '/backend/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    try {
        $mgr->muteEvent((int)($_POST['id'] ?? 0), ($_POST['similar'] ?? '') === '1', ($_POST['do'] ?? '') !== 'unmute');
        back('events.php?' . preg_replace('/[^\w=&%.\/+-]/', '', (string)($_POST['return'] ?? '')), 'success', ($_POST['do'] ?? '') === 'unmute' ? 'Event unmuted.' : 'Event muted. It stays on the Muted tab and no longer counts.');
    } catch (InvalidArgumentException $e) {
        back('events.php', 'error', $e->getMessage());
    }
}

$tabs = ['ongoing' => 'Ongoing', 'muted' => 'Muted', 'history' => 'History', 'all' => 'All'];
$f = [
    'tab'    => isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'ongoing',
    'olt_id' => (int)($_GET['olt'] ?? 0),
    'q'      => trim((string)($_GET['q'] ?? '')),
    'from'   => (string)($_GET['from'] ?? ''),
    'to'     => (string)($_GET['to'] ?? ''),
];
$rows = $mgr->events($f);
$c = $mgr->eventCounts();
$qs = http_build_query(array_filter(['tab' => $f['tab'], 'olt' => $f['olt_id'] ?: '', 'q' => $f['q'], 'from' => $f['from'], 'to' => $f['to']]));
$tabLink = function (string $t) use ($f) {
    $q = $_GET;
    $q['tab'] = $t;
    return 'events.php?' . http_build_query($q);
};

$pageTitle = 'Network Events';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo flash_messages(); ?>
<form method="GET" class="so-box">
    <input type="hidden" name="tab" value="<?php echo e($f['tab']); ?>">
    <div class="so-filter-grid">
        <div><label class="so-label">Search</label><input name="q" value="<?php echo e($f['q']); ?>" class="so-input" placeholder="OLT, source, rule, message"></div>
        <div><label class="so-label">OLT</label><select name="olt" class="so-select"><option value="">All OLTs</option><?php echo olt_options($mgr->olts(), $f['olt_id'] ?: ''); ?></select></div>
        <div><label class="so-label">From</label><input type="date" name="from" value="<?php echo e($f['from']); ?>" class="so-input"></div>
        <div><label class="so-label">To</label><input type="date" name="to" value="<?php echo e($f['to']); ?>" class="so-input"></div>
        <button class="so-btn so-btn-green so-btn-icon" title="Search"><i class="fa fa-search"></i></button>
    </div>
    <div class="so-pills" style="margin-top:12px"><?php foreach ($tabs as $t => $l): ?><a href="<?php echo e($tabLink($t)); ?>" class="<?php echo $f['tab'] === $t ? 'on' : ''; ?>"><?php echo $l; ?></a><?php endforeach; ?></div>
</form>

<div class="so-tiles">
    <div class="so-tile"><b><?php echo $c['total']; ?></b>Total</div>
    <div class="so-tile t-red"><b><?php echo $c['critical']; ?></b>Critical</div>
    <div class="so-tile t-orange"><b><?php echo $c['warning']; ?></b>Warning</div>
    <div class="so-tile"><b><?php echo $c['olt']; ?></b><i class="fa fa-server"></i> OLT unreachable</div>
    <div class="so-tile"><b><?php echo $c['pon']; ?></b><i class="fa fa-chain-broken"></i> PON outages</div>
    <div class="so-tile"><b><?php echo $c['signal']; ?></b><i class="fa fa-signal"></i> PON signal</div>
    <div class="so-tile"><b><?php echo $c['temp']; ?></b><i class="fa fa-thermometer-half" style="color:var(--red)"></i> OLT temp</div>
</div>

<?php echo panel_open('Network Events', '<span class="so-dot"></span> Read every 5 minutes &nbsp;' . badge($tabs[$f['tab']], 'blue'), 'history'); ?>
    <?php if (!$rows): ?>
        <p class="so-muted" style="margin:0"><?php echo $f['tab'] === 'ongoing' ? 'No ongoing events. Everything SmartOLT watches is fine.' : 'No events found.'; ?></p>
    <?php else: ?>
    <div class="so-table-wrap"><table class="so-table" style="font-size:14px">
        <thead><tr><th></th><th>Started</th><th>Type</th><th>Last update</th><th>Status</th><th>Source</th><th>Event</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $ev): $ongoing = $ev['resolved_at'] === null; ?>
            <tr>
                <td><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:<?php echo $ev['severity'] === 'critical' ? 'var(--red)' : 'var(--orange)'; ?>"></span></td>
                <td style="white-space:nowrap"><?php echo e(AppTime::format('d-M-Y', $ev['started_at'])); ?><br><?php echo e(AppTime::format('H:i:s', $ev['started_at'])); ?></td>
                <td><?php echo e(OltManager::EVENT_TYPES[$ev['type']] ?? $ev['type']); ?></td>
                <td style="white-space:nowrap"><?php echo $ongoing ? '' : e(AppTime::format('d-M-Y H:i:s', $ev['resolved_at'])); ?></td>
                <td><?php if ($ongoing): ?><span style="color:var(--link)">&#9679; Ongoing</span><br><small class="so-muted">Down <?php echo e(str_replace(' ago', '', ago($ev['started_at']))); ?></small>
                    <?php else: ?><span style="color:var(--green)"><i class="fa fa-check"></i> Resolved</span><br><small class="so-muted">After <?php echo e(str_replace(' ago', '', ago(date('Y-m-d H:i:s', time() - (strtotime($ev['resolved_at']) - strtotime($ev['started_at'])))))); ?></small><?php endif; ?>
                    <?php if ($ev['muted']) echo '<br>' . badge('Muted', 'grey'); ?></td>
                <td><?php echo e($ev['olt_name'] !== null ? $ev['olt_id'] . ' - ' . $ev['olt_name'] : ($ev['olt_id'] ? 'Removed OLT' : 'System')); ?></td>
                <td><b><?php echo e($ev['title']); ?></b> <span class="so-muted"><?php echo e($ev['message']); ?></span>
                    <div class="so-inline" style="margin-top:6px">
                        <?php if (!$ev['muted']): ?>
                            <?php if ($ongoing) echo post_btn('mute', ['id' => $ev['id'], 'return' => $qs], 'Mute this event', 'grey', 'bell-slash'); ?>
                            <?php echo post_btn('mute', ['id' => $ev['id'], 'similar' => 1, 'return' => $qs], 'Mute similar events', 'grey', 'ban'); ?>
                        <?php else: echo post_btn('unmute', ['id' => $ev['id'], 'similar' => 1, 'return' => $qs], 'Unmute', 'grey', 'bell'); endif; ?>
                    </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php endif; ?>
<?php echo panel_close(); ?>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
