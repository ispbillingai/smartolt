<?php
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/database.php';

// Establish database connection
try {
    $db = (new Database())->getConnection();
} catch (Exception $e) {
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'error' => 'Database connection failed',
        'details' => $e->getMessage()
    ]);
    exit;
}

// Handle list_all_devices request (GET or POST)
if (isset($_GET['list_all_devices']) || isset($_POST['list_all_devices'])) {
    try {
        // Get parameters (support both GET and POST)
        $method = $_SERVER['REQUEST_METHOD'];
        if ($method === 'POST') {
            $search = isset($_POST['search']) ? trim($_POST['search']) : '';
            $page = isset($_POST['page']) ? max(1, (int)$_POST['page']) : 1;
            $limit = isset($_POST['limit']) ? max(1, (int)$_POST['limit']) : 10;
        } else {
            $search = isset($_GET['search']) ? trim($_GET['search']) : '';
            $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
            $limit = isset($_GET['limit']) ? max(1, (int)$_GET['limit']) : 1000; // Increased limit for fetching all devices
        }

        // Base query
        $query = "
            SELECT 
                d.id,
                d.name,
                d.type,
                d.device_type,
                d.ip_address,
                d.pppoe_username,
                d.router_id,
                d.port,
                d.access_url,
                r.name AS router_name
            FROM tbl_devices d
            LEFT JOIN tbl_routers r ON d.router_id = r.id
        ";

        // Add search conditions
        $conditions = [];
        $params = [];
        if (!empty($search)) {
            $searchLower = "%" . strtolower($search) . "%";
            $conditions[] = "(LOWER(d.pppoe_username) LIKE :search 
                            OR LOWER(d.id) LIKE :search 
                            OR LOWER(d.name) LIKE :search 
                            OR LOWER(d.type) LIKE :search 
                            OR LOWER(d.device_type) LIKE :search 
                            OR LOWER(d.ip_address) LIKE :search 
                            OR LOWER(r.name) LIKE :search 
                            OR LOWER(d.port) LIKE :search)";
            $params[':search'] = $searchLower;
        }

        // Append conditions to query
        if (!empty($conditions)) {
            $query .= " WHERE " . implode(" AND ", $conditions);
        }

        // Order by ID descending
        $query .= " ORDER BY d.id DESC";

        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) FROM tbl_devices d LEFT JOIN tbl_routers r ON d.router_id = r.id";
        if (!empty($conditions)) {
            $countQuery .= " WHERE " . implode(" AND ", $conditions);
        }
        $stmt = $db->prepare($countQuery);
        $stmt->execute($params);
        $totalDevices = $stmt->fetchColumn();
        $totalPages = max(1, ceil($totalDevices / $limit));

        // Adjust page number to be within valid range
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $limit;

        // Add pagination to the query
        $query .= " LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'devices' => $devices,
            'current_page' => $page,
            'total_pages' => $totalPages,
            'total_devices' => $totalDevices
        ]);
        exit;
    } catch (PDOException $e) {
        error_log("Database error listing devices: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Database error',
            'details' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle device retrieval by identifier (GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['device_id']) || isset($_GET['serial']))) {
    $deviceIdentifier = $_GET['device_id'] ?? ($_GET['serial'] ?? null);

    if (empty($deviceIdentifier)) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Device identifier (device_id or serial) is required',
            'hint' => 'Use device_id, serial, or list_all_devices=1'
        ]);
        exit;
    }

    $isSerial = isset($_GET['serial']);
    $whereClause = $isSerial ? "d.serial = :identifier" : "d.id = :identifier";

    try {
        $stmt = $db->prepare("
            SELECT 
                d.id,
                d.name,
                d.type,
                d.device_type,
                d.ip_address,
                d.pppoe_username,
                d.router_id,
                d.port,
                d.access_url,
                r.name AS router_name
            FROM tbl_devices d
            LEFT JOIN tbl_routers r ON d.router_id = r.id
            WHERE {$whereClause}
            LIMIT 1
        ");
        $stmt->bindParam(':identifier', $deviceIdentifier);
        $stmt->execute();
        $device = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$device) {
            http_response_code(404);
            echo json_encode([
                'error' => 'Device not found',
                'details' => "No device found with " . ($isSerial ? "serial" : "ID") . ": {$deviceIdentifier}"
            ]);
            exit;
        }

        $response = [
            'success' => true,
            'device' => $device
        ];

        echo json_encode($response);
        exit;
    } catch (PDOException $e) {
        error_log("Database error fetching device: " . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'error' => 'Database error',
            'details' => $e->getMessage()
        ]);
        exit;
    }
}

