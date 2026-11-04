<?php
$session_lifetime = 30 * 24 * 60 * 60; // 30 days
session_set_cookie_params([
    'lifetime' => $session_lifetime,
    'path' => '/',
    'domain' => '',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Strict'
]);
session_start();

require_once __DIR__ . '/backend/autoload/autoload.php';
require_once __DIR__ . '/backend/config/database.php';
require_once __DIR__ . '/backend/auth/login.php';

function logError($message) {
    error_log("[CUSTOM LOG] " . $message);
}

function validateLength($string, $max, $min) {
    $length = strlen($string);
    return $length >= $min && $length <= $max;
}

function langT($message) {
    return $message;
}

function r2($url, $type, $message) {
    $_SESSION[$type === 's' ? 'success' : 'error'] = $message;
    header("Location: $url");
    exit;
}

$loginHandler = new LoginHandler();

if (!$loginHandler->isLoggedIn()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username']) && isset($_POST['password'])) {
        $result = $loginHandler->login($_POST['username'], $_POST['password']);
        if (!$result['success']) {
            $error = $result['message'];
        } else {
            if (isset($_POST['remember_me'])) {
                session_regenerate_id(true);
                setcookie(session_name(), session_id(), [
                    'expires' => time() + $session_lifetime,
                    'path' => '/',
                    'secure' => true,
                    'httponly' => true,
                    'samesite' => 'Strict'
                ]);
            }
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
        $master_email = trim($_POST['master_email'] ?? '');
        $result = $loginHandler->resetPassword($master_email);
        if ($result['success']) {
            $success_message = $result['message'];
        } else {
            $error = $result['message'];
        }
    }

    include __DIR__ . '/backend/templates/login_form.php';
    exit;
}

if ($loginHandler->needsPasswordChange()) {
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

    include __DIR__ . '/backend/templates/change_password_form.php';
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    logError("Database connection failed: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// Get the app_url from the Database class using the getter method
$app_url = $database->getAppUrl();
$parsed_url = parse_url($app_url);
$base_domain = $parsed_url['host'] ?? 'devices.ispledger.com';
$domain_parts = explode('.', $base_domain);
$subdomain_prefix = $domain_parts[0];
$domain_suffix = implode('.', array_slice($domain_parts, 1));

// Fetch all MikroTik routers
try {
    $stmt = $db->prepare("SELECT id, name FROM tbl_routers ORDER BY id");
    $stmt->execute();
    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logError("Error fetching routers: " . $e->getMessage());
    die("Error fetching routers: " . $e->getMessage());
}

// The access points shown here are managed from the billing system. This page
// is a read-only mirror: their data lives in `hotspot_aps` (pushed by the
// billing system), joined to `hotspot_routers` for the router name.
$itemsPerPage = 10;
$page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $itemsPerPage;

// Search (by AP name/MAC/IP)
$searchQuery = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchCondition = '';
$searchParams = [];
if ($searchQuery !== '') {
    $searchCondition = "WHERE (a.mac LIKE :search OR a.ip LIKE :search OR a.name LIKE :search)";
    $searchParams[':search'] = "%$searchQuery%";
}

// Sorting
$sortField = isset($_GET['sort']) ? $_GET['sort'] : 'id';
$sortOrder = isset($_GET['order']) && in_array($_GET['order'], ['asc', 'desc']) ? $_GET['order'] : 'asc';
$sortMap = [
    'name'        => 'a.name',
    'ip_address'  => 'a.ip',
    'router_name' => 'r.router_name',
    'port'        => 'a.port',
    'id'          => 'a.id',
];
$sortColumn = $sortMap[$sortField] ?? 'a.id';

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
} catch (Throwable $e) { /* table may not exist yet; queries below handle emptiness */ }

// Count for pagination
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM hotspot_aps a LEFT JOIN hotspot_routers r ON r.router_id = a.router_id $searchCondition");
    $stmt->execute($searchParams);
    $totalDevices = (int)$stmt->fetchColumn();
    $totalPages = (int)ceil($totalDevices / $itemsPerPage);
} catch (PDOException $e) {
    logError("Error counting access points: " . $e->getMessage());
    $totalDevices = 0; $totalPages = 0;
}

