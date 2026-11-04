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
    r2('devices.php', 'e', 'Please log in to delete a device.');
}

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    logError("Database connection failed: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_device'])) {
    $deviceId = trim($_POST['id'] ?? '');

    $msg = '';
    if (!is_numeric($deviceId)) {
        $msg .= 'Invalid device ID<br>';
    }

    if ($msg == '') {
        try {
            // Fetch the device details to determine the NAT rule comment and router details
            $stmt = $db->prepare("SELECT name, type, pppoe_username, router_id FROM tbl_devices WHERE id = :id");
            $stmt->execute([':id' => $deviceId]);
            $device = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$device) {
                $msg .= 'Device not found<br>';
            } else {
                // Determine the NAT rule comment
                $natComment = $device['type'] === 'PPPoE' ? "nat_{$device['pppoe_username']}" : $device['name'];

                // Fetch the router details for MikroTik connection
                $stmt = $db->prepare("SELECT ip_address, username, password, port FROM tbl_routers WHERE id = :id");
                $stmt->execute([':id' => $device['router_id']]);
                $router = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$router) {
                    $msg .= 'Router not found for device<br>';
                } else {
                    // Connect to the MikroTik router to remove the NAT rule
                    logError("Attempting to connect to MikroTik router to remove NAT rule for device {$device['name']}");
                    $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password'], $router['port']);
                    if ($client) {
                        logError("Removing NAT rule with comment={$natComment}");
                        $natRemoved = Mikrotik::removeNatRule($client, $natComment);
                        if (!$natRemoved) {
                            logError("Failed to remove NAT rule on MikroTik router for device {$device['name']} (rule may not exist or removal failed)");
                        } else {
                            logError("Successfully removed NAT rule for device {$device['name']}");
                        }
                    } else {
                        logError("Failed to connect to MikroTik router to remove NAT rule for device {$device['name']}");
                    }
                }

                // Proceed with deleting the device from the database regardless of NAT rule removal success
                $stmt = $db->prepare("DELETE FROM tbl_devices WHERE id = :id");
                $stmt->execute([':id' => $deviceId]);
                r2('devices.php', 's', langT('Data Deleted Successfully'));
            }
        } catch (PDOException $e) {
            logError("Error deleting device: " . $e->getMessage());
            $msg .= 'Failed to delete device<br>';
        }
    }

    if ($msg != '') {
        r2('devices.php', 'e', $msg);
    }
} else {
    r2('devices.php', 'e', 'Invalid request.');
}
?>