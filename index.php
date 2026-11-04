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
$onus = $mgr->listOnus([]);
$total = count($onus);
$online = count(array_filter($onus, function ($u) { return $u['run_state'] === 'online'; }));
$offlineList = array_values(array_filter($onus, function ($u) { return $u['run_state'] !== 'online' && $u['run_state'] !== ''; }));
$weakList = array_values(array_filter($onus, function ($u) { return $u['rx_power'] !== null && (float)$u['rx_power'] < -27; }));

// Signal spread for the chart
$bands = ['Good (-8 to -25)' => 0, 'Weak (-25 to -27)' => 0, 'Bad (below -27)' => 0, 'No reading' => 0];
foreach ($onus as $u) {
    if ($u['rx_power'] === null) { $bands['No reading']++; continue; }
    $v = (float)$u['rx_power'];
    if ($v < -27 || $v > -8) $bands['Bad (below -27)']++;
    elseif ($v < -25) $bands['Weak (-25 to -27)']++;
    else $bands['Good (-8 to -25)']++;
}

// ONUs per PON port, busiest first
$perPort = [];
foreach ($onus as $u) {
    $k = $u['olt_name'] . ' ' . $u['frame'] . '/' . $u['slot'] . '/' . $u['port'];
    $perPort[$k] = ($perPort[$k] ?? 0) + 1;
}
arsort($perPort);
$perPort = array_slice($perPort, 0, 8, true);

usort($offlineList, function ($a, $b) { return strcmp($b['updated_at'], $a['updated_at']); });
$offlineList = array_slice($offlineList, 0, 10);

$pageTitle = 'Dashboard';
include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>

<!-- Main Content -->
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <?php echo page_heading('Overview', 'Your OLTs, every ONU on them and how strong their signal is.'); ?>
        <?php echo flash_messages(); ?>

        <?php if (!$olts): ?>
            <div class="mb-8 p-5 rounded-2xl border border-brand-200 dark:border-brand-800/50 bg-brand-50 dark:bg-brand-900/30 text-brand-800 dark:text-brand-200 text-sm flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <span>Add your OLT to get started. SmartOLT reads its boards, profiles and every ONU on it.</span>
                <a href="olts.php?add=1" class="<?php echo UI_BTN_PRIMARY; ?>">Add OLT</a>
            </div>
        <?php endif; ?>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <?php
            echo stat_card('OLTs', count($olts), 'brand', 'M5 12h14M5 12a2 2 0 01-2-2V7a2 2 0 012-2h14a2 2 0 012 2v3a2 2 0 01-2 2M5 12a2 2 0 00-2 2v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 00-2-2');
            echo stat_card('Authorized ONUs', $total, 'violet', 'M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0');
            echo stat_card('Online', $online, 'emerald', 'M5 13l4 4L19 7');
            echo stat_card('Offline', $total - $online, 'rose', 'M6 18L18 6M6 6l12 12');
            echo stat_card('Weak Signal', count($weakList), 'amber', 'M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z');
            ?>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">Signal Levels</h3>
                <canvas id="signalChart" height="200"></canvas>
            </div>
            <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">Busiest PON Ports</h3>
                <canvas id="portChart" height="200"></canvas>
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
                            <td class="<?php echo UI_TD; ?> font-medium text-slate-900 dark:text-slate-100"><?php echo e($o['name']); ?><div class="text-xs text-slate-400 font-mono"><?php echo e($o['host'] . ':' . $o['telnet_port']); ?></div></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo $o['model'] !== '' ? e($o['model']) : '<span class="text-slate-400">Not read yet</span>'; ?></td>
                            <td class="<?php echo UI_TD; ?>"><a class="text-brand-600 dark:text-brand-400" href="onus.php?olt=<?php echo (int)$o['id']; ?>"><b><?php echo (int)$o['onu_online']; ?></b> online of <?php echo (int)$o['onu_count']; ?></a></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo $o['status'] === 'online' ? badge('Connected', 'emerald') : ($o['status'] === 'error' ? badge('Cannot connect', 'rose') : badge('Not read yet', 'slate')); ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo $o['last_polled_at'] ? e(date('j M H:i', strtotime($o['last_polled_at']))) : 'Never'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php echo panel_close(); ?>

        <!-- Offline ONUs -->
        <?php echo panel_open('Offline ONUs', '<a href="onus.php?status=offline" class="text-sm text-brand-600 dark:text-brand-400 font-medium">See all offline</a>'); ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="<?php echo UI_THEAD; ?>">
                        <tr><th class="<?php echo UI_TH; ?>">ONU</th><th class="<?php echo UI_TH; ?>">Serial Number</th><th class="<?php echo UI_TH; ?>">PON Port</th><th class="<?php echo UI_TH; ?>">Status</th><th class="<?php echo UI_TH; ?>">Customer</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                    <?php if (!$offlineList): ?>
                        <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400 dark:text-slate-500">Every ONU is online.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($offlineList as $u): ?>
                        <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                            <td class="<?php echo UI_TD; ?> font-medium"><a class="text-brand-600 dark:text-brand-400" href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description']); ?></a></td>
                            <td class="<?php echo UI_TD; ?> font-mono text-xs text-slate-600 dark:text-slate-300"><?php echo e($u['sn']); ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo e($u['olt_name'] . ' ' . $u['frame'] . '/' . $u['slot'] . '/' . $u['port'] . ' ID ' . $u['ont_id']); ?></td>
                            <td class="<?php echo UI_TD; ?>"><?php echo run_state_badge($u['run_state']); ?></td>
                            <td class="<?php echo UI_TD; ?> text-slate-600 dark:text-slate-300"><?php echo $u['customer_ref'] !== '' ? e($u['customer_ref']) : '<span class="text-slate-400">--</span>'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php echo panel_close(); ?>
    </main>

    <?php include __DIR__ . '/backend/templates/footer.php'; ?>