// Handle POST requests for actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Invalid JSON',
            'details' => 'Failed to decode JSON body: ' . json_last_error_msg()
        ]);
        exit;
    }

    $action = $input['action'] ?? null;

    // Update device password
    if ($action === 'update_password') {
        $deviceId = $input['device_id'] ?? null;
        $newPassword = $input['new_password'] ?? null;

        // Validate inputs
        if (empty($deviceId) || !filter_var($deviceId, FILTER_VALIDATE_INT)) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Invalid device ID',
                'details' => 'Device ID must be a valid integer'
            ]);
            exit;
        }

        if (empty($newPassword) || strlen($newPassword) < 8) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Invalid password',
                'details' => 'New password must be at least 8 characters long'
            ]);
            exit;
        }

        try {
            // Check if the device exists
            $stmt = $db->prepare("SELECT id FROM tbl_devices WHERE id = :id LIMIT 1");
            $stmt->bindParam(':id', $deviceId);
            $stmt->execute();
            $device = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$device) {
                http_response_code(404);
                echo json_encode([
                    'error' => 'Device not found',
                    'details' => "No device found with ID: {$deviceId}"
                ]);
                exit;
            }

            // Update the pppoe_password
            $stmt = $db->prepare("UPDATE tbl_devices SET pppoe_password = :password WHERE id = :id");
            $stmt->bindParam(':password', $newPassword);
            $stmt->bindParam(':id', $deviceId);
            $stmt->execute();

            error_log("Successfully updated password for device ID {$deviceId}");
            echo json_encode([
                'success' => true,
                'message' => 'Password updated successfully'
            ]);
            exit;
        } catch (PDOException $e) {
            error_log("Database error updating password for device ID {$deviceId}: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Database error',
                'details' => $e->getMessage()
            ]);
            exit;
        }
    }

    // Update dashboard login password
    if ($action === 'update_login_password') {
        $newPassword = $input['new_password'] ?? null;

        // Validate input
        if (empty($newPassword) || strlen($newPassword) < 8) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Invalid password',
                'details' => 'New password must be at least 8 characters long'
            ]);
            exit;
        }

        try {
            // Check if the admin user exists (assuming id=1 for the admin user)
            $stmt = $db->prepare("SELECT id FROM users WHERE id = 1 LIMIT 1");
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                http_response_code(404);
                echo json_encode([
                    'error' => 'User not found',
                    'details' => 'Admin user with ID 1 not found'
                ]);
                exit;
            }

            // Update the password (password field is named 'password')
            $stmt = $db->prepare("UPDATE users SET password = :password WHERE id = 1");
            $stmt->bindParam(':password', $newPassword);
            $stmt->execute();

            error_log("Successfully updated dashboard login password for admin user ID 1");
            echo json_encode([
                'success' => true,
                'message' => 'Dashboard login password updated successfully'
            ]);
            exit;
        } catch (PDOException $e) {
            error_log("Database error updating dashboard login password: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'error' => 'Database error',
                'details' => $e->getMessage()
            ]);
            exit;
        }
    }

    // If action is not recognized
    http_response_code(400);
    echo json_encode([
        'error' => 'Invalid action',
        'details' => 'Action must be "update_password" or "update_login_password"'
    ]);
    exit;
}

// Unsupported HTTP method or invalid request
http_response_code(405);
echo json_encode([
    'error' => 'Method not allowed',
    'details' => "The HTTP method '{$_SERVER['REQUEST_METHOD']}' is not supported or the request is invalid"
]);
?>