<?php
/**
 * hotspotap remote mirror API  (runs at {sub}.ispledger.click)
 * --------------------------------------------------------------------------
 * RADIUS (the dashboard) is the brain. This endpoint is a passive mirror:
 * it stores the ready-made JSON that RADIUS pushes and serves it back when
 * RADIUS pulls.
 *
 * Actions:
 *   POST {action:"enable",          sub, token}
 *   POST {action:"register_router", sub, token, router_id, router_name,
 *                                   vpn_name, vpn_user, vpn_ip, mac_address,
 *                                   ros_version}
 *   GET  ?action=list_routers&sub=..&token=..
 *
 * Keep the token scheme in sync with system/controllers/hotspotap.php on the
 * RADIUS side: token = 'freeisp_hotspotap_' . sub
 **/

declare(strict_types=1);
header('Content-Type: application/json');

function out(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/* ---------- read input (JSON body for POST, query for GET) -------------- */
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$in     = $_GET;
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $json = json_decode((string)$raw, true);
    if (is_array($json)) {
        $in = array_merge($in, $json);
    } else {
        $in = array_merge($in, $_POST);
    }
}

$action = (string)($in['action'] ?? '');
$sub    = (string)($in['sub'] ?? '');
$token  = (string)($in['token'] ?? '');

if ($sub === '' || !preg_match('/^[a-z0-9\-]+$/', $sub)) {
    out(['success' => false, 'error' => 'Invalid sub'], 400);
}

/* ---------- shared-secret check ---------------------------------------- */
$expected = 'freeisp_hotspotap_' . $sub;
if (!hash_equals($expected, $token)) {
    out(['success' => false, 'error' => 'Unauthorized'], 401);
}

/* ---------- database --------------------------------------------------- */
$dbFile = __DIR__ . '/backend/config/database.php';
if (!file_exists($dbFile)) {
    out(['success' => false, 'error' => 'Database not configured on remote server'], 500);
}
require_once $dbFile;

try {
    $database = new Database();
    $db = $database->getConnection();
} catch (Throwable $e) {
    out(['success' => false, 'error' => 'Database connection failed'], 500);
}

