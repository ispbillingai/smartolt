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
    r2('devices.php', 'e', 'Please log in to edit a device.');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_device'])) {
    $editDevice = [
        'id' => trim($_POST['id'] ?? ''),
        'name' => trim($_POST['name'] ?? ''),
        'type' => trim($_POST['type'] ?? ''),
        'device_type' => trim($_POST['device_type'] ?? ''),
        'ip_address' => trim($_POST['ip_address'] ?? ''),
        'pppoe_username' => trim($_POST['pppoe_username'] ?? ''),
        'router_id' => trim($_POST['router_id'] ?? '')
    ];

    $msg = '';
    if (!is_numeric($editDevice['id'])) {
        $msg .= 'Invalid device ID<br>';
    }
    if (!validateLength($editDevice['name'], 50, 1)) {
        $msg .= 'Device name must be between 1 and 50 characters<br>';
    }
    if (!in_array($editDevice['type'], ['PPPoE', 'Static'])) {
        $msg .= 'Invalid device type (PPPoE/Static) selected<br>';
    }
    if (!in_array($editDevice['device_type'], ['Tenda', 'Ubiquiti', 'Huawei', 'TP-Link', 'Other'])) {
        $msg .= 'Invalid device type selected<br>';
    }
    if ($editDevice['type'] === 'Static' && !filter_var($editDevice['ip_address'], FILTER_VALIDATE_IP)) {
        $msg .= 'Invalid IP address<br>';
    }
    if ($editDevice['type'] === 'PPPoE') {
        if (!validateLength($editDevice['pppoe_username'], 50, 1)) {
            $msg .= 'PPPoE username must be between 1 and 50 characters<br>';
        }
    }
    if (empty($editDevice['router_id']) || !is_numeric($editDevice['router_id'])) {
        $msg .= 'Please select a valid router<br>';
    }

    if ($msg == '') {
        // Check if device name is unique (excluding the current device)
        $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_devices WHERE name = :name AND id != :id");
        $stmt->execute([':name' => $editDevice['name'], ':id' => $editDevice['id']]);
        if ($stmt->fetchColumn() > 0) {
            $msg .= 'Device name already exists<br>';
        }
    }

    if ($msg == '' && $editDevice['type'] === 'PPPoE') {
        // Check if PPPoE username is unique (excluding the current device)
        $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_devices WHERE pppoe_username = :pppoe_username AND id != :id");
        $stmt->execute([':pppoe_username' => $editDevice['pppoe_username'], ':id' => $editDevice['id']]);
        if ($stmt->fetchColumn() > 0) {
            $msg .= 'PPPoE username already exists<br>';
        }
    }

    if ($msg == '' && $editDevice['type'] === 'Static') {
        // Check if IP address already exists on the same router (excluding the current device)
        $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_devices WHERE ip_address = :ip_address AND router_id = :router_id AND id != :id");
        $stmt->execute([
            ':ip_address' => $editDevice['ip_address'],
            ':router_id' => $editDevice['router_id'],
            ':id' => $editDevice['id']
        ]);
        if ($stmt->fetchColumn() > 0) {
            $msg .= 'IP address already exists on this router<br>';
        }
    }

    if ($msg == '' && $editDevice['type'] === 'PPPoE') {
        // Check if PPPoE username already exists on the same router (excluding the current device)
        $stmt = $db->prepare("SELECT COUNT(*) FROM tbl_devices WHERE pppoe_username = :pppoe_username AND router_id = :router_id AND id != :id");
        $stmt->execute([
            ':pppoe_username' => $editDevice['pppoe_username'],
            ':router_id' => $editDevice['router_id'],
            ':id' => $editDevice['id']
        ]);
        if ($stmt->fetchColumn() > 0) {
            $msg .= 'PPPoE username already exists on this router<br>';
        }
    }

    if ($msg == '') {
        // Check if router exists and get its details for NAT rule update
        $stmt = $db->prepare("SELECT id, ip_address, username, password, port FROM tbl_routers WHERE id = :id");
        $stmt->execute([':id' => $editDevice['router_id']]);
        $router = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$router) {
            $msg .= 'Selected router does not exist<br>';
        }
    }

    if ($msg == '') {
        try {
            // Fetch the current device details to determine the existing NAT rule
            $stmt = $db->prepare("SELECT name, type, ip_address, pppoe_username, port, router_id FROM tbl_devices WHERE id = :id");
            $stmt->execute([':id' => $editDevice['id']]);
            $currentDevice = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$currentDevice) {
                $msg .= 'Device not found<br>';
            } else {
                // Determine the current NAT rule comment
                $currentNatComment = $currentDevice['type'] === 'PPPoE' ? "nat_{$currentDevice['pppoe_username']}" : $currentDevice['name'];

                // Connect to the MikroTik router to remove the existing NAT rule
                logError("Attempting to connect to MikroTik router to update NAT rule for device {$currentDevice['name']}");
                $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password'], $router['port']);
                if (!$client) {
                    $msg .= 'Failed to connect to MikroTik router to update NAT rule<br>';
                } else {
                    // Remove the existing NAT rule using the current comment
                    logError("Removing existing NAT rule with comment={$currentNatComment}");
                    $natRemoved = Mikrotik::removeNatRule($client, $currentNatComment);
                    if (!$natRemoved) {
                        // Log the failure but allow the edit to proceed since the rule might have been manually removed
                        logError("Failed to remove existing NAT rule on MikroTik router (rule may not exist or removal failed)");
                    }
                }

                // Proceed with the update regardless of NAT rule removal success
                // Update the device in the database
                $port = $currentDevice['port'];
                $access_url = "http://{$subdomain_prefix}{$editDevice['router_id']}.{$domain_suffix}:{$port}";
                $stmt = $db->prepare("UPDATE tbl_devices SET name = :name, type = :type, device_type = :device_type, ip_address = :ip_address, pppoe_username = :pppoe_username, router_id = :router_id, access_url = :access_url WHERE id = :id");
                $stmt->execute([
                    ':name' => $editDevice['name'],
                    ':type' => $editDevice['type'],
                    ':device_type' => $editDevice['device_type'],
                    ':ip_address' => $editDevice['type'] === 'Static' ? $editDevice['ip_address'] : null,
                    ':pppoe_username' => $editDevice['type'] === 'PPPoE' ? $editDevice['pppoe_username'] : null,
                    ':router_id' => $editDevice['router_id'],
                    ':access_url' => $access_url,
                    ':id' => $editDevice['id']
                ]);

                // Add a new NAT rule with the updated details
                $natIp = $editDevice['type'] === 'PPPoE' ? '1.1.1.1' : $editDevice['ip_address'];
                $natComment = $editDevice['type'] === 'PPPoE' ? "nat_{$editDevice['pppoe_username']}" : $editDevice['name'];
                logError("Adding new NAT rule for device {$editDevice['name']} on port {$port} with to-addresses={$natIp} and comment={$natComment}");
                $natAdded = Mikrotik::addNatRule($client, $natIp, $port, $natComment);
                if ($natAdded) {
                    logError("Successfully added new NAT rule for device {$editDevice['name']} on port {$port}");
                    r2('devices.php', 's', langT('Data Updated Successfully'));
                } else {
                    $msg .= 'Failed to add new NAT rule on MikroTik router<br>';
                    // Roll back the database update
                    $stmt = $db->prepare("UPDATE tbl_devices SET name = :name, type = :type, ip_address = :ip_address, pppoe_username = :pppoe_username, router_id = :router_id WHERE id = :id");
                    $stmt->execute([
                        ':name' => $currentDevice['name'],
                        ':type' => $currentDevice['type'],
                        ':ip_address' => $currentDevice['ip_address'],
                        ':pppoe_username' => $currentDevice['pppoe_username'],
                        ':router_id' => $currentDevice['router_id'],
                        ':id' => $editDevice['id']
                    ]);
                    logError("Rolled back device update due to NAT rule failure for device {$editDevice['name']}");
                }
            }
        } catch (PDOException $e) {
            logError("Error updating device: " . $e->getMessage());
            $msg .= 'Failed to update device<br>';
        }
    }

    if ($msg != '') {
        r2('devices.php', 'e', $msg);
    }
} else {
    r2('devices.php', 'e', 'Invalid request.');
}
?>