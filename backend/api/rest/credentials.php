
<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Include database connection
require_once __DIR__ . '/../../config/database.php';

// Set JSON content type
header('Content-Type: application/json');

// Create database connection
$database = new Database();
$db = $database->getConnection();

// Handle different HTTP methods
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Fetch TR069 credentials
            $stmt = $db->prepare("SELECT * FROM tr069_config LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'data' => $result
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'No TR069 configuration found'
                ]);
            }
            break;

        case 'POST':
            // Get JSON input
            $json = file_get_contents('php://input');
            $data = json_decode($json, true);

            if (!$data) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Invalid JSON data'
                ]);
                exit;
            }

            // Update TR069 credentials
            $stmt = $db->prepare("
                UPDATE tr069_config 
                SET 
                    username = :username,
                    password = :password,
                    inform_interval = :inform_interval,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = 1
            ");

            $result = $stmt->execute([
                ':username' => $data['username'] ?? null,
                ':password' => $data['password'] ?? null,
                ':inform_interval' => $data['inform_interval'] ?? 300
            ]);

            if ($result) {
                echo json_encode([
                    'success' => true,
                    'message' => 'TR069 configuration updated successfully'
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => 'Failed to update TR069 configuration'
                ]);
            }
            break;

        default:
            // Method not allowed
            header('HTTP/1.1 405 Method Not Allowed');
            echo json_encode([
                'success' => false,
                'error' => 'Method not allowed'
            ]);
            break;
    }
} catch (PDOException $e) {
    // Handle database errors
    echo json_encode([
        'success' => false,
        'error' => 'Database error',
        'details' => $e->getMessage()
    ]);
}