/* ---------- ensure mirror table --------------------------------------- */
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `hotspot_routers` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `router_id` INT UNSIGNED NOT NULL,
          `router_name` VARCHAR(100) NOT NULL,
          `vpn_name` VARCHAR(64) NOT NULL,
          `vpn_user` VARCHAR(64) NOT NULL,
          `vpn_ip` VARCHAR(45) NULL DEFAULT NULL,
          `mac_address` VARCHAR(17) NULL DEFAULT NULL,
          `ros_version` TINYINT UNSIGNED NULL DEFAULT NULL,
          `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_router` (`router_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
} catch (Throwable $e) {
    out(['success' => false, 'error' => 'Schema error'], 500);
}

/* ---------- ensure AP IP table ---------------------------------------- */
try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS `hotspot_aps` (
          `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
          `router_id` INT UNSIGNED NOT NULL,
          `mac` VARCHAR(17) NOT NULL,
          `ip` VARCHAR(45) NULL DEFAULT NULL,
          `port` INT NULL DEFAULT NULL,
          `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_ap` (`router_id`, `mac`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    // self-heal: older tables may not have the port column
    $hasPort = $db->query("SHOW COLUMNS FROM `hotspot_aps` LIKE 'port'")->fetch();
    if (!$hasPort) {
        $db->exec("ALTER TABLE `hotspot_aps` ADD COLUMN `port` INT NULL DEFAULT NULL AFTER `ip`");
    }
    // self-heal: name column (the label chosen in the billing system)
    $hasName = $db->query("SHOW COLUMNS FROM `hotspot_aps` LIKE 'name'")->fetch();
    if (!$hasName) {
        $db->exec("ALTER TABLE `hotspot_aps` ADD COLUMN `name` VARCHAR(100) NULL DEFAULT NULL AFTER `mac`");
    }
} catch (Throwable $e) {
    out(['success' => false, 'error' => 'Schema error'], 500);
}

/* ---------- shared MAC validator -------------------------------------- */
function valid_mac(string $mac): bool
{
    return (bool)preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/', $mac);
}

/* ---------- resolve a router's OVPN tunnel IP by common-name ----------- *
 * The OVPN server uses username-as-common-name + ifconfig-pool-persist, so
 * ipp.txt maps "<common-name>,<tunnel-ip>". Returns null if not connected yet.
 */
function ovpn_ip_for(string $name): ?string
{
    $name = strtolower(trim($name));
    foreach (['/etc/openvpn/server/ipp.txt', '/etc/openvpn/ipp.txt'] as $f) {
        if (!is_readable($f)) continue;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $parts = explode(',', trim($line));
            if (count($parts) >= 2 && strtolower(trim($parts[0])) === $name) {
                $ip = trim($parts[1]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
    }
    return null;
}

/* ---------- dispatch --------------------------------------------------- */
switch ($action) {

    case 'enable':
        // Provisioning hook. The table is already ensured above; this just
        // confirms the remote mirror is reachable and ready.
        out(['success' => true, 'enabled' => true]);
        break;

    case 'register_vpn':
        // Add the OVPN username/password (username == password) to the server's
        // userpass file so the OVPN server authenticates this router's tunnel.
        $user = trim((string)($in['vpn_user'] ?? ''));
        if ($user === '' || !preg_match('/^[a-z0-9\-]+$/', $user)) {
            out(['success' => false, 'error' => 'Invalid vpn_user'], 400);
        }
        $userpassFile = '/etc/openvpn/userpass';

        try {
            // skip if already present
            $exists = false;
            if (is_readable($userpassFile)) {
                foreach (file($userpassFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $parts = preg_split('/\s+/', trim($line));
                    if (($parts[0] ?? '') === $user) { $exists = true; break; }
                }
            }
            if (!$exists) {
                $ok = @file_put_contents($userpassFile, "$user $user\n", FILE_APPEND | LOCK_EX);
                if ($ok === false) {
                    out(['success' => false, 'error' => 'Failed to write userpass'], 500);
                }
                @exec('sudo systemctl reload openvpn@server 2>&1');
            }
            out(['success' => true, 'created' => !$exists]);
        } catch (Throwable $e) {
            out(['success' => false, 'error' => 'VPN register failed'], 500);
        }
        break;

    case 'create_router_site':
        // Build the per-router Apache reverse-proxy vhost + DNS record by running
        // the root-owned script. Requires a sudoers NOPASSWD entry for www-data.
        $name = strtolower(trim((string)($in['name'] ?? '')));
        $ip   = trim((string)($in['ip'] ?? ''));
        if ($name === '' || !preg_match('/^[a-z0-9\-]+$/', $name)) {
            out(['success' => false, 'error' => 'Invalid name'], 400);
        }
        // Resolve the router's tunnel IP from the OVPN persist file if not given
        // (RADIUS fires the hook without needing to know the IP).
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = (string)(ovpn_ip_for($name) ?? '');
        }
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            // Router has not connected to the VPN yet -> tell RADIUS to retry.
            out(['success' => false, 'pending' => true, 'error' => 'tunnel IP not available yet']);
        }
        $o = []; $rc = 0;
        @exec('sudo /root/manage_router_site.sh ' . escapeshellarg($name) . ' ' . escapeshellarg($ip) . ' 2>&1', $o, $rc);
        if ($rc === 0) {
            out(['success' => true, 'ip' => $ip]);
        } else {
            out(['success' => false, 'error' => 'site script failed: ' . implode(' | ', $o)], 500);
        }
        break;

    case 'register_router':
        $routerId   = (int)($in['router_id'] ?? 0);
        $routerName = trim((string)($in['router_name'] ?? ''));
        $vpnName    = trim((string)($in['vpn_name'] ?? ''));
        $vpnUser    = trim((string)($in['vpn_user'] ?? ''));
        $vpnIp      = isset($in['vpn_ip']) && $in['vpn_ip'] !== '' ? trim((string)$in['vpn_ip']) : null;
        $mac        = isset($in['mac_address']) && $in['mac_address'] !== '' ? trim((string)$in['mac_address']) : null;
        $rosVer     = isset($in['ros_version']) && $in['ros_version'] !== '' ? (int)$in['ros_version'] : null;

        if ($routerId <= 0 || $routerName === '' || $vpnUser === '') {
            out(['success' => false, 'error' => 'Missing required fields'], 400);
        }

        try {
            // upsert by router_id
            $stmt = $db->prepare("
                INSERT INTO `hotspot_routers`
                    (router_id, router_name, vpn_name, vpn_user, vpn_ip, mac_address, ros_version)
                VALUES (:rid, :rname, :vname, :vuser, :vip, :mac, :ros)
                ON DUPLICATE KEY UPDATE
                    router_name = VALUES(router_name),
                    vpn_name    = VALUES(vpn_name),
                    vpn_user    = VALUES(vpn_user),
                    vpn_ip      = COALESCE(VALUES(vpn_ip), vpn_ip),
                    mac_address = COALESCE(VALUES(mac_address), mac_address),
                    ros_version = VALUES(ros_version)
            ");
            $stmt->execute([
                ':rid'   => $routerId,
                ':rname' => $routerName,
                ':vname' => $vpnName,
                ':vuser' => $vpnUser,
                ':vip'   => $vpnIp,
                ':mac'   => $mac,
                ':ros'   => $rosVer,
            ]);
            out(['success' => true, 'router_id' => $routerId]);
        } catch (Throwable $e) {
            out(['success' => false, 'error' => 'Insert failed'], 500);
        }
        break;

    case 'list_routers':
        try {
            $rows = $db->query("SELECT router_id, router_name, vpn_name, vpn_user, vpn_ip, mac_address, ros_version, updated_at FROM `hotspot_routers` ORDER BY router_name ASC")
                       ->fetchAll(PDO::FETCH_ASSOC);
            out(['success' => true, 'routers' => $rows]);
        } catch (Throwable $e) {
            out(['success' => false, 'error' => 'Query failed'], 500);
        }
        break;

    case 'update_ip':
        // Reported by the on-router scheduler (GET) or by RADIUS on refresh (POST).
        $routerId = (int)($in['router_id'] ?? 0);
        $mac      = strtolower(trim((string)($in['mac'] ?? '')));
        $ip       = trim((string)($in['ip'] ?? ''));

        if ($routerId <= 0 || !valid_mac($mac)) {
            out(['success' => false, 'error' => 'Missing/invalid router_id or mac'], 400);
        }
        // Require a valid IP: an empty/blank value must never NULL out a known IP
        // (that would drop the AP from list_forwards and break remote access).
        if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
            out(['success' => false, 'error' => 'Missing/invalid ip'], 400);
        }

        try {
            // upsert IP without clobbering an already-registered port
            $stmt = $db->prepare("
                INSERT INTO `hotspot_aps` (router_id, mac, ip)
                VALUES (:rid, :mac, :ip)
                ON DUPLICATE KEY UPDATE ip = VALUES(ip)
            ");
            $stmt->execute([':rid' => $routerId, ':mac' => $mac, ':ip' => $ip]);
            out(['success' => true]);
        } catch (Throwable $e) {
            out(['success' => false, 'error' => 'Insert failed'], 500);
        }
        break;

    case 'register_ap':
        // RADIUS assigns the front-door port for an AP and pushes its label.
        // Also used to update the name on its own (name-only edit): when no port
        // is sent, the existing port is kept.
        $routerId = (int)($in['router_id'] ?? 0);
        $mac      = strtolower(trim((string)($in['mac'] ?? '')));
        $port     = (int)($in['port'] ?? 0);
        $name     = trim((string)($in['name'] ?? ''));
        if ($name === '') { $name = null; }

        if ($routerId <= 0 || !valid_mac($mac)) {
            out(['success' => false, 'error' => 'Missing/invalid router_id or mac'], 400);
        }

        try {
            // On insert, port must be valid. On update, keep the old port if none
            // (or an invalid one) was sent, so a name-only push never nulls it.
            $portOk = ($port >= 1 && $port <= 65535);
            $stmt = $db->prepare("
                INSERT INTO `hotspot_aps` (router_id, mac, name, port)
                VALUES (:rid, :mac, :name, :port)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    port = IF(VALUES(port) IS NULL, port, VALUES(port))
            ");
            $stmt->execute([
                ':rid'  => $routerId,
                ':mac'  => $mac,
                ':name' => $name,
                ':port' => $portOk ? $port : null,
            ]);
            out(['success' => true]);
        } catch (Throwable $e) {
            out(['success' => false, 'error' => 'Insert failed'], 500);
        }
        break;

    case 'remove_ap':
        $routerId = (int)($in['router_id'] ?? 0);
        $mac      = strtolower(trim((string)($in['mac'] ?? '')));
        if ($routerId <= 0 || !valid_mac($mac)) {
            out(['success' => false, 'error' => 'Missing/invalid router_id or mac'], 400);
        }
        try {
            $stmt = $db->prepare("DELETE FROM `hotspot_aps` WHERE router_id = :rid AND mac = :mac");
            $stmt->execute([':rid' => $routerId, ':mac' => $mac]);
            out(['success' => true]);
        } catch (Throwable $e) {
            out(['success' => false, 'error' => 'Delete failed'], 500);
        }
        break;

    case 'list_aps':
        try {
            $rows = $db->query("SELECT router_id, mac, name, ip, port, updated_at FROM `hotspot_aps` ORDER BY updated_at DESC")
                       ->fetchAll(PDO::FETCH_ASSOC);
            out(['success' => true, 'aps' => $rows]);
        } catch (Throwable $e) {
            out(['success' => false, 'error' => 'Query failed'], 500);
        }
        break;

    case 'list_forwards':
        // For the .biz-side forwarder daemon: front-door port -> router tunnel IP.
        // The router then dst-NATs that port to the AP. Returns only rows that
        // have both a port and a known router tunnel IP.
        try {
            $rows = $db->query("
                SELECT a.port AS port, r.vpn_ip AS target_ip, a.router_id AS router_id, a.mac AS mac
                FROM `hotspot_aps` a
                JOIN `hotspot_routers` r ON r.router_id = a.router_id
                WHERE a.port IS NOT NULL AND r.vpn_ip IS NOT NULL AND r.vpn_ip <> ''
                ORDER BY a.port ASC
            ")->fetchAll(PDO::FETCH_ASSOC);
            out(['success' => true, 'forwards' => $rows]);
        } catch (Throwable $e) {
            out(['success' => false, 'error' => 'Query failed'], 500);
        }
        break;

    case 'update_login_password':
        // Set the dashboard admin password (id=1). Passwords are stored plaintext
        // in this app (login checks $user['password'] === input), so no hashing.
        $newPassword = trim((string)($in['new_password'] ?? ''));
        if (strlen($newPassword) < 8) {
            out(['success' => false, 'error' => 'Password must be at least 8 characters'], 400);
        }
        try {
            $stmt = $db->prepare("UPDATE `users` SET `password` = :p WHERE id = 1");
            $stmt->execute([':p' => $newPassword]);
            out(['success' => true]);
        } catch (Throwable $e) {
            out(['success' => false, 'error' => 'Update failed'], 500);
        }
        break;

    default:
        out(['success' => false, 'error' => 'Unknown action'], 400);
}
