<?php
require_once __DIR__ . '/backend/bootstrap.php';

$id = (int)($_GET['id'] ?? 0);
$row = $mgr->olt($id);
if (!$row) {
    back('olts.php', 'error', 'OLT not found.');
}
$olt = OltManager::publicOlt($row);
$counts = $mgr->counts($id);
$ports = $mgr->ports($id);
$net = $mgr->networkStatus(24, $id);
$perBoard = [];
foreach ($ports as $p) {
    $perBoard[(int)$p['slot']]['total'] = ($perBoard[(int)$p['slot']]['total'] ?? 0) + (int)$p['total'];
    $perBoard[(int)$p['slot']]['online'] = ($perBoard[(int)$p['slot']]['online'] ?? 0) + (int)$p['online'];
}
$vpn = $olt['conn_type'] === 'vpn' ? $mgr->vpnStatus($row) : null;
$script = $vpn !== null ? $mgr->mikrotikScript($row) : '';

$pageTitle = $olt['name'];
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo page_title($id . ' - ' . $olt['name'], ($olt['model'] !== '' ? 'Huawei ' . $olt['model'] . ', ' : '') . olt_address($olt),
    btn('Unconfigured', 'green', 'unconfigured.php?olt=' . $id)
    . post_btn('refresh', ['id' => $id, 'back' => 'olt'], 'Refresh', 'blue', 'refresh', '', 'Reading the OLT. On a big OLT this takes up to a minute...', 'olts.php')
    . btn('Edit', 'grey', 'olts.php?edit=' . $id, 'pencil') . btn('Diag', 'grey', 'diag.php?olt=' . $id, 'terminal')); ?>
<?php echo flash_messages(); ?>
<?php if ($olt['status'] === 'error') echo alert('Last read failed: ' . $olt['last_error'], 'err'); ?>

<?php if ($vpn !== null): ?>
<?php echo panel_open('SmartOLT VPN', $vpn['connected'] ? badge('Connected', 'green') . ' from ' . e($vpn['from']) . ', since ' . e($vpn['since']) : badge('Waiting for your MikroTik', 'orange'), 'lock'); ?>
    <?php if (!empty($vpn['error'])) echo alert($vpn['error'], 'err'); ?>
    <p style="margin-top:0">Paste this into the terminal of the MikroTik your OLT is connected to (Winbox, New Terminal). It connects that router to SmartOLT by VPN and lets SmartOLT reach the OLT at <span class="so-mono"><?php echo e($olt['lan_ip'] . ':' . $olt['lan_port']); ?></span> through it, and nothing else. Pasting it again is safe.</p>
    <pre class="so-pre" id="vpnScript"><?php echo e($script); ?></pre>
    <div class="so-inline">
        <button type="button" class="so-btn so-btn-grey" data-copy="vpnScript"><i class="fa fa-copy"></i> Copy</button>
        <?php echo btn('Check again', 'blue', 'olt.php?id=' . $id, 'refresh'); ?>
        <?php if (!$vpn['connected']): ?><span class="so-muted" style="font-size:14px">Once connected the router shows <b>smartolt-olt<?php echo $id; ?></b> as running under Interfaces. If not, check it can reach the internet on TCP port 1194.</span><?php endif; ?>
    </div>
<?php echo panel_close(); ?>
<?php endif; ?>

<div class="so-two">
    <div>
        <?php echo panel_open('Boards', '', 'server'); ?>
            <?php if (!$olt['boards']): ?><p class="so-muted" style="margin:0">Not read yet. Press Refresh.</p><?php else: ?>
            <div class="so-table-wrap"><table class="so-table">
                <thead><tr><th>Slot</th><th>Board</th><th>Status</th><th>Type</th><th>ONUs online</th></tr></thead>
                <tbody><?php foreach ($olt['boards'] as $b): $pb = $perBoard[(int)$b['slot']] ?? null; ?>
                    <tr><td>0/<?php echo (int)$b['slot']; ?></td><td><?php echo e($b['name']); ?></td>
                        <td><?php echo stripos($b['status'], 'normal') !== false ? badge(str_replace('_', ' ', $b['status']), 'green') : badge(str_replace('_', ' ', $b['status']), 'red'); ?></td>
                        <td><?php echo $b['gpon'] ? 'GPON' : 'Control or uplink'; ?></td>
                        <td><?php echo $b['gpon'] ? (int)($pb['online'] ?? 0) . ' / ' . (int)($pb['total'] ?? 0) : ''; ?></td></tr>
                <?php endforeach; ?></tbody>
            </table></div>
            <?php endif; ?>
        <?php echo panel_close(); ?>
    </div>
    <div>
        <div class="so-panel"><div class="so-panel-h"><span>OLT <?php echo $olt['model'] !== '' ? 'Huawei ' . e($olt['model']) : ''; ?></span>
            <span class="so-dot<?php echo $olt['status'] === 'online' ? '' : ' off'; ?>"></span></div>
            <div class="so-panel-b">
                <div class="so-olt-pic"><?php echo olt_picture(max(8, min(21, count($olt['boards']) ?: 16))); ?></div>
                <dl class="so-dl">
                    <dt>Uptime</dt><dd><?php echo e($olt['uptime'] ?: '-'); ?><?php echo $olt['temperature'] !== '' ? ', ' . e($olt['temperature']) . '°C' : ''; ?></dd>
                    <dt>Software</dt><dd><?php echo e($olt['version'] ?: '-'); ?></dd>
                    <dt>Last read</dt><dd><?php echo ago($olt['last_polled_at']); ?></dd>
                    <dt>Config saved</dt><dd><?php echo $olt['saved_at'] ? ago($olt['saved_at']) : 'Not from SmartOLT yet'; ?> <a href="#" data-open="soSave"><i class="fa fa-floppy-o"></i></a></dd>
                    <dt>ONUs</dt><dd><?php echo (int)$counts['online']; ?> online of <?php echo (int)$counts['total']; ?>, <?php echo (int)$counts['waiting']; ?> waiting</dd>
                </dl>
            </div>
        </div>
    </div>