// Fetch the current page and shape rows to the keys the table already renders
$devices = [];
try {
    $query = "SELECT a.id, a.router_id, a.mac, a.name, a.ip, a.port, r.router_name
              FROM hotspot_aps a
              LEFT JOIN hotspot_routers r ON r.router_id = a.router_id
              $searchCondition
              ORDER BY $sortColumn $sortOrder
              LIMIT :offset, :itemsPerPage";
    $stmt = $db->prepare($query);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->bindValue(':itemsPerPage', $itemsPerPage, PDO::PARAM_INT);
    foreach ($searchParams as $param => $value) {
        $stmt->bindValue($param, $value);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        $mac = (string)$row['mac'];
        $accessUrl = ($appSub !== '' && !empty($row['port']))
            ? 'http://' . $appSub . (int)$row['router_id'] . '.' . $appRoot . ':' . (int)$row['port']
            : '';
        $devices[] = [
            'id'             => $row['id'],
            'name'           => (trim((string)($row['name'] ?? '')) !== '') ? $row['name'] : 'AP ' . strtoupper($mac),
            'type'           => 'Access Point',
            'device_type'    => $mac,                 // show the MAC under Device Type
            'ip_address'     => $row['ip'] ?: '',
            'pppoe_username' => '',
            'router_id'      => $row['router_id'],
            'router_name'    => $row['router_name'] ?? '',
            'port'           => $row['port'] ?? '',
            'access_url'     => $accessUrl,
        ];
    }
} catch (PDOException $e) {
    logError("Error fetching access points: " . $e->getMessage());
    $devices = [];
}

$errors = [];
$success_message = '';
$usedPortsByRouter = [];
$usedPorts = [];
$availablePorts = range(200, 400);

include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>

