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

// ONUs per board for the board cards.
$perBoard = [];
foreach ($ports as $p) {
    $perBoard[(int)$p['slot']]['total'] = ($perBoard[(int)$p['slot']]['total'] ?? 0) + (int)$p['total'];
    $perBoard[(int)$p['slot']]['online'] = ($perBoard[(int)$p['slot']]['online'] ?? 0) + (int)$p['online'];
}
$speedOf = [];
foreach ($olt['traffic'] as $idx => $t) $speedOf[] = ['idx' => $idx, 'kbps' => $t['cir']];

$pageTitle = $olt['name'];
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading($olt['name'], trim(($olt['model'] ?: 'Not read yet') . ' · ' . $olt['version'] . ' · ' . $olt['host'] . ':' . $olt['telnet_port'], ' ·'),
            '<div class="flex gap-2"><a href="unconfigured.php?olt=' . $id . '" class="' . UI_BTN_GREEN . '">Unconfigured</a><a href="olts.php?edit=' . $id . '" class="' . UI_BTN_GHOST . '">Edit</a></div>'); ?>
        <?php echo flash_messages(); ?>
        <?php if ($olt['status'] === 'error'): ?>
            <div class="mb-4 p-3 border rounded-lg text-sm bg-rose-50 dark:bg-rose-900/30 border-rose-200 dark:border-rose-800/50 text-rose-700 dark:text-rose-300">Last read failed: <?php echo e($olt['last_error']); ?></div>
        <?php endif; ?>

        <div class="grid grid-cols-2 lg:grid-cols-6 gap-4 mb-8">
            <?php
            echo stat_card('Waiting', $counts['waiting'], 'teal', 'M12 9v3m0 0v3m0-3h3m-3 0H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z');
            echo stat_card('Online', $counts['online'], 'emerald', 'M5 13l4 4L19 7');
            echo stat_card('Power fail', $counts['power_fail'], 'amber', 'M13 10V3L4 14h7v7l9-11h-7z');
            echo stat_card('Signal loss', $counts['los'], 'rose', 'M6 18L18 6M6 6l12 12');
            echo stat_card('Low signal', $counts['warning'] + $counts['critical'], 'violet', 'M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z');
            echo stat_card('Last read', ago($olt['last_polled_at']), 'brand', 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z');
            ?>
        </div>

        <?php echo panel_open('Boards'); ?>
            <?php if (!$olt['boards']): ?>
                <p class="py-6 text-center text-sm text-slate-400">Not read yet. Press Refresh on the OLTs page.</p>
            <?php else: ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <?php foreach ($olt['boards'] as $b): $pb = $perBoard[(int)$b['slot']] ?? null; ?>
                    <div class="rounded-xl border p-3 <?php echo $b['gpon'] ? 'border-brand-200 dark:border-brand-800/50' : 'border-slate-200 dark:border-slate-700'; ?>">
                        <div class="text-xs text-slate-400 font-mono">Slot 0/<?php echo (int)$b['slot']; ?></div>
                        <div class="font-semibold text-slate-900 dark:text-slate-100"><?php echo e($b['name']); ?></div>
                        <div class="text-xs mt-1 <?php echo stripos($b['status'], 'normal') !== false ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400'; ?>"><?php echo e(str_replace('_', ' ', $b['status'])); ?></div>
                        <?php if ($b['gpon']): ?><div class="text-xs text-slate-500 dark:text-slate-400 mt-1"><?php echo (int)($pb['online'] ?? 0); ?> of <?php echo (int)($pb['total'] ?? 0); ?> ONUs online</div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php echo panel_close(); ?>

        <?php echo panel_open('PON Ports'); ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>"><tr>
                        <th class="<?php echo UI_TH; ?>">PON Port</th><th class="<?php echo UI_TH; ?>">Online</th><th class="<?php echo UI_TH; ?>">Power fail</th><th class="<?php echo UI_TH; ?>">Signal loss</th>
                        <th class="<?php echo UI_TH; ?>">Average signal</th><th class="<?php echo UI_TH; ?>">Weakest</th><th class="<?php echo UI_TH; ?> text-right">ONUs</th></tr></thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php if (!$ports): ?><tr><td colspan="7" class="px-4 py-8 text-center text-slate-400">No ONUs on this OLT yet.</td></tr><?php endif; ?>
                    <?php foreach ($ports as $p): $pon = "{$p['frame']}/{$p['slot']}/{$p['port']}"; $pct = $p['total'] ? round($p['online'] * 100 / $p['total']) : 0; ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                            <td class="<?php echo UI_TD; ?> font-mono font-semibold"><?php echo $pon; ?></td>
                            <td class="<?php echo UI_TD; ?>"><div class="flex items-center gap-2"><span class="font-medium"><?php echo (int)$p['online']; ?> / <?php echo (int)$p['total']; ?></span>
                                <div class="w-20 h-1.5 rounded-full bg-slate-200 dark:bg-slate-700"><div class="h-1.5 rounded-full <?php echo $pct >= 90 ? 'bg-emerald-500' : ($pct >= 60 ? 'bg-amber-500' : 'bg-rose-500'); ?>" style="width: <?php echo $pct; ?>%"></div></div></div></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo (int)$p['power_fail'] ? '<a class="text-amber-600 dark:text-amber-400 font-medium" href="onus.php?olt=' . $id . '&pon=' . $pon . '&status=power_fail">' . (int)$p['power_fail'] . '</a>' : '0'; ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo (int)$p['los'] ? '<a class="text-rose-600 dark:text-rose-400 font-medium" href="onus.php?olt=' . $id . '&pon=' . $pon . '&status=los">' . (int)$p['los'] . '</a>' : '0'; ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo signal_html($p['avg_rx']); ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo signal_html($p['min_rx']); ?></td>
                            <td class="<?php echo UI_TD; ?> text-right"><a href="onus.php?olt=<?php echo $id; ?>&pon=<?php echo $pon; ?>" class="<?php echo UI_BTN_PRIMARY; ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php echo panel_close(); ?>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2"><?php echo panel_open('ONUs online, last 24 hours'); ?>
                <?php if (count($net) < 2): ?><p class="py-6 text-center text-sm text-slate-400">The graph fills in as the OLT is read every 5 minutes.</p>
                <?php else: ?><canvas id="oltNet" height="120"></canvas><?php endif; ?>
            <?php echo panel_close(); ?></div>
            <div><?php echo panel_open('Profiles and VLANs'); ?>
                <dl class="text-sm space-y-3">
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">Line profiles</dt><dd><?php echo implode(', ', array_map(function ($p) { return e($p['id'] . ' ' . $p['name']); }, $olt['profiles']['line'])) ?: '--'; ?></dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">Service profiles</dt><dd><?php echo implode(', ', array_map(function ($p) { return e($p['id'] . ' ' . $p['name']); }, $olt['profiles']['srv'])) ?: '--'; ?></dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">VLANs</dt><dd class="font-mono"><?php echo implode(', ', array_map(function ($v) { return (int)$v['id']; }, $olt['profiles']['vlans'])) ?: '--'; ?></dd></div>
                    <div><dt class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400 mb-1">Speed tables</dt><dd><?php echo implode(', ', array_map(function ($t) { return 'table ' . $t['idx'] . ': ' . fmt_speed($t['kbps']); }, $speedOf)) ?: '--'; ?></dd></div>
                </dl>
            <?php echo panel_close(); ?></div>
        </div>
    </main>
    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
<script>
(function () {
    var el = document.getElementById('oltNet');
    if (!el) return;
    var pts = <?php echo json_encode(array_map(function ($r) { return ['t' => date('H:i', $r['t']), 'on' => $r['online'], 'off' => $r['offline']]; }, $net)); ?>;
    var chart;
    function build() {
        if (chart) chart.destroy();
        var dark = document.documentElement.classList.contains('dark'), tick = dark ? '#cbd5e1' : '#475569', grid = dark ? 'rgba(148,163,184,0.15)' : 'rgba(148,163,184,0.25)';
        chart = new Chart(el.getContext('2d'), { type: 'line',
            data: { labels: pts.map(function (p) { return p.t; }), datasets: [
                { label: 'Online', data: pts.map(function (p) { return p.on; }), borderColor: '#10b981', pointRadius: 0, tension: 0.25 },
                { label: 'Offline', data: pts.map(function (p) { return p.off; }), borderColor: '#ef4444', pointRadius: 0, tension: 0.25 }] },
            options: { responsive: true, interaction: { mode: 'index', intersect: false }, plugins: { legend: { labels: { color: tick } } },
                scales: { x: { ticks: { color: tick, maxTicksLimit: 12 }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: tick, precision: 0 }, grid: { color: grid } } } } });
    }
    build();
    window.addEventListener('themechange', build);
})();
</script>
