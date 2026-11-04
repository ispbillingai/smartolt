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

// Function to generate or retrieve OVPN credentials
function manageOvpnCredentials($subdomain, $db, $routerId) {
    $userpassFile = '/etc/openvpn/userpass';
    $credentials = [];

    // Check if credentials already exist in tbl_ovpn_credentials
    $stmt = $db->prepare("SELECT username, password FROM tbl_ovpn_credentials WHERE router_id = :router_id");
    $stmt->execute([':router_id' => $routerId]);
    $existingCredentials = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existingCredentials) {
        return ['exists' => true, 'username' => $existingCredentials['username'], 'password' => $existingCredentials['password']];
    }

    // Generate new credentials
    $username = $subdomain;
    $password = $subdomain; // Same as username per requirement

    // Append to userpass file
    $entry = "$username $password\n";
    $result = file_put_contents($userpassFile, $entry, FILE_APPEND | LOCK_EX);
    if ($result === false) {
        logError("Failed to write OVPN credentials to $userpassFile for $subdomain");
        return ['exists' => false, 'error' => 'Failed to generate OVPN credentials'];
    }

    // Attempt to reload OpenVPN (this will fail due to sudo password requirement, left as is per instruction)
    exec('sudo systemctl reload openvpn@server 2>&1', $output, $returnVar);
    if ($returnVar !== 0) {
        logError("Failed to reload OpenVPN service: " . implode("\n", $output));
    }

    // Store the generated credentials in tbl_ovpn_credentials
    try {
        $stmt = $db->prepare("INSERT INTO tbl_ovpn_credentials (router_id, username, password) VALUES (:router_id, :username, :password)");
        $stmt->execute([
            ':router_id' => $routerId,
            ':username' => $username,
            ':password' => $password
        ]);
    } catch (PDOException $e) {
        logError("Error storing OVPN credentials for router ID {$routerId}: " . $e->getMessage());
        return ['exists' => false, 'error' => 'Failed to store OVPN credentials in the database'];
    }

    return ['exists' => true, 'username' => $username, 'password' => $password];
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
$app_url = $database->getAppUrl(); // e.g., "https://devices.ispledger.cc"
// Parse the app_url to extract the base domain
$parsed_url = parse_url($app_url);
$base_domain = $parsed_url['host'] ?? 'devices.ispledger.cc'; // Fallback if parsing fails
// Extract the subdomain prefix (e.g., "devices" from "devices.ispledger.cc")
$domain_parts = explode('.', $base_domain);
$subdomain_prefix = $domain_parts[0]; // e.g., "devices"

// Determine the last router ID in tbl_routers
$lastRouterId = 0;
try {
    $stmt = $db->prepare("SELECT MAX(id) as max_id FROM tbl_routers");
    $stmt->execute();
    $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'];
    if ($maxId) {
        $lastRouterId = $maxId;
    }
} catch (PDOException $e) {
    logError("Error fetching max router ID from tbl_routers: " . $e->getMessage());
}

// Determine the last generated router ID in tbl_ovpn_credentials
$lastGeneratedRouterId = 0;
$lastGeneratedCredentials = null;
try {
    $stmt = $db->prepare("SELECT router_id, username, password FROM tbl_ovpn_credentials ORDER BY router_id DESC LIMIT 1");
    $stmt->execute();
    $lastGeneratedCredentials = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($lastGeneratedCredentials) {
        $lastGeneratedRouterId = $lastGeneratedCredentials['router_id'];
    }
} catch (PDOException $e) {
    logError("Error fetching last generated credentials: " . $e->getMessage());
}

// Determine the next router ID for OVPN credential generation
$nextRouterId = $lastRouterId + 1;
$subdomain = "{$subdomain_prefix}{$nextRouterId}"; // e.g., devices1

// Check if the last generated credentials have been used
$lastCredentialsUsed = false;
if ($lastGeneratedCredentials) {
    $lastRouterIdToCheck = $lastGeneratedCredentials['router_id'];
    $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_routers WHERE id = :id");
    $stmt->execute([':id' => $lastRouterIdToCheck]);
    $lastCredentialsUsed = $stmt->fetchColumn() > 0;
}

