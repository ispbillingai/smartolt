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

// ----- Dashboard figures, all from the database copy (the 5 minute read keeps it fresh) -----
$olts = $mgr->olts();
$c = $mgr->counts();
$net = $mgr->networkStatus(24);
$out = $mgr->outages();
$info = array_slice(array_values(array_filter($mgr->actionsFiltered([], 60), function ($a) {
    return !in_array($a['action'], ['onu.status', 'onu.autofind', 'olt.diag', 'onu.config'], true) && !($a['action'] === 'olt.refresh' && $a['source'] === 'cron' && $a['ok']);
})), 0, 8);
$oltId = (int)($_GET['olt'] ?? ($olts[0]['id'] ?? 0));
$olt = null;
foreach ($olts as $o) if ((int)$o['id'] === $oltId) $olt = $o;
$boards = $olt ? count(json_decode((string)$olt['boards_json'], true) ?: []) : 0;
$dash = function ($n) { return $n ? (int)$n : '-'; };

$pageTitle = 'Dashboard';
include __DIR__ . '/backend/templates/header.php';
?>
<?php echo flash_messages(); ?>
<?php if (!$olts): ?>
    <div class="so-alert a-info">Add your OLT to get started. SmartOLT reads its boards, profiles and every ONU on it. <a href="olts.php?add=1"><b>Add OLT</b></a></div>
<?php endif; ?>

<div class="so-cards">
    <a class="so-card c-blue" href="unconfigured.php">
        <div class="so-card-top"><i class="fa fa-magic"></i><div class="so-card-num"><b><?php echo (int)$c['waiting']; ?></b><span>Waiting authorization</span></div></div>
        <div class="so-card-foot"><span title="Serials already authorized somewhere, plugged in again">D: <?php echo (int)$c['moved']; ?></span><span title="ONUs whose setup on the OLT differs from SmartOLT">Resync: <?php echo (int)$c['resync']; ?></span><span>New: <?php echo (int)$c['new']; ?></span></div>
    </a>
    <a class="so-card c-green" href="onus.php?status=online">
        <div class="so-card-top"><i class="fa fa-tasks"></i><div class="so-card-num"><b><?php echo (int)$c['online']; ?></b><span>Online</span></div></div>
        <div class="so-card-foot"><span>Total authorized: <?php echo (int)$c['total']; ?></span></div>
    </a>
    <a class="so-card c-grey" href="onus.php?status=offline">
        <div class="so-card-top"><i class="fa fa-times"></i><div class="so-card-num"><b><?php echo (int)$c['offline']; ?></b><span>Total offline</span></div></div>
        <div class="so-card-foot"><span>PwrFail: <?php echo (int)$c['power_fail']; ?> &nbsp;LoS: <?php echo (int)$c['los']; ?></span><span>N/A: <?php echo (int)$c['other_off'] + (int)$c['disabled']; ?></span></div>
    </a>
    <a class="so-card c-orange" href="onus.php?signal=weak">
        <div class="so-card-top"><i class="fa fa-exclamation-circle"></i><div class="so-card-num"><b><?php echo (int)$c['warning'] + (int)$c['critical']; ?></b><span>Low signals</span></div></div>
        <div class="so-card-foot"><span>Warning: <?php echo $dash($c['warning']); ?></span><span>Critical: <?php echo $dash($c['critical']); ?></span></div>
    </a>
</div>
<div class="so-live">
    <a href="#" id="autoRefresh" style="color:inherit"><span class="so-dot" id="autoDot"></span><span id="autoText">Auto-refresh on</span></a> |
    <span><span class="so-dot"></span>OLTs read every 5 minutes <i class="fa fa-info-circle" title="Statuses, causes and signals come from reading every OLT every 5 minutes. Open an ONU to read it live."></i></span>
</div>

