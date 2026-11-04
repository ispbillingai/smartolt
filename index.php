<?php
// Set session cookie parameters before starting the session
$session_lifetime = 30 * 24 * 60 * 60; // 30 days
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/',
    'domain' => '',
    'secure' => true, // Set to true if using HTTPS
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

require_once __DIR__ . '/backend/autoload/autoload.php';
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
                session_regenerate_id(true);
                setcookie(session_name(), session_id(), [
                    'expires' => time() + $session_lifetime,
                    'path' => '/',
                    'secure' => true, // Set to true if using HTTPS
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
        $master_email = trim($_POST['master_email'] ?? '');
        $result = $loginHandler->resetPassword($master_email);
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
    // Handle password change submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new_password']) && isset($_POST['confirm_password'])) {
        $new_password = trim($_POST['new_password'] ?? '');
        $confirm_password = trim($_POST['confirm_password'] ?? '');

        $result = $loginHandler->changePassword($new_password, $confirm_password);
        if ($result['success']) {
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } else {
            $error = $result['message'];
        }
    }

    // Show password change form
    include __DIR__ . '/backend/templates/change_password_form.php';
    exit;
}

// Establish database connection
try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// Fetch access points from the database (mirrored from the billing system).
// The dashboard is read-only: AP data lives in hotspot_aps, joined to
// hotspot_routers for the router name.
try {
    // The .biz app URL host, used to build the per-router access link
    $app_url = $database->getAppUrl();
    $appHost = parse_url($app_url, PHP_URL_HOST) ?: 'ispledger.click';
    $appParts = explode('.', $appHost);
    $appSub = $appParts[0] ?? '';
    $appRoot = implode('.', array_slice($appParts, 1)) ?: 'ispledger.click';

    // self-heal: the name column may not exist yet if the API hasn't run since
    // deploy. Add it so this read-only page never 500s on a missing column.
    try {
        $hasName = $db->query("SHOW COLUMNS FROM `hotspot_aps` LIKE 'name'")->fetch();
        if (!$hasName) {
            $db->exec("ALTER TABLE `hotspot_aps` ADD COLUMN `name` VARCHAR(100) NULL DEFAULT NULL AFTER `mac`");
        }
    } catch (Throwable $e) { /* table may not exist yet; the query below handles emptiness */ }

    $query = "SELECT a.id, a.router_id, a.mac, a.name, a.ip, a.port, r.router_name
              FROM hotspot_aps a
              LEFT JOIN hotspot_routers r ON r.router_id = a.router_id";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Shape rows to the keys the rest of this page expects
    $allDevices = [];
    foreach ($rows as $row) {
        $mac = (string)$row['mac'];
        $accessUrl = ($appSub !== '' && !empty($row['port']))
            ? 'http://' . $appSub . (int)$row['router_id'] . '.' . $appRoot . ':' . (int)$row['port']
            : '';
        $allDevices[] = [
            'id'             => $row['id'],
            'name'           => (trim((string)($row['name'] ?? '')) !== '') ? $row['name'] : 'AP ' . strtoupper($mac),
            'type'           => 'Static',
            'device_type'    => 'Other',
            'mac'            => $mac,
            'ip_address'     => $row['ip'] ?: '',
            'pppoe_username' => '',
            'router_id'      => $row['router_id'],
            'port'           => $row['port'] ?? '',
            'access_url'     => $accessUrl,
            'router_name'    => $row['router_name'] ?? '',
        ];
    }

    // Fetch all routers for counting
    $stmt = $db->prepare("SELECT router_id AS id, router_name AS name FROM hotspot_routers");
    $stmt->execute();
    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $routerCount = count($routers);

    // Process devices and add simulated data
    foreach ($allDevices as &$device) {
        // Simulate Radio Type for Ubiquiti devices
        if ($device['device_type'] === 'Ubiquiti') {
            $radioTypes = ['NanoStation', 'PowerBeam', 'LiteBeam'];
            $device['radio_type'] = $radioTypes[array_rand($radioTypes)];
        } else {
            $device['radio_type'] = 'N/A';
        }

        // Ensure access_url is set
        $device['access_url'] = $device['access_url'] ?? "http://{$device['ip_address']}:{$device['port']}";
    }
    unset($device);

    // Category filter
    $categoryFilter = isset($_GET['category']) ? $_GET['category'] : 'all';
    if ($categoryFilter !== 'all') {
        $allDevices = array_filter($allDevices, function($device) use ($categoryFilter) {
            return $device['device_type'] === $categoryFilter;
        });
    }

    // Calculate statistics (reused by the stat cards + the distribution chart)
    $totalDevices = count($allDevices);
    // "with IP" vs "waiting for IP" for access points
    $pppoeCount = count(array_filter($allDevices, function($device) {
        return trim((string)($device['ip_address'] ?? '')) !== '';
    }));
    $staticCount = count(array_filter($allDevices, function($device) {
        return trim((string)($device['ip_address'] ?? '')) === '';
    }));

    // Device type counts
    $tendaCount = count(array_filter($allDevices, function($device) {
        return $device['device_type'] === 'Tenda';
    }));
    $ubiquitiCount = count(array_filter($allDevices, function($device) {
        return $device['device_type'] === 'Ubiquiti';
    }));
    $huaweiCount = count(array_filter($allDevices, function($device) {
        return $device['device_type'] === 'Huawei';
    }));
    $tplinkCount = count(array_filter($allDevices, function($device) {
        return $device['device_type'] === 'TP-Link';
    }));
    $otherCount = count(array_filter($allDevices, function($device) {
        return $device['device_type'] === 'Other';
    }));

    // Prepare data for Most Used Device Types card (top 3 device types)
    $deviceTypeCounts = [
        'Tenda' => $tendaCount,
        'Ubiquiti' => $ubiquitiCount,
        'Huawei' => $huaweiCount,
        'TP-Link' => $tplinkCount,
        'Other' => $otherCount
    ];
    arsort($deviceTypeCounts); // Sort by count in descending order
    $topDeviceTypes = array_slice($deviceTypeCounts, 0, 3, true); // Get top 3
    $topDeviceTypesLabels = array_keys($topDeviceTypes);
    $topDeviceTypesValues = array_values($topDeviceTypes);

    // Prepare chart data
    $typeDistribution = [
        'Tenda' => $tendaCount,
        'Ubiquiti' => $ubiquitiCount,
        'Huawei' => $huaweiCount,
        'TP-Link' => $tplinkCount,
        'Other' => $otherCount
    ];
    $pppoeVsStaticDistribution = [
        'With IP' => $pppoeCount,
        'Waiting for IP' => $staticCount
    ];

    $typeLabels = array_keys($typeDistribution);
    $typeValues = array_values($typeDistribution);
    $pppoeVsStaticLabels = array_keys($pppoeVsStaticDistribution);
    $pppoeVsStaticValues = array_values($pppoeVsStaticDistribution);

    // Sorting logic for all devices
    $sortField = isset($_GET['sort']) ? $_GET['sort'] : 'name';
    $sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'asc' : 'desc';

    $validSortFields = ['name', 'type', 'device_type', 'ip_address', 'pppoe_username', 'router_name', 'port'];
    if (!in_array($sortField, $validSortFields)) {
        $sortField = 'name';
    }

    usort($allDevices, function($a, $b) use ($sortField, $sortOrder) {
        $aValue = $a[$sortField] ?? '';
        $bValue = $b[$sortField] ?? '';
        if ($sortOrder === 'asc') {
            return $aValue <=> $bValue;
        } else {
            return $bValue <=> $aValue;
        }
    });

    // Get the last 10 devices added (sorted by id descending)
    usort($allDevices, function($a, $b) {
        return $b['id'] <=> $a['id']; // Sort by id descending
    });
    $devices = array_slice($allDevices, 0, 10); // Take the first 10 devices

} catch (Exception $e) {
    error_log("Error processing devices: " . $e->getMessage());
    die("Error processing devices: " . $e->getMessage());
}

