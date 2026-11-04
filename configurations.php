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
    logError("Database connection failed: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// Check if the user has already added a router
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_routers");
    $stmt->execute();
    $routerCount = $stmt->fetchColumn();
} catch (PDOException $e) {
    logError("Error checking routers: " . $e->getMessage());
    die("Error checking routers: " . $e->getMessage());
}

// Include the header
include __DIR__ . '/backend/templates/header.php';

// Include the sidebar
include __DIR__ . '/backend/templates/sidebar.php';
?>

<!-- Main Content -->
<div class="flex-1 pt-20 sm:pt-16 ml-0 md:ml-64 min-h-screen flex flex-col">
    <main class="p-4 md:p-8 flex-1">
        <!-- Welcome Section -->
        <div id="welcome" class="relative overflow-hidden bg-brand-700 text-white rounded-2xl shadow-glow p-8 mb-8">
            <div class="absolute -top-20 -right-20 w-60 h-60 bg-white/10 rounded-full blur-3xl"></div>
            <div class="absolute -bottom-20 -left-20 w-60 h-60 bg-sky-400/20 rounded-full blur-3xl"></div>
            <div class="relative text-center">
                <h1 class="text-3xl md:text-4xl font-bold mb-3 tracking-tight">Device Configuration</h1>
                <p class="text-base md:text-lg text-brand-100 mb-6 max-w-2xl mx-auto">This guide walks you through configuring your MikroTik router and other devices to work seamlessly with our system. Follow the steps below to get started.</p>
                <div class="flex flex-wrap justify-center gap-3">
                    <a href="#tenda-config" class="inline-flex items-center gap-2 rounded-lg bg-white text-brand-700 px-5 py-2.5 text-sm font-medium hover:bg-brand-50 focus:outline-none focus:ring-2 focus:ring-white/60 transition shadow">
                        Start Configuration
                    </a>
                    <a href="devices.php" class="inline-flex items-center gap-2 rounded-lg bg-white/15 text-white border border-white/30 px-5 py-2.5 text-sm font-medium hover:bg-white/25 focus:outline-none focus:ring-2 focus:ring-white/60 transition">
                        Skip to Device Management
                    </a>
                </div>
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

        <!-- Configuration Navigation Menu -->
        <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6 mb-8">
            <h2 class="text-base font-semibold text-slate-800 dark:text-slate-100 mb-4">Configuration Categories</h2>
            <div class="flex flex-wrap gap-2">
                <a href="#tenda-config" class="inline-flex items-center rounded-lg bg-emerald-50 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/60 px-3 py-1.5 text-sm font-medium transition">Tenda</a>
                <a href="#ubiquiti-config" class="inline-flex items-center rounded-lg bg-amber-50 dark:bg-amber-900/40 text-amber-700 dark:text-amber-300 hover:bg-amber-100 dark:hover:bg-amber-900/60 px-3 py-1.5 text-sm font-medium transition">Ubiquiti</a>
                <a href="#huawei-config" class="inline-flex items-center rounded-lg bg-violet-50 dark:bg-violet-900/40 text-violet-700 dark:text-violet-300 hover:bg-violet-100 dark:hover:bg-violet-900/60 px-3 py-1.5 text-sm font-medium transition">Huawei</a>
                <a href="#tplink-config" class="inline-flex items-center rounded-lg bg-teal-50 dark:bg-teal-900/40 text-teal-700 dark:text-teal-300 hover:bg-teal-100 dark:hover:bg-teal-900/60 px-3 py-1.5 text-sm font-medium transition">TP-Link</a>
                <a href="#general-config" class="inline-flex items-center rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-slate-200 hover:bg-slate-200 dark:hover:bg-slate-600 px-3 py-1.5 text-sm font-medium transition">General Knowledge</a>
            </div>
        </div>

        <!-- Tenda Configuration -->
        <div id="tenda-config" class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6 mb-8">
            <h2 class="text-xl md:text-2xl font-semibold text-slate-900 dark:text-white mb-4 flex items-center">
                <svg class="w-6 h-6 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                </svg>
                Tenda Configuration
            </h2>
            <p class="text-sm text-slate-600 dark:text-slate-300 leading-relaxed">Set your Tenda to <strong>AP mode</strong> and you are done.</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mt-3">Note: once you set the Tenda to AP mode it will keep restarting until you add it to the dashboard.</p>
        </div>

        <!-- Ubiquiti Configuration -->
        <div id="ubiquiti-config" class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6 mb-8">
            <h2 class="text-xl md:text-2xl font-semibold text-slate-900 dark:text-white mb-4 flex items-center">
                <svg class="w-6 h-6 text-orange-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-4-6v12"></path>
                </svg>
                Ubiquiti Configuration
            </h2>
            <p class="text-sm text-slate-600 dark:text-slate-300 mb-4 leading-relaxed">To configure your Ubiquiti device for remote management, you need to disable the secure web server to allow HTTP access.</p>
            <ul class="list-disc pl-5 text-sm text-slate-600 dark:text-slate-300 mb-4 space-y-1.5">
                <li>Log in to your Ubiquiti device’s web interface (e.g., 192.168.21.1).</li>
                <li>Go to <strong>Services</strong> in the menu (depending on the device model, this might be under System or Settings).</li>
                <li>Locate the "Web Server" .</li>
                <li>Disable the Secure Connection (HTTPS) to allow HTTP access on port 80 (this might be a checkbox labeled "Enable HTTPS" or similar).</li>
                <li>Save the settings and restart the device if required.</li>
                <li>Test remote access by accessing the device .Go to Devices and access from there.</li>
            </ul>
        </div>

        <!-- Huawei Configuration -->
        <div id="huawei-config" class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6 mb-8">
            <h2 class="text-xl md:text-2xl font-semibold text-slate-900 dark:text-white mb-4 flex items-center">
                <svg class="w-6 h-6 text-purple-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                </svg>
                Huawei Configuration
            </h2>
            <p class="text-sm text-slate-600 dark:text-slate-300 mb-4 leading-relaxed">Huawei routers require specific configuration to enable remote management and ensure compatibility with our system. Follow these steps to set up your Huawei router.</p>
            <ul class="list-disc pl-5 text-sm text-slate-600 dark:text-slate-300 mb-4 space-y-1.5">
                <li>Log in to your Huawei router’s web interface by entering its IP address (e.g., 192.168.1.1) in your browser. Default credentials are usually username: <strong>admin</strong>, password: <strong>admin</strong> (change these if prompted).</li>
                <li>Navigate to <strong>System Tools > Remote Management</strong> (or <strong>Advanced > Remote Management</strong> depending on the model).</li>
                <li>Enable remote management and set the port to <strong>80</strong> (or another port if preferred, but ensure it matches your NAT rules).</li>
                <li>Specify allowed IP addresses for remote access (e.g., set to 0.0.0.0/0 for all IPs, or restrict to specific IPs for security).</li>
                <li>Go to <strong>Security > Firewall</strong> and ensure that the firewall allows incoming traffic on the chosen port (e.g., port 80).</li>
                <li>Configure NAT rules to forward external traffic to your router’s internal IP:
                    <ul class="list-circle pl-5 mt-2">
                        <li>Go to <strong>Network > NAT</strong> or <strong>Advanced > Port Forwarding</strong>.</li>
                        <li>Add a new rule to forward external port 80 to your router’s internal IP (e.g., 192.168.1.1) on port 80.</li>
                        <li>Save and apply the settings.</li>
                    </ul>
                </li>
                <li>Enable UPnP if your router supports it (optional, for easier NAT configuration): Go to <strong>Network > UPnP</strong> and enable it.</li>
                <li>Save all settings and reboot the router if prompted.</li>
                <li>Test remote access by accessing the router from an external network (e.g., http://[public-ip]:80).</li>
                <li>For PPPoE users, ensure the PPPoE settings are correctly configured:
                    <ul class="list-circle pl-5 mt-2">
                        <li>Go to <strong>Network > WAN</strong>.</li>
                        <li>Verify that the PPPoE username and password match the credentials used in our system.</li>
                        <li>Enable "Always Online" or similar to keep the connection active.</li>
                    </ul>
                </li>
            </ul>
        </div>

        <!-- TP-Link Configuration -->
        <div id="tplink-config" class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6 mb-8">
            <h2 class="text-xl md:text-2xl font-semibold text-slate-900 dark:text-white mb-4 flex items-center">
                <svg class="w-6 h-6 text-teal-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                </svg>
                TP-Link Configuration
            </h2>
            <p class="text-sm text-slate-600 dark:text-slate-300 mb-4 leading-relaxed">TP-Link routers are straightforward to configure for remote management. Follow these steps to set up your TP-Link router.</p>
            <ul class="list-disc pl-5 text-sm text-slate-600 dark:text-slate-300 mb-4 space-y-1.5">
                <li>Log in to your TP-Link router’s web interface by entering its IP address (e.g., 192.168.0.1) in your browser. Default credentials are usually username: <strong>admin</strong>, password: <strong>admin</strong> (change these if prompted).</li>
                <li>Navigate to <strong>Advanced > System Tools > Administration</strong> (or <strong>System Tools > Administration</strong> depending on the model).</li>
                <li>Scroll to the "Remote Management" section and enable it.</li>
                <li>Set the remote management port to <strong>80</strong> (or another port if preferred, but ensure it matches your NAT rules).</li>
                <li>Specify the remote management IP address (e.g., set to 0.0.0.0/0 to allow all IPs, or restrict to specific IPs for security).</li>
                <li>Go to <strong>Advanced > NAT Forwarding > Virtual Servers</strong> (or <strong>Forwarding > Virtual Servers</strong>):
                    <ul class="list-circle pl-5 mt-2">
                        <li>Add a new rule to forward external port 80 to your router’s internal IP (e.g., 192.168.0.1) on port 80.</li>
                        <li>Ensure the protocol is set to TCP.</li>
                        <li>Save and apply the settings.</li>
                    </ul>
                </li>
                <li>Go to <strong>Advanced > Security > Firewall</strong> and ensure that the firewall allows incoming traffic on port 80.</li>
                <li>Enable UPnP if your router supports it (optional, for easier NAT configuration): Go to <strong>Advanced > NAT Forwarding > UPnP</strong> and enable it.</li>
                <li>Save all settings and reboot the router if prompted.</li>
                <li>Test remote access by accessing the router from an external network (e.g., http://[public-ip]:80).</li>
                <li>For PPPoE users, ensure the PPPoE settings are correctly configured:
                    <ul class="list-circle pl-5 mt-2">
                        <li>Go to <strong>Network > WAN</strong>.</li>
                        <li>Verify that the PPPoE username and password match the credentials used in our system.</li>
                        <li>Enable "Connect Automatically" to keep the connection active.</li>
                    </ul>
                </li>
            </ul>
        </div>

        <!-- General Knowledge for Other Devices -->
        <div id="general-config" class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6 mb-8">
            <h2 class="text-xl md:text-2xl font-semibold text-slate-900 dark:text-white mb-4 flex items-center">
                <svg class="w-6 h-6 text-gray-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7h18M3 4h18M4 4v17m4-17v17m4-17v17m4-17v17m4-17v17"></path>
                </svg>
                General Knowledge for Other Devices
            </h2>
            <p class="text-sm text-slate-600 dark:text-slate-300 mb-4 leading-relaxed">If your device is not a MikroTik, Tenda, Ubiquiti, Huawei, or TP-Link, follow these general guidelines to configure it for remote management and compatibility with our system.</p>
            <ul class="list-disc pl-5 text-sm text-slate-600 dark:text-slate-300 mb-4 space-y-1.5">
                <li>Log in to your device’s web interface or management software (refer to the device’s manual for the default IP address and credentials).</li>
                <li>Look for a <strong>Remote Management</strong> or <strong>Remote Access</strong> setting, often found under Administration, System, or Security settings.</li>
                <li>Enable remote management and set the port to <strong>80</strong> (or another port if preferred, but ensure it matches your NAT rules).</li>
                <li>Specify allowed IP addresses for remote access (e.g., set to 0.0.0.0/0 to allow all IPs, or restrict to specific IPs for security).</li>
                <li>Configure port forwarding or NAT rules to allow external access:
                    <ul class="list-circle pl-5 mt-2">
                        <li>Go to the NAT or Port Forwarding section of your device’s settings.</li>
                        <li>Add a rule to forward external port 80 to your device’s internal IP (e.g., 192.168.1.x) on port 80.</li>
                        <li>Ensure the protocol is set to TCP.</li>
                        <li>Save and apply the settings.</li>
                    </ul>
                </li>
                <li>Check your device’s firewall settings to ensure incoming traffic on the chosen port (e.g., port 80) is allowed.</li>
                <li>If your device supports UPnP, consider enabling it for easier NAT configuration (often found under Network or Advanced settings).</li>
                <li>Save all settings and reboot the device if prompted.</li>
                <li>For PPPoE users, ensure the PPPoE settings are correctly configured:
                    <ul class="list-circle pl-5 mt-2">
                        <li>Go to the WAN or Internet settings.</li>
                        <li>Verify that the PPPoE username and password match the credentials used in our system.</li>
                        <li>Enable an "Always On" or "Connect Automatically" option to keep the connection active.</li>
                    </ul>
                </li>
                <li>Best Practices:
                    <ul class="list-circle pl-5 mt-2">
                        <li>Change the default admin password to a strong, unique password.</li>
                        <li>Regularly update your device’s firmware to ensure security and compatibility.</li>
                    </ul>
                </li>
            </ul>
        </div>

        <!-- Next Steps -->
        <div class="bg-white dark:bg-slate-800/60 border border-slate-200 dark:border-slate-700 rounded-2xl shadow-soft p-6">
            <h2 class="text-xl md:text-2xl font-semibold text-slate-900 dark:text-white mb-4 flex items-center">
                <svg class="w-6 h-6 text-indigo-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
                Next Steps
            </h2>
            <p class="text-sm text-slate-600 dark:text-slate-300 mb-4 leading-relaxed">Now that your devices are configured, you can proceed to manage your devices or explore other sections of the dashboard.</p>
            <div class="flex flex-wrap gap-3">
                <a href="devices.php" class="inline-flex items-center gap-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white px-5 py-2.5 text-sm font-medium shadow-glow focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 dark:focus:ring-offset-slate-900 transition">
                    Go to Device Management
                </a>
                <a href="index.php" class="inline-flex items-center gap-2 rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700 px-5 py-2.5 text-sm font-medium transition">
                    Back to Dashboard
                </a>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/backend/templates/footer.php'; ?>
</div>