<div class="so-two">
    <div>
        <?php echo panel_open('Network status', '<span style="position:relative"><a href="#" id="moreGraphs">More graphs <span class="so-caret"></span></a>'
            . '<span class="so-drop" id="moreGraphsMenu" style="right:0;left:auto"><a href="graphs.php?range=24">Last 24 hours</a><a href="graphs.php?range=168">Last week</a><a href="graphs.php?range=720">Last month</a><a href="graphs.php?view=pon">PON ports</a></span></span>', 'bar-chart'); ?>
            <?php if (count($net) < 2): ?><p class="so-muted" style="text-align:center;padding:50px 0">The graph fills in as your OLTs are read every 5 minutes.</p>
            <?php else: ?><canvas id="netChart" height="110"></canvas><?php endif; ?>
        <?php echo panel_close(); ?>
    </div>
    <div>
        <div class="so-panel">
            <div class="so-panel-h"><span>OLT <?php echo $olt ? e($olt['model'] !== '' ? 'Huawei ' . $olt['model'] : 'not read yet') : ''; ?></span>
                <?php if ($olts): ?>
                <form method="GET" class="so-tools"><select name="olt" class="so-select" style="height:30px;padding:2px 6px;width:auto;font-size:13px" onchange="this.form.submit()"><?php echo olt_options($olts, $oltId); ?></select>
                    <span class="so-dot<?php echo $olt && $olt['status'] === 'online' ? '' : ' off'; ?>" title="<?php echo $olt && $olt['status'] === 'online' ? 'Connected' : 'Cannot connect'; ?>"></span></form>
                <?php endif; ?>
            </div>
            <div class="so-panel-b">
                <?php if (!$olt): ?>
                    <p class="so-muted">No OLT yet. <a href="olts.php?add=1">Add OLT</a></p>
                <?php else: ?>
                    <a class="so-olt-pic" href="olt.php?id=<?php echo $oltId; ?>"><?php echo olt_picture(max(8, min(21, $boards ?: 16))); ?></a>
                    <div class="so-olt-icons">
                        <a href="graphs.php?olt=<?php echo $oltId; ?>" title="Graphs"><i class="fa fa-area-chart"></i></a>
                        <a href="reports.php?r=status&olt=<?php echo $oltId; ?>" title="Status changes" style="color:var(--orange)"><i class="fa fa-bell"></i></a>
                        <a href="olts.php?edit=<?php echo $oltId; ?>" title="OLT settings" style="color:var(--text)"><i class="fa fa-cog"></i></a>
                        <a href="diag.php?olt=<?php echo $oltId; ?>" title="Diagnostics" style="color:var(--green)"><i class="fa fa-terminal"></i></a>
                    </div>
                    <div class="so-kv"><span><i class="fa fa-cogs"></i> Uptime</span><i><?php echo e($olt['uptime'] !== '' ? $olt['uptime'] : '-'); ?><?php echo $olt['temperature'] !== '' ? ', ' . e($olt['temperature']) . '°C' : ''; ?></i></div>
                    <?php if ($olt['status'] === 'error'): ?><div class="so-alert a-err" style="margin:10px 0 0"><?php echo e($olt['last_error']); ?></div><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="so-two">
    <div>
        <?php
        $rows = [
            ['variations', 'Signal variations', 'signal', 'var(--green)', count($out['variations'])],
            ['partial_los', 'Partial LOS', 'chain-broken', 'var(--orange)', count($out['partial_los'])],
            ['full_los', 'Full PON LOS', 'chain-broken', 'var(--red)', count($out['full_los'])],
            ['power_fail', 'Power fail', 'plug', '#4e8cff', count($out['power_fail'])],
            ['na', 'Offline N/A', 'globe', '#9aa5b8', count($out['na'])],
        ];
        echo panel_open('PON outage', '<a href="reports.php?r=status" title="Status changes"><i class="fa fa-bell"></i></a><a href="help.php#outage" title="What these mean"><i class="fa fa-cog"></i></a>', 'heartbeat');
        foreach ($rows as [$key, $label, $icon, $color, $n]): ?>
            <details class="so-acc">
                <summary><i class="fa fa-caret-right"></i><i class="fa fa-<?php echo $icon; ?>" style="color:<?php echo $color; ?>"></i> <?php echo $label; ?>
                    <?php echo $n ? badge((string)$n, 'red') : '<i class="fa fa-check-circle" style="color:var(--green)"></i>'; ?></summary>
                <div class="so-acc-b">
                    <?php if (!$n): ?><span class="so-muted">Nothing right now.</span><?php endif; ?>
                    <?php if ($key === 'variations'): foreach ($out['variations'] as $u): ?>
                        <div><a href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description']); ?></a> <?php echo e($u['olt_name']); ?>: now <?php echo number_format((float)$u['rx_power'], 2); ?> dBm, usually <?php echo number_format((float)$u['avg_rx'], 2); ?> dBm</div>
                    <?php endforeach; elseif ($key === 'na'): foreach ($out['na'] as $u): ?>
                        <div><a href="onu.php?id=<?php echo (int)$u['id']; ?>"><?php echo e($u['description']); ?></a> <?php echo e($u['olt_name'] . ' ' . onu_place($u)); ?>, down <?php echo ago($u['last_down_at']); ?></div>
                    <?php endforeach; else: foreach ($out[$key] as $p): $pon = "{$p['frame']}/{$p['slot']}/{$p['port']}"; ?>
                        <div><a href="onus.php?olt=<?php echo (int)$p['olt_id']; ?>&pon=<?php echo $pon; ?>"><?php echo e($p['olt_name']); ?> PON <?php echo $pon; ?></a>:
                            <?php echo $key === 'power_fail' ? (int)$p['pf'] . ' power fail' : (int)$p['los'] . ' LOS'; ?> of <?php echo (int)$p['total']; ?> ONUs</div>
                    <?php endforeach; endif; ?>
                </div>
            </details>
        <?php endforeach;
        echo panel_close(); ?>
    </div>
    <div>
        <?php echo panel_open('Info', '', 'info-circle'); ?>
            <?php if (!$info): ?><p class="so-muted" style="margin:0">Authorizations and changes on your OLTs show here.</p><?php endif; ?>
            <ul class="so-info">
                <?php foreach ($info as $a): ?>
                    <li><span><i class="fa fa-<?php echo $a['ok'] ? 'lock' : 'exclamation-triangle'; ?>"></i> <?php echo e(action_text($a)); ?></span><i class="ago"><?php echo ago($a['created_at']); ?></i></li>
                <?php endforeach; ?>
            </ul>
            <?php if ($info): ?><a href="events.php">All events</a><?php endif; ?>
        <?php echo panel_close(); ?>
    </div>