// Include the header
include __DIR__ . '/backend/templates/header.php';

// Include the sidebar
include __DIR__ . '/backend/templates/sidebar.php';
?>

<!-- Main Content -->
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <div class="mb-6">
            <h2 class="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white tracking-tight">Overview</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Devices, routers, and traffic types across your LAN.</p>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <!-- Total Devices -->
            <div class="relative overflow-hidden bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-5 hover:-translate-y-0.5 transition">
                <div class="absolute -top-8 -right-8 w-32 h-32 bg-gradient-to-br from-brand-200/40 to-brand-400/20 dark:from-brand-500/20 dark:to-brand-700/10 rounded-full blur-2xl"></div>
                <div class="relative flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-brand-50 dark:bg-brand-900/40 text-brand-600 dark:text-brand-300 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Total Access Points</p>
                        <h3 class="text-2xl font-bold text-slate-900 dark:text-white mt-0.5"><?php echo $totalDevices; ?></h3>
                    </div>
                </div>
            </div>

            <!-- PPPoE Devices -->
            <div class="relative overflow-hidden bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-5 hover:-translate-y-0.5 transition">
                <div class="absolute -top-8 -right-8 w-32 h-32 bg-gradient-to-br from-emerald-200/40 to-emerald-400/20 dark:from-emerald-500/20 dark:to-emerald-700/10 rounded-full blur-2xl"></div>
                <div class="relative flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-300 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">With IP</p>
                        <h3 class="text-2xl font-bold text-slate-900 dark:text-white mt-0.5"><?php echo $pppoeCount; ?></h3>
                    </div>
                </div>
            </div>

            <!-- Static Devices -->
            <div class="relative overflow-hidden bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-5 hover:-translate-y-0.5 transition">
                <div class="absolute -top-8 -right-8 w-32 h-32 bg-gradient-to-br from-amber-200/40 to-amber-400/20 dark:from-amber-500/20 dark:to-amber-700/10 rounded-full blur-2xl"></div>
                <div class="relative flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-amber-50 dark:bg-amber-900/40 text-amber-600 dark:text-amber-300 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Waiting for IP</p>
                        <h3 class="text-2xl font-bold text-slate-900 dark:text-white mt-0.5"><?php echo $staticCount; ?></h3>
                    </div>
                </div>
            </div>

            <!-- Routers -->
            <div class="relative overflow-hidden bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-5 hover:-translate-y-0.5 transition">
                <div class="absolute -top-8 -right-8 w-32 h-32 bg-gradient-to-br from-violet-200/40 to-violet-400/20 dark:from-violet-500/20 dark:to-violet-700/10 rounded-full blur-2xl"></div>
                <div class="relative flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-violet-50 dark:bg-violet-900/40 text-violet-600 dark:text-violet-300 flex items-center justify-center">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path></svg>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Routers</p>
                        <h3 class="text-2xl font-bold text-slate-900 dark:text-white mt-0.5"><?php echo $routerCount; ?></h3>
                    </div>
                </div>
            </div>

            <!-- Most Used Device Types -->
            <div class="relative overflow-hidden bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-5 hover:-translate-y-0.5 transition">
                <div class="absolute -top-8 -right-8 w-32 h-32 bg-gradient-to-br from-teal-200/40 to-teal-400/20 dark:from-teal-500/20 dark:to-teal-700/10 rounded-full blur-2xl"></div>
                <div class="relative flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-teal-50 dark:bg-teal-900/40 text-teal-600 dark:text-teal-300 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"></path></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-xs uppercase tracking-wide text-slate-500 dark:text-slate-400">Top Types</p>
                        <p class="text-xs text-slate-700 dark:text-slate-200 mt-1 truncate">
                            <?php
                            $topDeviceTypesDisplay = [];
                            foreach ($topDeviceTypes as $type => $count) {
                                $topDeviceTypesDisplay[] = "$type: $count";
                            }
                            echo htmlspecialchars(implode(', ', $topDeviceTypesDisplay));
                            ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <!-- Device Type Distribution -->
            <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">Device Types</h3>
                <canvas id="deviceCategoryChart" height="200"></canvas>
            </div>

            <!-- PPPoE vs Static Distribution -->
            <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6">
                <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">IP Status</h3>
                <canvas id="pppoeVsStaticChart" height="200"></canvas>
            </div>
        </div>

        <!-- Devices Table (Last 10 Devices Added) -->
        <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft">
            <div class="p-6">
                <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-4 gap-3">
                    <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                        <svg class="w-5 h-5 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                        </svg>
                        Last 10 Access Points Added
                    </h3>
                    <div class="flex flex-wrap gap-3 items-center">
                        <div class="flex flex-wrap gap-x-3 gap-y-1">
                            <a href="?sort=name&order=<?php echo $sortField === 'name' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>"
                               class="text-sm <?php echo $sortField === 'name' ? 'text-brand-600 dark:text-brand-400 font-semibold' : 'text-slate-500 dark:text-slate-400'; ?> hover:text-brand-700 dark:hover:text-brand-300">
                                Name <?php echo $sortField === 'name' ? ($sortOrder === 'asc' ? '↑' : '↓') : ''; ?>
                            </a>
                            <a href="?sort=ip_address&order=<?php echo $sortField === 'ip_address' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>"
                               class="text-sm <?php echo $sortField === 'ip_address' ? 'text-brand-600 dark:text-brand-400 font-semibold' : 'text-slate-500 dark:text-slate-400'; ?> hover:text-brand-700 dark:hover:text-brand-300">
                                IP <?php echo $sortField === 'ip_address' ? ($sortOrder === 'asc' ? '↑' : '↓') : ''; ?>
                            </a>
                            <a href="?sort=router_name&order=<?php echo $sortField === 'router_name' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>"
                               class="text-sm <?php echo $sortField === 'router_name' ? 'text-brand-600 dark:text-brand-400 font-semibold' : 'text-slate-500 dark:text-slate-400'; ?> hover:text-brand-700 dark:hover:text-brand-300">
                                Router <?php echo $sortField === 'router_name' ? ($sortOrder === 'asc' ? '↑' : '↓') : ''; ?>
                            </a>
                            <a href="?sort=port&order=<?php echo $sortField === 'port' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>"
                               class="text-sm <?php echo $sortField === 'port' ? 'text-brand-600 dark:text-brand-400 font-semibold' : 'text-slate-500 dark:text-slate-400'; ?> hover:text-brand-700 dark:hover:text-brand-300">
                                Port <?php echo $sortField === 'port' ? ($sortOrder === 'asc' ? '↑' : '↓') : ''; ?>
                            </a>
                        </div>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide bg-slate-50 dark:bg-slate-800/40 border-y border-slate-200 dark:border-slate-700">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">Name</th>
                                <th scope="col" class="px-4 py-3 font-medium">MAC</th>
                                <th scope="col" class="px-4 py-3 font-medium">IP Address</th>
                                <th scope="col" class="px-4 py-3 font-medium">Status</th>
                                <th scope="col" class="px-4 py-3 font-medium">Router</th>
                                <th scope="col" class="px-4 py-3 font-medium">Port</th>
                                <th scope="col" class="px-4 py-3 font-medium">Access</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <?php if (empty($devices)): ?>
                                <tr>
                                    <td colspan="7" class="px-4 py-8 text-center text-slate-400 dark:text-slate-500">No access points yet. Add them from your billing system.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($devices as $device): ?>
                                    <?php $hasIp = trim((string)($device['ip_address'] ?? '')) !== ''; ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                                        <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($device['name']); ?></td>
                                        <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($device['mac'] ?? ''); ?></td>
                                        <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300"><?php echo $hasIp ? htmlspecialchars($device['ip_address']) : '<span class="text-slate-400">waiting...</span>'; ?></td>
                                        <td class="px-4 py-3">
                                            <?php if ($hasIp): ?>
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 px-2 py-0.5 text-xs font-medium">Ready</span>
                                            <?php else: ?>
                                                <span class="inline-flex items-center rounded-full bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 px-2 py-0.5 text-xs font-medium">Waiting</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($device['router_name']); ?></td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars((string)$device['port']); ?></td>
                                        <td class="px-4 py-3">
                                            <?php if ($hasIp && $device['access_url'] !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($device['access_url']); ?>"
                                                   target="_blank"
                                                   class="inline-flex items-center gap-1 text-brand-600 dark:text-brand-400 hover:text-brand-700 dark:hover:text-brand-300 text-xs font-medium">
                                                    Access
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                                    </svg>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-slate-400 text-xs">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
</div>

<!-- Chart Initialization Scripts (theme-aware) -->
<script>
    (function () {
        var typeLabels = <?php echo json_encode($typeLabels); ?>;
        var typeValues = <?php echo json_encode($typeValues); ?>;
        var pppoeVsStaticLabels = <?php echo json_encode($pppoeVsStaticLabels); ?>;
        var pppoeVsStaticValues = <?php echo json_encode($pppoeVsStaticValues); ?>;

        var categoryChart, pppoeChart;

        function isDark() { return document.documentElement.classList.contains('dark'); }
        function tickColor() { return isDark() ? '#cbd5e1' : '#475569'; }
        function gridColor() { return isDark() ? 'rgba(148, 163, 184, 0.15)' : 'rgba(148, 163, 184, 0.25)'; }
        function legendColor() { return isDark() ? '#e2e8f0' : '#334155'; }

        function buildCategoryChart() {
            if (categoryChart) categoryChart.destroy();
            var ctx = document.getElementById('deviceCategoryChart').getContext('2d');
            categoryChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: typeLabels,
                    datasets: [{
                        label: 'Number of Devices',
                        data: typeValues,
                        backgroundColor: ['#3b63f6', '#10b981', '#f59e0b', '#a855f7', '#ef4444'],
                        borderRadius: 8,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { ticks: { color: tickColor(), font: { size: 12 } }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { stepSize: 1, color: tickColor(), font: { size: 12 } }, grid: { color: gridColor() } }
                    }
                }
            });
        }

        function buildPppoeChart() {
            if (pppoeChart) pppoeChart.destroy();
            var ctx = document.getElementById('pppoeVsStaticChart').getContext('2d');
            pppoeChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: pppoeVsStaticLabels,
                    datasets: [{
                        data: pppoeVsStaticValues,
                        backgroundColor: ['#10b981', '#f59e0b'],
                        borderColor: isDark() ? '#1e293b' : '#ffffff',
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    cutout: '65%',
                    plugins: {
                        legend: { position: 'bottom', labels: { color: legendColor(), font: { size: 12 }, padding: 16 } }
                    }
                }
            });
        }

        function buildAll() { buildCategoryChart(); buildPppoeChart(); }
        document.addEventListener('DOMContentLoaded', buildAll);
        window.addEventListener('themechange', buildAll);
    })();
</script>