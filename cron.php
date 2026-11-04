<?php
// cron.php

// Include database configuration
require_once __DIR__ . '/backend/config/database.php';

// Connect to the database
$database = new Database();
$db = $database->getConnection();

// Check if inform_interval column exists and add it if it doesn't
$stmt = $db->query("SHOW COLUMNS FROM devices LIKE 'inform_interval'");
if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
    $db->exec("ALTER TABLE devices ADD COLUMN inform_interval INT DEFAULT NULL");
}

?>