</div>

<script>
(function () {
    // Reload the figures every minute unless switched off; remembered per browser.
    var on = true;
    try { on = localStorage.getItem('smartolt-autorefresh') !== 'off'; } catch (e) {}
    function show() {
        document.getElementById('autoDot').className = 'so-dot' + (on ? '' : ' off');
        document.getElementById('autoText').textContent = on ? 'Auto-refresh on' : 'Auto-refresh off';
    }
    show();
    var timer = setInterval(function () { if (on && !document.querySelector('.so-modal.show') && !document.querySelector('details[open]')) location.reload(); }, 60000);
    document.getElementById('autoRefresh').addEventListener('click', function (e) {
        e.preventDefault(); on = !on; show();
        try { localStorage.setItem('smartolt-autorefresh', on ? 'on' : 'off'); } catch (err) {}
    });
    var mg = document.getElementById('moreGraphs'), mgm = document.getElementById('moreGraphsMenu');
    mg.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); mgm.style.display = mgm.style.display === 'block' ? 'none' : 'block'; });
    document.addEventListener('click', function () { mgm.style.display = 'none'; });

    var el = document.getElementById('netChart');
    if (!el) return;
    var net = <?php echo json_encode(array_map(function ($r) { return ['t' => date('H:i', $r['t']), 'on' => $r['online'], 'pf' => $r['power_fail'], 'los' => $r['los'], 'na' => max(0, $r['offline'] - $r['los'] - $r['power_fail'])]; }, $net)); ?>;
    var last = net[net.length - 1], chart;
    function build() {
        if (chart) chart.destroy();
        var ds = function (label, key, color, fill) {
            return { label: label + ' (' + last[key] + ')', data: net.map(function (p) { return p[key]; }), borderColor: color, backgroundColor: fill || 'transparent', fill: !!fill, pointRadius: 0, tension: 0.2, borderWidth: 2 };
        };
        chart = new Chart(el.getContext('2d'), {
            type: 'line',
            data: { labels: net.map(function (p) { return p.t; }), datasets: [
                ds('Online', 'on', '#2ecc40', 'rgba(46,204,64,0.12)'), ds('Power Fail', 'pf', '#4e8cff'), ds('LOS', 'los', '#f5a623'), ds('N/A', 'na', '#9aa5b8')
            ] },
            options: { responsive: true, interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom', labels: { color: soChart.tick(), boxWidth: 10, font: { weight: 'bold', size: 11 } } } },
                scales: { x: { ticks: { color: soChart.tick(), maxTicksLimit: 7 }, grid: { display: false } },
                    y: { beginAtZero: true, ticks: { color: soChart.tick(), precision: 0 }, grid: { color: soChart.grid() } } } }
        });
    }
    build();
    window.addEventListener('themechange', build);
})();
</script>
<?php include __DIR__ . '/backend/templates/footer.php'; ?>
