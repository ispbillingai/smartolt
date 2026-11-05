<?php
require_once __DIR__ . '/backend/bootstrap.php';

$olts = $mgr->olts();
$oltId = (int)($_GET['olt'] ?? 0);
$range = (int)($_GET['range'] ?? 24);
$range = in_array($range, [6, 24, 168, 720], true) ? $range : 24;
$pon = preg_match('#^\d+/\d+/\d+$#', (string)($_GET['pon'] ?? '')) ? $_GET['pon'] : '';
$fmt = $range > 24 ? 'j M H:i' : 'H:i';

$net = array_map(function ($r) use ($fmt) {
    return ['t' => date($fmt, $r['t']), 'on' => $r['online'], 'pf' => $r['power_fail'], 'los' => $r['los'], 'na' => max(0, $r['offline'] - $r['los'] - $r['power_fail'])];
}, $mgr->networkStatus($range, $oltId));

// One small graph per PON port of the chosen OLT (or the one port asked for).
$ponGraphs = [];
if ($oltId) {
    foreach ($mgr->ports($oltId) as $p) {
        $key = "{$p['frame']}/{$p['slot']}/{$p['port']}";
        if ($pon !== '' && $key !== $pon) continue;
        $ponGraphs[$key] = array_map(function ($r) use ($fmt) { return ['t' => date($fmt, strtotime($r['at'])), 'on' => (int)$r['online'], 'total' => (int)$r['total']]; },
            $mgr->ponHistory($oltId, $key, $range));
    }
}

$pageTitle = 'Graphs';
include __DIR__ . '/backend/templates/header.php';
?>
<form method="GET" class="so-inline" style="margin:6px 0 16px">
    <label class="so-label">OLT</label><select name="olt" class="so-select" onchange="this.form.submit()"><option value="">All OLTs</option><?php echo olt_options($olts, $oltId ?: ''); ?></select>
    <label class="so-label">Period</label>
    <select name="range" class="so-select" onchange="this.form.submit()">
        <?php foreach ([6 => 'Last 6 hours', 24 => 'Last 24 hours', 168 => 'Last week', 720 => 'Last month'] as $h => $l): ?><option value="<?php echo $h; ?>" <?php echo $h === $range ? 'selected' : ''; ?>><?php echo $l; ?></option><?php endforeach; ?>
    </select>
    <?php if ($pon !== ''): ?><input type="hidden" name="pon" value="<?php echo e($pon); ?>"><span><?php echo badge('PON ' . $pon, 'blue'); ?> <a href="graphs.php?olt=<?php echo $oltId; ?>&range=<?php echo $range; ?>">All ports</a></span><?php endif; ?>
</form>

<?php echo panel_open('Network status' . ($oltId ? '' : ', all OLTs'), '', 'bar-chart'); ?>
    <?php if (count($net) < 2): ?><p class="so-muted" style="text-align:center;padding:40px 0">The graph fills in as your OLTs are read every 5 minutes.</p>
    <?php else: ?><canvas id="g_net" height="80"></canvas><?php endif; ?>
<?php echo panel_close(); ?>

<?php if (!$oltId): ?>
    <p class="so-muted">Choose an OLT to see a graph for each of its PON ports.</p>
<?php else: ?>
<div class="so-half">
    <?php $i = 0; foreach ($ponGraphs as $key => $pts): ?>
        <div><?php echo panel_open('PON ' . $key, '<a href="onus.php?olt=' . $oltId . '&pon=' . e($key) . '">ONUs</a>', 'sitemap'); ?>
            <?php if (count($pts) < 2): ?><p class="so-muted" style="margin:0">Not enough reads yet.</p><?php else: ?><canvas id="g_pon<?php echo $i; ?>" height="110"></canvas><?php endif; ?>
        <?php echo panel_close(); ?></div>
    <?php $i++; endforeach; ?>
</div>
<?php endif; ?>
<script>
(function () {
    var charts = [];
    var net = <?php echo json_encode($net); ?>;
    var pons = <?php echo json_encode(array_values($ponGraphs)); ?>;
    function line(id, labels, sets) {
        var el = document.getElementById(id);
        if (!el) return;
        charts.push(new Chart(el.getContext('2d'), { type: 'line', data: { labels: labels, datasets: sets },
            options: { responsive: true, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom', labels: { color: soChart.tick(), boxWidth: 10 } } },
                scales: { x: { ticks: { color: soChart.tick(), maxTicksLimit: 8 }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: soChart.tick(), precision: 0 }, grid: { color: soChart.grid() } } } } }));
    }
    function ds(label, data, color, fill) { return { label: label, data: data, borderColor: color, backgroundColor: fill || 'transparent', fill: !!fill, pointRadius: 0, tension: 0.2, borderWidth: 2 }; }
    function build() {
        charts.forEach(function (c) { c.destroy(); });
        charts = [];
        line('g_net', net.map(function (p) { return p.t; }), [
            ds('Online', net.map(function (p) { return p.on; }), '#2ecc40', 'rgba(46,204,64,0.12)'), ds('Power Fail', net.map(function (p) { return p.pf; }), '#4e8cff'),
            ds('LOS', net.map(function (p) { return p.los; }), '#f5a623'), ds('N/A', net.map(function (p) { return p.na; }), '#9aa5b8')]);
        pons.forEach(function (pts, i) {
            line('g_pon' + i, pts.map(function (p) { return p.t; }), [
                ds('Online', pts.map(function (p) { return p.on; }), '#2ecc40', 'rgba(46,204,64,0.12)'), ds('Authorized', pts.map(function (p) { return p.total; }), '#9aa5b8')]);
        });
    }
    build();
    window.addEventListener('themechange', build);
})();
</script>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