</div>

<?php echo panel_open('PON ports', '', 'sitemap'); ?>
    <div class="so-table-wrap"><table class="so-table">
        <thead><tr><th>PON port</th><th>Online</th><th>Occupancy</th><th>Power fail</th><th>LOS</th><th>Average signal</th><th>Weakest</th><th></th></tr></thead>
        <tbody>
        <?php if (!$ports): ?><tr><td colspan="8" class="so-muted">No ONUs on this OLT yet.</td></tr><?php endif; ?>
        <?php foreach ($ports as $p): $pon = "{$p['frame']}/{$p['slot']}/{$p['port']}"; $pct = round($p['total'] * 100 / 128); ?>
            <tr><td class="so-mono"><?php echo $pon; ?></td>
                <td><?php echo (int)$p['online']; ?> / <?php echo (int)$p['total']; ?></td>
                <td><div class="so-inline"><div class="so-bar" style="width:100px"><span style="width:<?php echo max(2, $pct); ?>%;background:<?php echo $pct >= 90 ? 'var(--red)' : ($pct >= 70 ? 'var(--orange)' : 'var(--green)'); ?>"></span></div><small><?php echo (int)$p['total']; ?> of 128</small></div></td>
                <td><?php echo (int)$p['power_fail'] ? '<a href="onus.php?olt=' . $id . '&pon=' . $pon . '&status=power_fail">' . (int)$p['power_fail'] . '</a>' : '0'; ?></td>
                <td><?php echo (int)$p['los'] ? '<a href="onus.php?olt=' . $id . '&pon=' . $pon . '&status=los" style="color:var(--red)">' . (int)$p['los'] . '</a>' : '0'; ?></td>
                <td><?php echo signal_html($p['avg_rx']); ?></td><td><?php echo signal_html($p['min_rx']); ?></td>
                <td class="num"><?php echo btn('ONUs', 'blue', 'onus.php?olt=' . $id . '&pon=' . $pon) . ' ' . btn('', 'grey', 'graphs.php?olt=' . $id . '&pon=' . $pon, 'area-chart', 'title="Graph"'); ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
<?php echo panel_close(); ?>

<div class="so-two">
    <div><?php echo panel_open('ONUs online, last 24 hours', '<a href="graphs.php?olt=' . $id . '">More graphs</a>', 'area-chart'); ?>
        <?php if (count($net) < 2): ?><p class="so-muted" style="text-align:center">The graph fills in as the OLT is read every 5 minutes.</p>
        <?php else: ?><canvas id="oltNet" height="110"></canvas><?php endif; ?>
    <?php echo panel_close(); ?></div>
    <div><?php echo panel_open('Profiles and VLANs', '', 'list'); ?>
        <dl class="so-dl">
            <dt>Line profiles</dt><dd><?php echo implode(', ', array_map(function ($p) { return e($p['id'] . ' ' . $p['name']); }, $olt['profiles']['line'])) ?: '-'; ?></dd>
            <dt>Service profiles</dt><dd><?php echo implode(', ', array_map(function ($p) { return e($p['id'] . ' ' . $p['name']); }, $olt['profiles']['srv'])) ?: '-'; ?></dd>
            <dt>VLANs</dt><dd><?php echo implode(', ', array_map(function ($v) { return (int)$v['id']; }, $olt['profiles']['vlans'])) ?: '-'; ?></dd>
            <dt>Speed tables</dt><dd><?php $t = []; foreach ($olt['traffic'] as $idx => $tt) $t[] = $idx . ': ' . fmt_speed($tt['cir']); echo e(implode(', ', $t)) ?: '-'; ?></dd>
        </dl>
    <?php echo panel_close(); ?></div>
</div>
<script>
(function () {
    var el = document.getElementById('oltNet');
    if (!el) return;
    var pts = <?php echo json_encode(array_map(function ($r) { return ['t' => date('H:i', $r['t']), 'on' => $r['online'], 'off' => $r['offline']]; }, $net)); ?>;
    var chart;
    function build() {
        if (chart) chart.destroy();
        chart = new Chart(el.getContext('2d'), { type: 'line',
            data: { labels: pts.map(function (p) { return p.t; }), datasets: [
                { label: 'Online', data: pts.map(function (p) { return p.on; }), borderColor: '#2ecc40', backgroundColor: 'rgba(46,204,64,0.12)', fill: true, pointRadius: 0, tension: 0.2 },
                { label: 'Offline', data: pts.map(function (p) { return p.off; }), borderColor: '#9aa5b8', pointRadius: 0, tension: 0.2 }] },
            options: { responsive: true, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { color: soChart.tick() } } },
                scales: { x: { ticks: { color: soChart.tick(), maxTicksLimit: 8 }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: soChart.tick(), precision: 0 }, grid: { color: soChart.grid() } } } } });
    }
    build();
    window.addEventListener('themechange', build);
})();
</script>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