$errors = [];
$success_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_router'])) {
    $newRouter = [
        'name' => trim($_POST['name'] ?? ''),
        'ip_address' => trim($_POST['ip_address'] ?? ''),
        'username' => trim($_POST['username'] ?? ''),
        'password' => $subdomain_prefix // reuse the main password (the tenant subdomain)
    ];

    $msg = '';
    if (!validateLength($newRouter['name'], 30, 4)) {
        $msg .= 'Name should be between 5 to 30 characters<br>';
    }
    if ($newRouter['ip_address'] == '' || $newRouter['username'] == '') {
        $msg .= langT('All fields are required') . '<br>';
    }
    if (strtolower($newRouter['name']) == 'radius') {
        $msg .= '<b>Radius</b> name is reserved<br>';
    }

    if ($msg == '') {
        try {
            $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_routers WHERE name = :name");
            $stmt->execute([':name' => $newRouter['name']]);
            if ($stmt->fetchColumn() > 0) {
                $msg .= langT('Router Name Already Exists') . '<br>';
            }

            $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_routers WHERE ip_address = :ip_address");
            $stmt->execute([':ip_address' => $newRouter['ip_address']]);
            if ($stmt->fetchColumn() > 0) {
                $msg .= langT('IP Router Already Exists') . '<br>';
            }
        } catch (PDOException $e) {
            logError("Error checking duplicates: " . $e->getMessage());
            $msg .= 'Database error occurred<br>';
        }
    }

    if ($msg == '') {
        if (!class_exists('Mikrotik')) {
            logError("Class Mikrotik not found before calling getClient");
            die("Class Mikrotik not found. Check autoloader and file paths.");
        }
        $client = Mikrotik::getClient($newRouter['ip_address'], $newRouter['username'], $newRouter['password']);
        if (!$client) {
            $msg .= 'Failed to authenticate with MikroTik API. Check IP, username, or password.<br>';
        }
    }

    if ($msg == '') {
        try {
            // Insert the router into the database
            $stmt = $db->prepare("INSERT INTO tbl_routers (name, ip_address, username, password, port, access_url) VALUES (:name, :ip_address, :username, :password, :port, :access_url)");
            $stmt->execute([
                ':name' => $newRouter['name'],
                ':ip_address' => $newRouter['ip_address'],
                ':username' => $newRouter['username'],
                ':password' => $newRouter['password'],
                ':port' => 8728,
                ':access_url' => ''
            ]);

            // Get the ID of the newly inserted router
            $routerId = $db->lastInsertId();
            $subdomain = "{$subdomain_prefix}{$routerId}"; // e.g., devices1
            $newRouter['access_url'] = "http://{$subdomain}.ispledger.cc";

            // Update the access_url with the correct subdomain based on the router ID
            $stmt = $db->prepare("UPDATE tbl_routers SET access_url = :access_url WHERE id = :id");
            $stmt->execute([
                ':access_url' => $newRouter['access_url'],
                ':id' => $routerId
            ]);

            // Call the root-owned script to generate the .conf file, enable the site, and create the subdomain
            $cmd = escapeshellcmd("sudo /root/manage_router_site.sh {$subdomain} {$newRouter['ip_address']}");
            exec("{$cmd} 2>&1", $output, $returnVar);

            if ($returnVar === 0) {
                logError("Successfully enabled Apache configuration and created subdomain for {$subdomain}.ispledger.cc");
                $success_message = "Router added successfully, Apache configuration enabled, and subdomain created.";
                r2($_SERVER['PHP_SELF'], 's', langT('Data Created Successfully'));
            } else {
                $errorMessage = implode("\n", $output);
                logError("Failed to enable Apache configuration or create subdomain: {$errorMessage}");
                $msg .= "Failed to enable Apache configuration or create subdomain: {$errorMessage}<br>";
                // Roll back the database insert
                $stmt = $db->prepare("DELETE FROM tbl_routers WHERE id = :id");
                $stmt->execute([':id' => $routerId]);
            }
        } catch (PDOException $e) {
            logError("Error adding router to database: " . $e->getMessage());
            $msg .= 'Failed to add router to database<br>';
        }
    }

    if ($msg != '') {
        $errors[] = $msg;
        r2($_SERVER['PHP_SELF'], 'e', $msg);
    }
}

