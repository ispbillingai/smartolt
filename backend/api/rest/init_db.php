
<?php
// Database initialization script for REST API
header('Content-Type: application/json');

// Include database connection
require_once __DIR__ . '/../../config/database.php';
$database = new Database();
$db = $database->getConnection();

// Check connection
if (!$db) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

try {
    // Create device_tasks table if it doesn't exist
    $createTasksTable = "CREATE TABLE IF NOT EXISTS device_tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        device_id INT NOT NULL,
        task_type VARCHAR(50) NOT NULL COMMENT 'wifi, wan, reboot, etc.',
        task_data TEXT NOT NULL COMMENT 'JSON encoded parameters',
        status ENUM('pending', 'in_progress', 'completed', 'failed', 'canceled') NOT NULL DEFAULT 'pending',
        message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB";
    
    $db->exec($createTasksTable);

    // Create tr069_config table if it doesn't exist
    $createConfigTable = "CREATE TABLE IF NOT EXISTS tr069_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        password VARCHAR(50) NOT NULL,
        inform_interval INT NOT NULL DEFAULT 300,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB";
    
    $db->exec($createConfigTable);

    // Insert default tr069_config if none exists
    $checkConfig = $db->query("SELECT COUNT(*) FROM tr069_config");
    if ($checkConfig->fetchColumn() == 0) {
        $insertDefault = "INSERT INTO tr069_config (username, password, inform_interval) 
                         VALUES ('admin', 'admin', 300)";
        $db->exec($insertDefault);
    }
    
    // Add wifi_password column to devices table if it doesn't exist
    try {
        $checkColumn = $db->query("SHOW COLUMNS FROM devices LIKE 'wifi_password'");
        if ($checkColumn->rowCount() == 0) {
            $db->exec("ALTER TABLE devices ADD COLUMN wifi_password VARCHAR(64) NULL AFTER ssid");
        }
    } catch (PDOException $e) {
        // This is expected to fail if devices table doesn't exist yet - that's OK
    }
    
    // Success
    echo json_encode([
        'success' => true,
        'message' => 'Database initialization completed successfully'
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'error' => 'Database initialization failed',
        'details' => $e->getMessage()
    ]);
}