<!-- Chart Initialization Scripts (theme-aware) -->
<script>
    (function () {
        var bandLabels = <?php echo json_encode(array_keys($bands)); ?>;
        var bandValues = <?php echo json_encode(array_values($bands)); ?>;
        var portLabels = <?php echo json_encode(array_keys($perPort)); ?>;
        var portValues = <?php echo json_encode(array_values($perPort)); ?>;
        var signalChart, portChart;

        function isDark() { return document.documentElement.classList.contains('dark'); }
        function tickColor() { return isDark() ? '#cbd5e1' : '#475569'; }
        function gridColor() { return isDark() ? 'rgba(148, 163, 184, 0.15)' : 'rgba(148, 163, 184, 0.25)'; }
        function legendColor() { return isDark() ? '#e2e8f0' : '#334155'; }

        function build() {
            if (signalChart) signalChart.destroy();
            if (portChart) portChart.destroy();
            signalChart = new Chart(document.getElementById('signalChart').getContext('2d'), {
                type: 'doughnut',
                data: { labels: bandLabels, datasets: [{ data: bandValues, backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#94a3b8'], borderColor: isDark() ? '#1e293b' : '#ffffff', borderWidth: 3 }] },
                options: { responsive: true, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { color: legendColor(), font: { size: 12 }, padding: 16 } } } }
            });
            portChart = new Chart(document.getElementById('portChart').getContext('2d'), {
                type: 'bar',
                data: { labels: portLabels, datasets: [{ label: 'ONUs', data: portValues, backgroundColor: '#3b63f6', borderRadius: 8, borderSkipped: false }] },
                options: {
                    responsive: true, plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: tickColor(), font: { size: 11 } }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { stepSize: 1, color: tickColor() }, grid: { color: gridColor() } }
                    }
                }
            });
        }
        document.addEventListener('DOMContentLoaded', build);
        window.addEventListener('themechange', build);
    })();
</script>