// Handle OVPN credential generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_ovpn'])) {
    $msg = '';

    // Check if there are existing generated credentials that haven't been used
    if ($lastGeneratedCredentials && !$lastCredentialsUsed) {
        $success_message = "OVPN credentials already exist for router ID {$lastGeneratedCredentials['router_id']}. Please add the router to the admin panel before generating new credentials.";
    } else {
        $result = manageOvpnCredentials($subdomain, $db, $nextRouterId);
        if (isset($result['error'])) {
            $errors[] = $result['error'];
            r2($_SERVER['PHP_SELF'], 'e', $result['error']);
        } else {
            $success_message = "OVPN credentials generated successfully for router ID {$nextRouterId}. Apply the script to your MikroTik router, connect to the VPN, and then add the router to the admin panel using the VPN-assigned IP.";
        }
    }

    if ($msg != '') {
        $errors[] = $msg;
        r2($_SERVER['PHP_SELF'], 'e', $msg);
    } else {
        r2($_SERVER['PHP_SELF'], 's', $success_message);
    }
}

try {
    $stmt = $db->prepare(
        "SELECT r.id, r.name, r.ip_address, r.username, r.port, r.access_url,
                c.username AS ovpn_username, c.password AS ovpn_password
         FROM tbl_routers r
         LEFT JOIN tbl_ovpn_credentials c ON c.router_id = r.id
         ORDER BY r.name"
    );
    $stmt->execute();
    $routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logError("Error fetching routers: " . $e->getMessage());
    die("Error fetching routers: " . $e->getMessage());
}

try {
    foreach ($routers as &$router) {
        $router['ipAddress'] = $router['ip_address'] ?? 'N/A';
        $router['port'] = $router['port'] ?? 8728;
        $router['name'] = $router['name'] ?? 'Unnamed';
        // Extract subdomain for OVPN credentials
        $router['subdomain'] = parse_url($router['access_url'], PHP_URL_HOST) ?? '';
        $router['subdomain'] = $router['subdomain'] ?: "{$subdomain_prefix}{$router['id']}";
        logError("Rendering access_url for router {$router['name']}: {$router['access_url']}");
    }
    unset($router);

    $sortField = isset($_GET['sort']) ? $_GET['sort'] : 'name';
    $sortOrder = isset($_GET['order']) && $_GET['order'] === 'asc' ? 'asc' : 'desc';
    $validSortFields = ['name', 'ipAddress', 'username', 'port'];
    if (!in_array($sortField, $validSortFields)) {
        $sortField = 'name';
    }

    usort($routers, function($a, $b) use ($sortField, $sortOrder) {
        $aValue = $a[$sortField] ?? '';
        $bValue = $b[$sortField] ?? '';
        return $sortOrder === 'asc' ? $aValue <=> $bValue : $bValue <=> $aValue;
    });
} catch (Exception $e) {
    logError("Error processing routers: " . $e->getMessage());
    die("Error processing routers: " . $e->getMessage());
}

include __DIR__ . '/backend/templates/header.php';
include __DIR__ . '/backend/templates/sidebar.php';
?>

