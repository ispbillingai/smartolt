-- SmartOLT site database. One database per ISP, created by install.sh.

CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') DEFAULT 'user',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `timezone` varchar(64) DEFAULT 'Africa/Nairobi',
  `display_name` varchar(64) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `email_notifications` tinyint(1) DEFAULT '0',
  `theme` enum('light','dark') DEFAULT 'light',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default login admin / admin. The first sign in must change it. Stored as a bcrypt hash.
INSERT IGNORE INTO `users` (`id`, `username`, `password`, `role`, `display_name`, `company_name`) VALUES
(1, 'admin', '$2y$10$be2BIAM4JD0lAknMRPJMhO7WhFagS75X0cKNT6o0bZS8HWSqAywCq', 'admin', 'FreeIspRadius', 'FreeIspRadius');

CREATE TABLE IF NOT EXISTS `olts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `host` varchar(190) NOT NULL,
  `telnet_port` smallint unsigned NOT NULL DEFAULT 23,
  `username` varchar(100) NOT NULL,
  `password_enc` text NOT NULL,
  `vendor` varchar(30) NOT NULL DEFAULT 'huawei',
  `model` varchar(60) NOT NULL DEFAULT '',
  `version` varchar(100) NOT NULL DEFAULT '',
  `status` varchar(20) NOT NULL DEFAULT 'new',
  `last_error` varchar(500) NOT NULL DEFAULT '',
  `boards_json` mediumtext,
  `profiles_json` mediumtext,
  `last_polled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Authorized ONUs as last read from the OLT. The OLT is the source of truth; this is the copy
-- the pages read so a list never waits on a Telnet session.
CREATE TABLE IF NOT EXISTS `onus` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `olt_id` int unsigned NOT NULL,
  `frame` tinyint unsigned NOT NULL,
  `slot` tinyint unsigned NOT NULL,
  `port` tinyint unsigned NOT NULL,
  `ont_id` smallint unsigned NOT NULL,
  `sn` varchar(32) NOT NULL,
  `description` varchar(190) NOT NULL DEFAULT '',
  `control_flag` varchar(20) NOT NULL DEFAULT '',
  `run_state` varchar(20) NOT NULL DEFAULT '',
  `config_state` varchar(20) NOT NULL DEFAULT '',
  `match_state` varchar(20) NOT NULL DEFAULT '',
  `rx_power` decimal(6,2) DEFAULT NULL,
  `tx_power` decimal(6,2) DEFAULT NULL,
  `olt_rx_power` decimal(6,2) DEFAULT NULL,
  `temperature` smallint DEFAULT NULL,
  `vlan` smallint unsigned DEFAULT NULL,
  `line_profile` smallint unsigned DEFAULT NULL,
  `srv_profile` smallint unsigned DEFAULT NULL,
  `customer_ref` varchar(100) NOT NULL DEFAULT '',
  `authorized_at` datetime DEFAULT NULL,
  `signal_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_position` (`olt_id`,`frame`,`slot`,`port`,`ont_id`),
  KEY `idx_sn` (`sn`),
  CONSTRAINT `fk_onus_olt` FOREIGN KEY (`olt_id`) REFERENCES `olts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Every change made on an OLT, with the commands sent and what the OLT answered.
CREATE TABLE IF NOT EXISTS `actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `olt_id` int unsigned DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `target` varchar(100) NOT NULL DEFAULT '',
  `ok` tinyint(1) NOT NULL DEFAULT 0,
  `message` varchar(500) NOT NULL DEFAULT '',
  `source` varchar(20) NOT NULL DEFAULT 'panel',
  `transcript` mediumtext,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
