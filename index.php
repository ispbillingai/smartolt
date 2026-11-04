<?php
// Set session cookie parameters before starting the session
$session_lifetime = 30 * 24 * 60 * 60; // 30 days
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/',
    'domain' => '',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/auth/login.php';

// Initialize login handler
$loginHandler = new LoginHandler();

// Check if user is logged in
if (!$loginHandler->isLoggedIn()) {
    // If it's a login attempt
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
        $result = $loginHandler->login($_POST['username'], $_POST['password']);
        if (!$result['success']) {
            $error = $result['message'];
        } else {
            // Extend session lifetime if "Remember Me" is checked
            if (isset($_POST['remember_me'])) {
                setcookie(session_name(), session_id(), [
                    'expires' => time() + $session_lifetime,
                    'path' => '/',
                    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    // Handle password reset
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
        $result = $loginHandler->resetPassword(trim($_POST['master_email'] ?? ''));
        if ($result['success']) {
            $success_message = $result['message'];
        } else {
            $error = $result['message'];
        }
    }

    // Show login form if not logged in
    include __DIR__ . '/backend/templates/login_form.php';
    exit;
}

// Check if the user needs to change their password
if ($loginHandler->needsPasswordChange()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password']) && isset($_POST['confirm_password'])) {
        $result = $loginHandler->changePassword(trim($_POST['new_password'] ?? ''), trim($_POST['confirm_password'] ?? ''));
        if ($result['success']) {
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
        $error = $result['message'];
    }
    include __DIR__ . '/backend/templates/change_password_form.php';
    exit;
}

require_once __DIR__ . '/backend/bootstrap.php';

// ----- Overview figures, all from the database copy (the 5 minute read keeps it fresh) -----
$olts = $mgr->olts();
$c = $mgr->counts();
$net = $mgr->networkStatus(24);
$events = $mgr->recentEvents(12);
$offlineList = $mgr->listOnus(['status' => 'offline'], 10);

// Signal spread for the chart
$bands = ['Good' => 0, 'Warning' => 0, 'Critical' => 0, 'No reading' => 0];
foreach ($mgr->listOnus([]) as $u) {
    $l = signal_level($u['rx_power']);
    $bands[$l === '' ? 'No reading' : $l]++;
}

// A card that links to the matching list
$card = function (string $label, $value, string $tone, string $href, string $sub = ''): string {
    return '<a href="' . e($href) . '" class="block bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-5 hover:border-' . $tone . '-400 transition">'
        . '<p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">' . e($label) . '</p>'
        . '<h3 class="text-3xl font-bold text-' . $tone . '-600 dark:text-' . $tone . '-400 mt-1">' . e($value) . '</h3>'
        . ($sub !== '' ? '<p class="text-xs text-slate-500 dark:text-slate-400 mt-2">' . $sub . '</p>' : '') . '</a>';
};

$pageTitle = 'Dashboard';
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>

<!-- Main Content -->
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading('Overview', 'Your OLTs, every ONU on them, why the offline ones are down and how strong their signal is.'); ?>
        <?php echo flash_messages(); ?>

        <?php if (!$olts): ?>
            <div class="mb-8 p-5 rounded-2xl border border-brand-200 dark:border-brand-800/50 bg-brand-50 dark:bg-brand-900/30 text-brand-800 dark:text-brand-200 text-sm flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <span>Add your OLT to get started. SmartOLT reads its boards, profiles and every ONU on it.</span>
                <a href="olts.php?add=1" class="<?php echo UI_BTN_PRIMARY; ?>">Add OLT</a>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <?php
            echo $card('Waiting authorization', $c['waiting'], 'teal', 'unconfigured.php', 'New ONUs plugged in');
            echo $card('Online', $c['online'], 'emerald', 'onus.php?status=online', 'of ' . (int)$c['total'] . ' authorized');
            echo $card('Total offline', $c['offline'], 'rose', 'onus.php?status=offline',
                '<b>' . $c['power_fail'] . '</b> power fail · <b>' . $c['los'] . '</b> signal loss · <b>' . ($c['other_off']) . '</b> other' . ($c['disabled'] ? ' · <b>' . $c['disabled'] . '</b> disabled' : ''));
            echo $card('Low signal', $c['warning'] + $c['critical'], 'amber', 'onus.php?status=weak',
                '<b>' . $c['warning'] . '</b> warning · <b>' . $c['critical'] . '</b> critical');
            ?>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
            <div class="lg:col-span-2 bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">Network Status, last 24 hours</h3>
                <?php if (count($net) < 2): ?><p class="py-10 text-center text-sm text-slate-400">The graph fills in as your OLTs are read every 5 minutes.</p>
                <?php else: ?><canvas id="netChart" height="120"></canvas><?php endif; ?>
            </div>
            <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">Signal Levels</h3>
                <canvas id="signalChart" height="200"></canvas>
            </div>
        </div>

        <!-- OLT status -->
        <?php echo panel_open('OLTs', '<a href="olts.php" class="text-sm text-brand-600 dark:text-brand-400 font-medium">Manage OLTs</a>'); ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>">
                        <tr><th class="<?php echo UI_TH; ?>">OLT</th><th class="<?php echo UI_TH; ?>">Model</th><th class="<?php echo UI_TH; ?>">ONUs</th><th class="<?php echo UI_TH; ?>">Status</th><th class="<?php echo UI_TH; ?>">Last Read</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php if (!$olts): ?>
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400 dark:text-slate-500">No OLT added yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($olts as $o): ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                            <td class="<?php echo UI_TD; ?> font-medium"><a class="text-brand-600 dark:text-brand-400" href="olt.php?id=<?php echo (int)$o['id']; ?>"><?php echo e($o['name']); ?></a><div class="text-xs text-slate-400 font-mono"><?php echo e($o['host'] . ':' . $o['telnet_port']); ?></div></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo $o['model'] !== '' ? e($o['model']) : '<span class="text-slate-400">Not read yet</span>'; ?></td>
                            <td class="<?php echo UI_TD; ?>"><a class="text-brand-600 dark:text-brand-400" href="onus.php?olt=<?php echo (int)$o['id']; ?>"><b><?php echo (int)$o['onu_online']; ?></b> online of <?php echo (int)$o['onu_count']; ?></a></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo $o['status'] === 'online' ? badge('Connected', 'emerald') : ($o['status'] === 'error' ? badge('Cannot connect', 'rose') : badge('Not read yet', 'slate')); ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo ago($o['last_polled_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php echo panel_close(); ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div><?php echo panel_open('Offline ONUs', '<a href="onus.php?status=offline" class="text-sm text-brand-600 dark:text-brand-400 font-medium">See all offline</a>'); ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="<?php echo UI_THEAD; ?>"><tr><th class="<?php echo UI_TH; ?>">ONU</th><th class="<?php echo UI_TH; ?>">PON Port</th><th class="<?php echo UI_TH; ?>">Status</th><th class="<?php echo UI_TH; ?>">Since</th></tr></thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                        <?php if (!$offlineList): ?>
                            <tr><td colspan="4" class="px-4 py-8 text-center text-slate-400 dark:text-slate-500">Every ONU is online.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($offlineList as $u): ?>
                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                                <td class="<?php echo UI_TD; ?> font-medium"><a class="text-brand-600 dark:text-brand-400" href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description']); ?></a><div class="text-xs font-mono text-slate-400"><?php echo e($u['sn']); ?></div></td>
                                <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300 whitespace-nowrap"><?php echo e($u['olt_name']); ?> <span class="font-mono text-xs"><?php echo (int)$u['frame'] . '/' . (int)$u['slot'] . '/' . (int)$u['port']; ?></span></td>
                                <td class="<?php echo UI_TD; ?>"><?php echo status_badge($u['status_group'], $u['run_state']); ?></td>
                                <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300 whitespace-nowrap"><?php echo ago($u['last_down_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php echo panel_close(); ?></div>
            <div><?php echo panel_open('Latest changes'); ?>
                <?php if (!$events): ?>
                    <p class="py-8 text-center text-sm text-slate-400">When an ONU goes offline or comes back, it shows here.</p>
                <?php else: ?>
                <ul class="divide-y divide-slate-200 dark:divide-slate-700 text-sm">
                    <?php foreach ($events as $ev): ?>
                        <li class="py-2.5 flex items-center justify-between gap-3">
                            <span><a class="font-medium text-brand-600 dark:text-brand-400" href="onu.php?id=<?php echo (int)$ev['onu_id']; ?>"><?php echo e($ev['description']); ?></a>
                                <span class="text-xs text-slate-400"><?php echo e($ev['olt_name']); ?></span></span>
                            <span class="flex items-center gap-3 whitespace-nowrap"><?php echo status_badge($ev['state']); ?><span class="text-xs text-slate-400"><?php echo ago($ev['at']); ?></span></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            <?php echo panel_close(); ?></div>
        </div>
    </main>

    <?php include __DIR__ . '/backend/templates/footer.php'; ?>

<!-- Chart Initialization Scripts (theme-aware) -->
<script>
    (function () {
        var bandLabels = <?php echo json_encode(array_keys($bands)); ?>;
        var bandValues = <?php echo json_encode(array_values($bands)); ?>;
        var net = <?php echo json_encode(array_map(function ($r) { return ['t' => date('H:i', $r['t']), 'on' => $r['online'], 'off' => $r['offline'], 'los' => $r['los'], 'pf' => $r['power_fail']]; }, $net)); ?>;
        var signalChart, netChart;

        function isDark() { return document.documentElement.classList.contains('dark'); }
        function tickColor() { return isDark() ? '#cbd5e1' : '#475569'; }
        function gridColor() { return isDark() ? 'rgba(148, 163, 184, 0.15)' : 'rgba(148, 163, 184, 0.25)'; }
        function legendColor() { return isDark() ? '#e2e8f0' : '#334155'; }

        function build() {
            if (signalChart) signalChart.destroy();
            if (netChart) netChart.destroy();
            signalChart = new Chart(document.getElementById('signalChart').getContext('2d'), {
                type: 'doughnut',
                data: { labels: bandLabels, datasets: [{ data: bandValues, backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#94a3b8'], borderColor: isDark() ? '#1e293b' : '#ffffff', borderWidth: 3 }] },
                options: { responsive: true, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { color: legendColor(), font: { size: 12 }, padding: 16 } } } }
            });
            var el = document.getElementById('netChart');
            if (!el) return;
            netChart = new Chart(el.getContext('2d'), {
                type: 'line',
                data: { labels: net.map(function (p) { return p.t; }), datasets: [
                    { label: 'Online', data: net.map(function (p) { return p.on; }), borderColor: '#10b981', pointRadius: 0, tension: 0.25 },
                    { label: 'Power fail', data: net.map(function (p) { return p.pf; }), borderColor: '#f59e0b', pointRadius: 0, tension: 0.25 },
                    { label: 'Signal loss', data: net.map(function (p) { return p.los; }), borderColor: '#ef4444', pointRadius: 0, tension: 0.25 }
                ] },
                options: {
                    responsive: true, interaction: { mode: 'index', intersect: false }, plugins: { legend: { labels: { color: legendColor() } } },
                    scales: { x: { ticks: { color: tickColor(), maxTicksLimit: 12 }, grid: { display: false } }, y: { beginAtZero: true, ticks: { color: tickColor(), precision: 0 }, grid: { color: gridColor() } } }
                }
            });
        }
        document.addEventListener('DOMContentLoaded', build);
        window.addEventListener('themechange', build);
    })();
</script>
