-- Simplified Devices table with additional Mikrotik parameters
CREATE TABLE IF NOT EXISTS devices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    serial_number VARCHAR(64) UNIQUE,
    manufacturer VARCHAR(64),
    model_name VARCHAR(64),
    mac_address VARCHAR(17),
    ip_address VARCHAR(45),
    last_contact DATETIME,
    status ENUM('online', 'offline', 'provisioning'),
    software_version VARCHAR(32),
    hardware_version VARCHAR(32),
    ssid VARCHAR(64),
    ssid_password VARCHAR(64),
    uptime INT UNSIGNED,
    local_admin_password VARCHAR(255),
    tr069_password VARCHAR(255),
    connected_devices VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Parameters table to store TR-069 parameters
CREATE TABLE IF NOT EXISTS parameters (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT,
    param_name VARCHAR(255) NOT NULL,
    param_value TEXT,
    param_type VARCHAR(32) DEFAULT 'string',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    UNIQUE KEY device_param (device_id, param_name)
);

-- WiFi Connected Clients table with enhanced details
CREATE TABLE IF NOT EXISTS connected_clients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_id INT,
    mac_address VARCHAR(17),
    ip_address VARCHAR(45),
    hostname VARCHAR(64),
    signal_strength INT,
    connected_since DATETIME,
    last_seen DATETIME,
    is_active BOOLEAN DEFAULT TRUE,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
);

-- Sessions table remains unchanged
CREATE TABLE IF NOT EXISTS sessions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    device_serial VARCHAR(64),
    session_id VARCHAR(64) UNIQUE,
    created_at DATETIME,
    expires_at DATETIME,
    FOREIGN KEY (device_serial) REFERENCES devices(serial_number)
);

-- New Users table for ACS authentication with plain password
CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(64) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') DEFAULT 'user',
    last_login DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    timezone VARCHAR(64) DEFAULT 'Africa/Nairobi',
    display_name VARCHAR(64)
);

-- TR069 Configuration table
CREATE TABLE IF NOT EXISTS tr069_config (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(64) NOT NULL DEFAULT 'admin',
    password VARCHAR(255) NOT NULL DEFAULT 'admin',
    inform_interval INT NOT NULL DEFAULT 300,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Insert default admin user with plain password 'admin'
INSERT INTO users (username, password, role) 
VALUES ('admin', 'admin', 'admin')
ON DUPLICATE KEY UPDATE username=username;

-- Insert default TR069 configuration
INSERT INTO tr069_config (username, password, inform_interval)
VALUES ('admin', 'admin', 300)
ON DUPLICATE KEY UPDATE username=username;

-- Create indexes
CREATE INDEX idx_device_serial ON devices(serial_number);
CREATE INDEX idx_device_status ON devices(status);
CREATE INDEX idx_device_last_contact ON devices(last_contact);
CREATE INDEX idx_device_mac ON devices(mac_address);
CREATE INDEX idx_username ON users(username);
CREATE INDEX idx_user_timezone ON users(timezone);
CREATE INDEX idx_user_display_name ON users(display_name);
CREATE INDEX idx_parameter_name ON parameters(param_name);
CREATE INDEX idx_client_ip ON connected_clients(ip_address);
CREATE INDEX idx_client_hostname ON connected_clients(hostname);