<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <div class="mb-6">
            <h2 class="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white tracking-tight">Network</h2>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">Generate VPN credentials and register MikroTik routers.</p>
        </div>


        <!-- OVPN Credential Generation Section -->
        <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6 mb-8">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">Generate OVPN Credentials for Router ID <span class="text-brand-600 dark:text-brand-400"><?php echo htmlspecialchars($nextRouterId); ?></span></h3>
            <?php if (!empty($errors)): ?>
                <div class="mb-4 p-4 bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-800/50 text-rose-700 dark:text-rose-300 rounded-xl">
                    <ul class="list-disc pl-5 text-sm space-y-1">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo $error; ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if ($success_message): ?>
                <div class="mb-4 p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800/50 text-emerald-700 dark:text-emerald-300 rounded-xl text-sm">
                    <?php echo htmlspecialchars($success_message); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="mb-4 p-4 bg-rose-50 dark:bg-rose-900/30 border border-rose-200 dark:border-rose-800/50 text-rose-700 dark:text-rose-300 rounded-xl text-sm">
                    <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="mb-4 p-4 bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800/50 text-emerald-700 dark:text-emerald-300 rounded-xl text-sm">
                    <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            <?php if ($lastGeneratedCredentials && !$lastCredentialsUsed): ?>
                <div class="mb-4 p-4 bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
                    <div class="flex flex-col sm:flex-row sm:gap-8 gap-1">
                        <div><span class="font-medium text-slate-700 dark:text-slate-200">Username:</span> <span class="font-mono text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($lastGeneratedCredentials['username']); ?></span></div>
                        <div><span class="font-medium text-slate-700 dark:text-slate-200">Password:</span> <span class="font-mono text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($lastGeneratedCredentials['password']); ?></span></div>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">RouterOS 6 Script</label>
                    <div class="relative">
                        <textarea readonly id="script_ros6" class="w-full h-24 p-3 pr-20 border border-slate-300 dark:border-slate-600 rounded-lg text-xs font-mono bg-slate-900 dark:bg-slate-950 text-slate-100">/interface ovpn-client add connect-to=62.171.128.120 name=deviceaccess user="<?php echo htmlspecialchars($lastGeneratedCredentials['username']); ?>" password="<?php echo htmlspecialchars($lastGeneratedCredentials['password']); ?>" profile=default comment=free_device_access_freeispradius.net cipher=aes256 auth=sha1</textarea>
                        <button type="button" onclick="copyScript('script_ros6')" class="absolute top-2 right-2 inline-flex items-center gap-1 bg-brand-600 hover:bg-brand-700 text-white text-xs px-3 py-1.5 rounded focus:outline-none focus:ring-2 focus:ring-brand-500 transition">Copy</button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">RouterOS 7 Script</label>
                    <div class="relative">
                        <textarea readonly id="script_ros7" class="w-full h-24 p-3 pr-20 border border-slate-300 dark:border-slate-600 rounded-lg text-xs font-mono bg-slate-900 dark:bg-slate-950 text-slate-100">/interface ovpn-client add connect-to=62.171.128.120 name=deviceaccess user="<?php echo htmlspecialchars($lastGeneratedCredentials['username']); ?>" password="<?php echo htmlspecialchars($lastGeneratedCredentials['password']); ?>" profile=default comment=free_device_access_freeispradius.net cipher=aes256-cbc auth=sha1 route-nopull=yes</textarea>
                        <button type="button" onclick="copyScript('script_ros7')" class="absolute top-2 right-2 inline-flex items-center gap-1 bg-brand-600 hover:bg-brand-700 text-white text-xs px-3 py-1.5 rounded focus:outline-none focus:ring-2 focus:ring-brand-500 transition">Copy</button>
                    </div>
                </div>
                <p class="text-sm text-slate-500 dark:text-slate-400">Connect your router to the VPN using the script above, then use the VPN-assigned IP (e.g., 20.0.0.x) to add the router to the admin panel using the form below.</p>
            <?php else: ?>
                <p class="text-sm text-slate-500 dark:text-slate-400">VPN credentials are generated from your <strong>billing system</strong> when you set up a router. Nothing to do here.</p>
            <?php endif; ?>
        </div>

        <!-- Add New MikroTik Router Form -->
        <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6 mb-8">
            <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">Routers (managed from your billing system)</h3>
            <p class="text-sm text-slate-500 dark:text-slate-400">
                Routers are added and configured from your <strong>billing system</strong>. Once you set a router up there, it appears in the list below automatically. This page is read-only.
            </p>
        </div>

        <!-- Managed Routers Table -->
        <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft">
            <div class="p-6">
                <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-4 gap-3">
                    <h3 class="text-base font-semibold text-slate-800 dark:text-slate-100 flex items-center gap-2">
                        <svg class="w-5 h-5 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        Managed MikroTik Routers
                    </h3>
                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                        <a href="?sort=name&order=<?php echo $sortField === 'name' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>"
                           class="text-sm <?php echo $sortField === 'name' ? 'text-brand-600 dark:text-brand-400 font-semibold' : 'text-slate-500 dark:text-slate-400'; ?> hover:text-brand-700 dark:hover:text-brand-300">
                            Name <?php echo $sortField === 'name' ? ($sortOrder === 'asc' ? '↑' : '↓') : ''; ?>
                        </a>
                        <a href="?sort=ipAddress&order=<?php echo $sortField === 'ipAddress' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>"
                           class="text-sm <?php echo $sortField === 'ipAddress' ? 'text-brand-600 dark:text-brand-400 font-semibold' : 'text-slate-500 dark:text-slate-400'; ?> hover:text-brand-700 dark:hover:text-brand-300">
                            IP Address <?php echo $sortField === 'ipAddress' ? ($sortOrder === 'asc' ? '↑' : '↓') : ''; ?>
                        </a>
                        <a href="?sort=username&order=<?php echo $sortField === 'username' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>"
                           class="text-sm <?php echo $sortField === 'username' ? 'text-brand-600 dark:text-brand-400 font-semibold' : 'text-slate-500 dark:text-slate-400'; ?> hover:text-brand-700 dark:hover:text-brand-300">
                            Username <?php echo $sortField === 'username' ? ($sortOrder === 'asc' ? '↑' : '↓') : ''; ?>
                        </a>
                        <a href="?sort=port&order=<?php echo $sortField === 'port' && $sortOrder === 'asc' ? 'desc' : 'asc'; ?>"
                           class="text-sm <?php echo $sortField === 'port' ? 'text-brand-600 dark:text-brand-400 font-semibold' : 'text-slate-500 dark:text-slate-400'; ?> hover:text-brand-700 dark:hover:text-brand-300">
                            Port <?php echo $sortField === 'port' ? ($sortOrder === 'asc' ? '↑' : '↓') : ''; ?>
                        </a>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left">
                        <thead class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wide bg-slate-50 dark:bg-slate-800/40 border-y border-slate-200 dark:border-slate-700">
                            <tr>
                                <th scope="col" class="px-4 py-3 font-medium">Name</th>
                                <th scope="col" class="px-4 py-3 font-medium">IP Address</th>
                                <th scope="col" class="px-4 py-3 font-medium">Username</th>
                                <th scope="col" class="px-4 py-3 font-medium">Port</th>
                                <th scope="col" class="px-4 py-3 font-medium">Access</th>
                                <th scope="col" class="px-4 py-3 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200 dark:divide-slate-700">
                            <?php foreach ($routers as $router): ?>
                                <tr class="hover:bg-slate-50 dark:hover:bg-slate-800/40 transition">
                                    <td class="px-4 py-3 font-medium text-slate-900 dark:text-slate-100"><?php echo htmlspecialchars($router['name']); ?></td>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($router['ipAddress']); ?></td>
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($router['username']); ?></td>
                                    <td class="px-4 py-3 text-slate-600 dark:text-slate-300"><?php echo htmlspecialchars($router['port']); ?></td>
                                    <td class="px-4 py-3">
                                        <a href="<?php echo htmlspecialchars($router['access_url']); ?>"
                                           target="_blank"
                                           class="inline-flex items-center gap-1 text-brand-600 dark:text-brand-400 hover:text-brand-700 dark:hover:text-brand-300 text-xs font-medium">
                                            Access
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                                            </svg>
                                        </a>
                                    </td>
                                    <td class="px-4 py-3">
                                        <?php if (!empty($router['ovpn_username'])): ?>
                                            <button type="button"
                                                    data-router-name="<?php echo htmlspecialchars($router['name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-ovpn-username="<?php echo htmlspecialchars($router['ovpn_username'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-ovpn-password="<?php echo htmlspecialchars($router['ovpn_password'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    onclick="openScriptModal(this)"
                                                    class="text-xs bg-brand-600 hover:bg-brand-700 text-white px-3 py-1.5 rounded-lg focus:outline-none focus:ring-2 focus:ring-brand-500 transition">
                                                Show Script / Reconfigure
                                            </button>
                                        <?php else: ?>
                                            <span class="text-xs text-slate-400 dark:text-slate-500">No script on file</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
</div>

<!-- Show Script Modal (read-only OVPN script for an already-installed router) -->
<div id="scriptModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-2xl w-full max-w-2xl p-6 max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-base font-semibold text-slate-900 dark:text-white">Reconfigure / OVPN Script for <span id="scriptModalRouter" class="text-brand-600 dark:text-brand-400"></span></h3>
            <button type="button" onclick="closeScriptModal()" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-200 text-2xl leading-none">&times;</button>
        </div>
        <p class="text-sm text-slate-600 dark:text-slate-300 mb-4">Re-paste this script in <strong>New Terminal</strong> on your MikroTik router if the OVPN client was lost or the router was reset. Credentials and identifiers are unchanged.</p>
        <div class="mb-4 p-4 bg-slate-50 dark:bg-slate-900/40 border border-slate-200 dark:border-slate-700 rounded-xl text-sm">
            <div class="flex flex-col sm:flex-row sm:gap-8 gap-1">
                <div><span class="font-medium text-slate-700 dark:text-slate-200">Username:</span> <span id="scriptModalUser" class="font-mono text-slate-900 dark:text-slate-100"></span></div>
                <div><span class="font-medium text-slate-700 dark:text-slate-200">Password:</span> <span id="scriptModalPass" class="font-mono text-slate-900 dark:text-slate-100"></span></div>
            </div>
        </div>
        <div class="mb-4">
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">RouterOS 6 Script</label>
            <div class="relative">
                <textarea readonly id="scriptModalRos6" class="w-full h-24 p-3 pr-20 border border-slate-300 dark:border-slate-600 rounded-lg text-xs font-mono bg-slate-900 dark:bg-slate-950 text-slate-100"></textarea>
                <button type="button" onclick="copyScript('scriptModalRos6')" class="absolute top-2 right-2 bg-brand-600 hover:bg-brand-700 text-white text-xs px-3 py-1.5 rounded focus:outline-none focus:ring-2 focus:ring-brand-500 transition">Copy</button>
            </div>
        </div>
        <div class="mb-4">
            <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1.5">RouterOS 7 Script</label>
            <div class="relative">
                <textarea readonly id="scriptModalRos7" class="w-full h-24 p-3 pr-20 border border-slate-300 dark:border-slate-600 rounded-lg text-xs font-mono bg-slate-900 dark:bg-slate-950 text-slate-100"></textarea>
                <button type="button" onclick="copyScript('scriptModalRos7')" class="absolute top-2 right-2 bg-brand-600 hover:bg-brand-700 text-white text-xs px-3 py-1.5 rounded focus:outline-none focus:ring-2 focus:ring-brand-500 transition">Copy</button>
            </div>
        </div>
        <div class="flex justify-end">
            <button type="button" onclick="closeScriptModal()" class="rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-600 px-4 py-2 text-sm font-medium transition">Close</button>
        </div>
    </div>
</div>

<script>
function copyScript(textareaId) {
    const textarea = document.getElementById(textareaId);
    textarea.select();
    try {
        document.execCommand('copy');
        alert('Script copied to clipboard!');
    } catch (err) {
        alert('Failed to copy script. Please copy it manually.');
    }
}

function openScriptModal(btn) {
    const name = btn.dataset.routerName;
    const user = btn.dataset.ovpnUsername;
    const pass = btn.dataset.ovpnPassword;

    document.getElementById('scriptModalRouter').textContent = name;
    document.getElementById('scriptModalUser').textContent = user;
    document.getElementById('scriptModalPass').textContent = pass;
    document.getElementById('scriptModalRos6').value =
        '/interface ovpn-client add connect-to=62.171.128.120 name=deviceaccess user="' + user + '" password="' + pass + '" profile=default comment=free_device_access_freeispradius.net cipher=aes256 auth=sha1';
    document.getElementById('scriptModalRos7').value =
        '/interface ovpn-client add connect-to=62.171.128.120 name=deviceaccess user="' + user + '" password="' + pass + '" profile=default comment=free_device_access_freeispradius.net cipher=aes256-cbc auth=sha1 route-nopull=yes';

    document.getElementById('scriptModal').classList.remove('hidden');
}

function closeScriptModal() {
    document.getElementById('scriptModal').classList.add('hidden');
}

document.getElementById('scriptModal').addEventListener('click', function (e) {
    if (e.target === this) closeScriptModal();
});
</script>

<?php
include __DIR__ . '/backend/templates/footer.php';
?>