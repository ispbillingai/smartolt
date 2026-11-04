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
    r2('devices.php', 'e', 'Please log in to add a device.');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_device'])) {
    $newDevice = [
        'name' => trim($_POST['name'] ?? ''),
        'type' => trim($_POST['type'] ?? ''),
        'device_type' => trim($_POST['device_type'] ?? ''),
        'ip_address' => trim($_POST['ip_address'] ?? ''),
        'pppoe_username' => trim($_POST['pppoe_username'] ?? ''),
        'router_id' => trim($_POST['router_id'] ?? ''),
        'port' => trim($_POST['port'] ?? '')
    ];

    $msg = '';
    if (!validateLength($newDevice['name'], 50, 1)) {
        $msg .= 'Device name must be between 1 and 50 characters<br>';
    }
    if (!in_array($newDevice['type'], ['PPPoE', 'Static'])) {
        $msg .= 'Invalid device type (PPPoE/Static) selected<br>';
    }
    if (!in_array($newDevice['device_type'], ['Tenda', 'Ubiquiti', 'Huawei', 'TP-Link', 'Other'])) {
        $msg .= 'Invalid device type selected<br>';
    }
    if ($newDevice['type'] === 'Static' && !filter_var($newDevice['ip_address'], FILTER_VALIDATE_IP)) {
        $msg .= 'Invalid IP address<br>';
    }
    if ($newDevice['type'] === 'PPPoE') {
        if (!validateLength($newDevice['pppoe_username'], 50, 1)) {
            $msg .= 'PPPoE username must be between 1 and 50 characters<br>';
        }
    }
    if (empty($newDevice['router_id']) || !is_numeric($newDevice['router_id'])) {
        $msg .= 'Please select a valid router<br>';
    }
    if (empty($newDevice['port']) || !is_numeric($newDevice['port']) || $newDevice['port'] < 200 || $newDevice['port'] > 400) {
        $msg .= 'Please select a valid port between 200 and 400<br>';
    }

    if ($msg == '') {
        // Check if device name is unique
        $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_devices WHERE name = :name");
        $stmt->execute([':name' => $newDevice['name']]);
        if ($stmt->fetchColumn() > 0) {
            $msg .= 'Device name already exists<br>';
        }
    }

    if ($msg == '' && $newDevice['type'] === 'PPPoE') {
        // Check if PPPoE username already exists on the same router
        $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_devices WHERE pppoe_username = :pppoe_username AND router_id = :router_id");
        $stmt->execute([
            ':pppoe_username' => $newDevice['pppoe_username'],
            ':router_id' => $newDevice['router_id']
        ]);
        if ($stmt->fetchColumn() > 0) {
            $msg .= 'PPPoE username already exists on this router<br>';
        }
    }

    if ($msg == '' && $newDevice['type'] === 'Static') {
        // Check if IP address already exists on the same router
        $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_devices WHERE ip_address = :ip_address AND router_id = :router_id");
        $stmt->execute([
            ':ip_address' => $newDevice['ip_address'],
            ':router_id' => $newDevice['router_id']
        ]);
        if ($stmt->fetchColumn() > 0) {
            $msg .= 'IP address already exists on this router<br>';
        }
    }

    if ($msg == '') {
        // Check if router exists and get its details
        $stmt = $db->prepare("SELECT id, ip_address, username, password, port FROM tbl_routers WHERE id = :id");
        $stmt->execute([':id' => $newDevice['router_id']]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$router) {
            $msg .= 'Selected router does not exist<br>';
        }
    }

    if ($msg == '') {
        // Check if port is already used for the selected router
        $stmt = $db->prepare("SELECT port FROM tbl_devices WHERE router_id = :router_id AND port = :port");
        $stmt->execute([':router_id' => $newDevice['router_id'], ':port' => $newDevice['port']]);
        if ($stmt->fetch()) {
            $msg .= 'Selected port is already in use for this router<br>';
        }
    }

    if ($msg == '') {
        try {
            // Generate access_url dynamically using the subdomain prefix
            $access_url = "http://{$subdomain_prefix}{$newDevice['router_id']}.{$domain_suffix}:{$newDevice['port']}";

            // Save the device to the database
            $stmt = $db->prepare("INSERT INTO tbl_devices (name, type, device_type, ip_address, pppoe_username, router_id, port, access_url) VALUES (:name, :type, :device_type, :ip_address, :pppoe_username, :router_id, :port, :access_url)");
            $stmt->execute([
                ':name' => $newDevice['name'],
                ':type' => $newDevice['type'],
                ':device_type' => $newDevice['device_type'],
                ':ip_address' => $newDevice['type'] === 'Static' ? $newDevice['ip_address'] : null,
                ':pppoe_username' => $newDevice['type'] === 'PPPoE' ? $newDevice['pppoe_username'] : null,
                ':router_id' => $newDevice['router_id'],
                ':port' => $newDevice['port'],
                ':access_url' => $access_url
            ]);

            // Add NAT rule on the MikroTik router
            logError("Attempting to connect to MikroTik router to add NAT rule for device {$newDevice['name']}");
            $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password'], $router['port']);
            if (!$client) {
                $msg .= 'Failed to connect to MikroTik router to add NAT rule<br>';
            } else {
                $natIp = $newDevice['type'] === 'PPPoE' ? '1.1.1.1' : $newDevice['ip_address'];
                $natComment = $newDevice['type'] === 'PPPoE' ? "nat_{$newDevice['pppoe_username']}" : $newDevice['name'];
                logError("Connected to MikroTik router. Adding NAT rule for device {$newDevice['name']} on port {$newDevice['port']} with to-addresses={$natIp} and comment={$natComment}");
                $natAdded = Mikrotik::addNatRule($client, $natIp, $newDevice['port'], $natComment);
                if ($natAdded) {
                    logError("Successfully added NAT rule for device {$newDevice['name']} on port {$newDevice['port']}");
                    r2('devices.php', 's', langT('Data Created Successfully'));
                } else {
                    $msg .= 'Failed to add NAT rule on MikroTik router<br>';
                    // Roll back the database insert
                    $stmt = $db->prepare("DELETE FROM tbl_devices WHERE name = :name");
                    $stmt->execute([':name' => $newDevice['name']]);
                    logError("Rolled back device addition due to NAT rule failure for device {$newDevice['name']}");
                }
            }
        } catch (PDOException $e) {
            logError("Error adding device to database: " . $e->getMessage());
            $msg .= 'Failed to add device to database<br>';
        }
    }

    if ($msg != '') {
        r2('devices.php', 'e', $msg);
    }
} else {
    r2('devices.php', 'e', 'Invalid request.');
}
?>