<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <div class="mb-6">
            <h2 class="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white tracking-tight">Devices</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Access points behind your routers. These are added and managed from your billing system; this page mirrors them for remote access.</p>
        </div>

        <!-- Devices Table Section -->
        <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft">
            <div class="p-6">
                <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-6 gap-3">
                    <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                        <svg class="w-5 h-5 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        Managed Devices
                    </h3>
                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-2 w-full md:w-auto">
                        <form method="GET" action="" class="flex items-stretch w-full md:w-auto">
                            <div class="relative w-full md:w-72">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-400">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"></path></svg>
                                </span>
                                <input type="text" name="search" placeholder="Search by MAC or IP..." value="<?php echo htmlspecialchars($searchQuery); ?>" class="w-full rounded-l-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 pl-9 pr-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition">
                            </div>
                            <button type="submit" class="rounded-r-lg bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium border border-brand-600 hover:border-brand-700 transition">Search</button>
                        </form>
                        <select onchange="location = this.value;" class="text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-700 dark:text-slate-200 px-3 py-2 shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition">
                            <option value="">Sort By</option>
                            <?php
                            $sortOptions = [
                                ['label' => 'MAC (A-Z)', 'field' => 'name', 'order' => 'asc'],
                                ['label' => 'MAC (Z-A)', 'field' => 'name', 'order' => 'desc'],
                                ['label' => 'IP Address (Asc)', 'field' => 'ip_address', 'order' => 'asc'],
                                ['label' => 'IP Address (Desc)', 'field' => 'ip_address', 'order' => 'desc'],
                                ['label' => 'Router (A-Z)', 'field' => 'router_name', 'order' => 'asc'],
                                ['label' => 'Router (Z-A)', 'field' => 'router_name', 'order' => 'desc'],
                                ['label' => 'Port (Low to High)', 'field' => 'port', 'order' => 'asc'],
                                ['label' => 'Port (High to Low)', 'field' => 'port', 'order' => 'desc'],
                            ];
                            foreach ($sortOptions as $option): ?>
                                <option value="?sort=<?php echo $option['field']; ?>&order=<?php echo $option['order']; ?>&search=<?php echo urlencode($searchQuery); ?>&page=<?php echo $page; ?>" <?php echo $sortField === $option['field'] && $sortOrder === $option['order'] ? 'selected' : ''; ?>>
                                    <?php echo $option['label']; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php if (isset($_SESSION['error'])): ?>
                    <div class="mb-4 p-4 bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-800/50 text-rose-700 dark:text-rose-300 rounded-xl flex items-center gap-2">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        <span><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (isset($_SESSION['success'])): ?>
                    <div class="mb-4 p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800/50 text-emerald-700 dark:text-emerald-300 rounded-xl flex items-center gap-2">
                        <svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                        <span><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide bg-slate-50 dark:bg-slate-800/40 border-y border-slate-200 dark:border-slate-700">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">#</th>
                                <th scope="col" class="px-4 py-3 font-medium">Name</th>
                                <th scope="col" class="px-4 py-3 font-medium">Type</th>
                                <th scope="col" class="px-4 py-3 font-medium">MAC</th>
                                <th scope="col" class="px-4 py-3 font-medium">IP Address</th>
                                <th scope="col" class="px-4 py-3 font-medium">Router</th>
                                <th scope="col" class="px-4 py-3 font-medium">Port</th>
                                <th scope="col" class="px-4 py-3 font-medium">Access URL</th>
                                <th scope="col" class="px-4 py-3 font-medium">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <?php if (empty($devices)): ?>
                                <tr>
                                    <td colspan="9" class="px-4 py-8 text-center text-slate-400 dark:text-slate-500">No access points yet. Add them from your billing system.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($devices as $index => $device): ?>
                                    <?php $rowNumber = ($page - 1) * $itemsPerPage + $index + 1; ?>
                                    <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                                        <td class="px-4 py-3 text-slate-500 dark:text-slate-400"><?php echo $rowNumber; ?></td>
                                        <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($device['name']); ?></td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($device['type']); ?></td>
                                        <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($device['device_type']); ?></td>
                                        <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300"><?php echo $device['ip_address'] !== '' ? htmlspecialchars($device['ip_address']) : '<span class="text-slate-400">waiting...</span>'; ?></td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($device['router_name']); ?></td>
                                        <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars((string)$device['port']); ?></td>
                                        <td class="px-4 py-3">
                                            <?php if ($device['access_url'] !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($device['access_url']); ?>"
                                                   target="_blank"
                                                   class="text-brand-600 dark:text-brand-400 hover:text-brand-700 dark:hover:text-brand-300 font-medium text-xs truncate inline-block max-w-[14rem]">
                                                    <?php echo htmlspecialchars($device['access_url']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-slate-400 text-xs">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <?php if ($device['access_url'] !== '' && $device['ip_address'] !== ''): ?>
                                                <a href="<?php echo htmlspecialchars($device['access_url']); ?>" target="_blank" class="inline-flex items-center gap-1 text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400 dark:hover:text-brand-300">
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3a1 1 0 100 2h2.586l-6.293 6.293a1 1 0 101.414 1.414L15 6.414V9a1 1 0 102 0V4a1 1 0 00-1-1h-5z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 5a2 2 0 00-2 2v8a2 2 0 002 2h8a2 2 0 002-2v-3"></path></svg>
                                                    Access
                                                </a>
                                            <?php else: ?>
                                                <span class="text-slate-400 text-xs">Waiting for IP</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="mt-6 flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
                        <div class="text-sm text-slate-500 dark:text-slate-400">
                            Showing <?php echo ($offset + 1); ?> to <?php echo min($offset + $itemsPerPage, $totalDevices); ?> of <?php echo $totalDevices; ?> devices
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($searchQuery); ?>&sort=<?php echo $sortField; ?>&order=<?php echo $sortOrder; ?>" class="px-3 py-1.5 text-sm rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 transition">Previous</a>
                            <?php endif; ?>
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($i = $startPage; $i <= $endPage; $i++): ?>
                                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($searchQuery); ?>&sort=<?php echo $sortField; ?>&order=<?php echo $sortOrder; ?>" class="px-3 py-1.5 text-sm rounded-lg border transition <?php echo $i === $page ? 'bg-brand-600 text-white border-brand-600 shadow-glow' : 'bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 border-slate-200 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700'; ?>"><?php echo $i; ?></a>
                            <?php endfor; ?>
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($searchQuery); ?>&sort=<?php echo $sortField; ?>&order=<?php echo $sortOrder; ?>" class="px-3 py-1.5 text-sm rounded-lg bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-700 border border-slate-200 dark:border-slate-700 transition">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
</div>

<!-- Add Device Modal -->
<div id="addModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center hidden z-[60] p-4">
    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-2xl p-6 md:p-8 w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-brand-50 dark:bg-brand-900/40 text-brand-600 dark:text-brand-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                </span>
                Add New Device
            </h3>
            <button type="button" onclick="closeAddModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="add_device.php" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label for="add_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Device Name</label>
                <input type="text" name="name" id="add_name" placeholder="e.g., device1" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition" required>
            </div>
            <div>
                <label for="add_type" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Type</label>
                <select name="type" id="add_type" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition" required>
                    <option value="Static">Static IPs</option>
                    <option value="PPPoE">PPPoE</option>
                </select>
            </div>
            <div>
                <label for="add_device_type" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Device Type</label>
                <select name="device_type" id="add_device_type" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition" required>
                    <option value="">Select Device Type</option>
                    <option value="Tenda">Tenda</option>
                    <option value="Ubiquiti">Ubiquiti</option>
                    <option value="Huawei">Huawei</option>
                    <option value="TP-Link">TP-Link</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div id="add_ip_address_field">
                <label for="add_ip_address" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">IP Address</label>
                <input type="text" name="ip_address" id="add_ip_address" placeholder="e.g., 192.168.1.100" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition" required>
            </div>
            <div id="add_pppoe_username_field" class="hidden md:col-span-2">
                <label for="add_pppoe_username" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">PPPoE Username</label>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-1.5">Make sure this matches the PPPoE username on your MikroTik router exactly, or the NAT rule won't work.</p>
                <input type="text" name="pppoe_username" id="add_pppoe_username" placeholder="e.g., user123" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition">
            </div>
            <div>
                <label for="add_router_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">MikroTik Router</label>
                <select name="router_id" id="add_router_id" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition" required>
                    <option value="">Select Router</option>
                    <?php foreach ($routers as $router): ?>
                        <option value="<?php echo htmlspecialchars($router['id']); ?>"><?php echo htmlspecialchars($router['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="add_port" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Port (200-400)</label>
                <select name="port" id="add_port" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition" required>
                    <option value="">Select Port</option>
                </select>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">Pick the router first — available ports depend on it.</p>
            </div>
            <div class="md:col-span-2 flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeAddModal()" class="rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-600 px-4 py-2 text-sm font-medium transition">Cancel</button>
                <button type="submit" name="add_device" class="rounded-lg bg-brand-600 hover:bg-brand-700 text-white px-4 py-2 text-sm font-medium shadow-glow focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900 transition">Add Device</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Device Modal -->
<div id="editModal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center hidden z-[60] p-4">
    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-2xl p-6 md:p-8 w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-5">
            <h3 class="text-lg font-semibold text-slate-900 dark:text-white flex items-center gap-2">
                <span class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-emerald-50 dark:bg-emerald-900/40 text-emerald-600 dark:text-emerald-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                </span>
                Edit Device
            </h3>
            <button type="button" onclick="closeEditModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-2xl leading-none">&times;</button>
        </div>
        <form method="POST" action="edit_device.php" class="grid grid-cols-1 gap-4">
            <input type="hidden" name="id" id="edit_id">
            <input type="hidden" name="type" id="edit_type">
            <div>
                <label for="edit_name" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Device Name</label>
                <input type="text" name="name" id="edit_name" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition" required>
            </div>
            <div>
                <label for="edit_device_type" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Device Type</label>
                <select name="device_type" id="edit_device_type" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition" required>
                    <option value="">Select Device Type</option>
                    <option value="Tenda">Tenda</option>
                    <option value="Ubiquiti">Ubiquiti</option>
                    <option value="Huawei">Huawei</option>
                    <option value="TP-Link">TP-Link</option>
                    <option value="Other">Other</option>
                </select>
            </div>
            <div id="edit_ip_address_field">
                <label for="edit_ip_address" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">IP Address</label>
                <input type="text" name="ip_address" id="edit_ip_address" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition" required>
            </div>
            <div id="edit_pppoe_username_field" class="hidden">
                <label for="edit_pppoe_username" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">PPPoE Username</label>
                <p class="text-xs text-slate-500 dark:text-slate-400 mb-1.5">Make sure this matches the PPPoE username on your MikroTik router exactly, or the NAT rule won't work.</p>
                <input type="text" name="pppoe_username" id="edit_pppoe_username" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition">
            </div>
            <div>
                <label for="edit_router_id" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">MikroTik Router</label>
                <select name="router_id" id="edit_router_id" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-900/40 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/30 focus:outline-none transition" required>
                    <option value="">Select Router</option>
                    <?php foreach ($routers as $router): ?>
                        <option value="<?php echo htmlspecialchars($router['id']); ?>"><?php echo htmlspecialchars($router['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="edit_port" class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">Port (cannot be changed)</label>
                <input type="text" id="edit_port" class="block w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-slate-50 dark:bg-slate-800/80 text-slate-500 dark:text-slate-400 px-3 py-2 text-sm shadow-sm cursor-not-allowed" readonly>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeEditModal()" class="rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-600 px-4 py-2 text-sm font-medium transition">Cancel</button>
                <button type="submit" name="edit_device" class="rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 text-sm font-medium shadow focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900 transition">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add Device Modal JavaScript
    const addTypeSelect = document.getElementById('add_type');
    const addIpAddressField = document.getElementById('add_ip_address_field');
    const addPppoeUsernameField = document.getElementById('add_pppoe_username_field');
    const addIpAddressInput = document.getElementById('add_ip_address');
    const addPppoeUsernameInput = document.getElementById('add_pppoe_username');

    addTypeSelect.addEventListener('change', function() {
        if (this.value === 'PPPoE') {
            addIpAddressField.classList.add('hidden');
            addPppoeUsernameField.classList.remove('hidden');
            addIpAddressInput.removeAttribute('required');
            addPppoeUsernameInput.setAttribute('required', 'required');
        } else {
            addIpAddressField.classList.remove('hidden');
            addPppoeUsernameField.classList.add('hidden');
            addIpAddressInput.setAttribute('required', 'required');
            addPppoeUsernameInput.removeAttribute('required');
        }
    });

    const addRouterSelect = document.getElementById('add_router_id');
    const addPortSelect = document.getElementById('add_port');
    const usedPortsByRouter = <?php echo json_encode($usedPortsByRouter); ?>;
    const allPorts = <?php echo json_encode($availablePorts); ?>;

    addRouterSelect.addEventListener('change', function() {
        const routerId = this.value;
        addPortSelect.innerHTML = '<option value="">Select Port</option>';

        if (routerId) {
            const usedPorts = usedPortsByRouter[routerId] || [];
            const availablePorts = allPorts.filter(port => !usedPorts.includes(parseInt(port)));

            availablePorts.forEach(port => {
                const option = document.createElement('option');
                option.value = port;
                option.textContent = port;
                addPortSelect.appendChild(option);
            });
        }
    });

    // Edit Device Modal JavaScript
    const editTypeSelect = document.getElementById('edit_type');
    const editIpAddressField = document.getElementById('edit_ip_address_field');
    const editPppoeUsernameField = document.getElementById('edit_pppoe_username_field');
    const editIpAddressInput = document.getElementById('edit_ip_address');
    const editPppoeUsernameInput = document.getElementById('edit_pppoe_username');

    // Note: editTypeSelect change listener is handled in openEditModal to set initial state
});

function openAddModal() {
    document.getElementById('addModal').classList.remove('hidden');
}

function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
    // Reset the form
    document.getElementById('add_name').value = '';
    document.getElementById('add_type').value = 'Static';
    document.getElementById('add_device_type').value = '';
    document.getElementById('add_ip_address').value = '';
    document.getElementById('add_pppoe_username').value = '';
    document.getElementById('add_router_id').value = '';
    document.getElementById('add_port').value = '';
    // Reset visibility of IP/PPPoE fields
    document.getElementById('add_ip_address_field').classList.remove('hidden');
    document.getElementById('add_pppoe_username_field').classList.add('hidden');
    document.getElementById('add_ip_address').setAttribute('required', 'required');
    document.getElementById('add_pppoe_username').removeAttribute('required');
}

function openEditModal(device) {
    document.getElementById('edit_id').value = device.id;
    document.getElementById('edit_type').value = device.type;
    document.getElementById('edit_name').value = device.name;
    document.getElementById('edit_device_type').value = device.device_type;
    document.getElementById('edit_ip_address').value = device.ip_address || '';
    document.getElementById('edit_pppoe_username').value = device.pppoe_username || '';
    document.getElementById('edit_router_id').value = device.router_id;
    document.getElementById('edit_port').value = device.port;

    const ipAddressField = document.getElementById('edit_ip_address_field');
    const pppoeUsernameField = document.getElementById('edit_pppoe_username_field');
    const ipAddressInput = document.getElementById('edit_ip_address');
    const pppoeUsernameInput = document.getElementById('edit_pppoe_username');

    if (device.type === 'PPPoE') {
        ipAddressField.classList.add('hidden');
        pppoeUsernameField.classList.remove('hidden');
        ipAddressInput.removeAttribute('required');
        pppoeUsernameInput.setAttribute('required', 'required');
    } else {
        ipAddressField.classList.remove('hidden');
        pppoeUsernameField.classList.add('hidden');
        ipAddressInput.setAttribute('required', 'required');
        pppoeUsernameInput.removeAttribute('required');
    }

    document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}
</script>

<?php
include __DIR__ . '/backend/templates/footer.php';
?>