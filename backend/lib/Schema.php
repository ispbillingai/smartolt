<?php
// Brings a site's database up to the current layout. install.sql is version 1; every later change
// is a numbered step here, run once and recorded in `meta`, so sites installed earlier catch up on
// their next page load or 5 minute read.

class Schema
{
    const VERSION = 2;

    public static function ensure(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS `meta` (`k` varchar(50) NOT NULL, `v` varchar(255) NOT NULL, PRIMARY KEY (`k`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $have = (int)($db->query("SELECT v FROM meta WHERE k='schema_version'")->fetchColumn() ?: 1);
        if ($have >= self::VERSION) {
            return;
        }
        // Two requests may arrive together on the first load after an update.
        if ((int)$db->query("SELECT GET_LOCK(CONCAT(DATABASE(), '_schema'), 30)")->fetchColumn() !== 1) {
            return;
        }
        try {
            $have = (int)($db->query("SELECT v FROM meta WHERE k='schema_version'")->fetchColumn() ?: 1);
            for ($v = $have + 1; $v <= self::VERSION; $v++) {
                self::{'v' . $v}($db);
                $db->prepare("REPLACE INTO meta (k, v) VALUES ('schema_version', ?)")->execute([$v]);
            }
        } finally {
            $db->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '_schema'))");
        }
    }

    private static function addColumn(PDO $db, string $table, string $column, string $definition): void
    {
        $st = $db->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
        $st->execute([$table, $column]);
        if (!(int)$st->fetchColumn()) {
            $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    private static function addIndex(PDO $db, string $table, string $index, string $columns): void
    {
        $st = $db->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?');
        $st->execute([$table, $index]);
        if (!(int)$st->fetchColumn()) {
            $db->exec("ALTER TABLE `{$table}` ADD INDEX `{$index}` {$columns}");
        }
    }

    /** Zones, ODBs, ONU types, speed profiles, offline causes, signal and status history. */
    private static function v2(PDO $db): void
    {
        $db->exec("CREATE TABLE IF NOT EXISTS `zones` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ODB: the splitter box in the street that a customer's drop cable plugs into.
        $db->exec("CREATE TABLE IF NOT EXISTS `odbs` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `zone_id` int unsigned DEFAULT NULL,
            `name` varchar(100) NOT NULL,
            `ports` tinyint unsigned NOT NULL DEFAULT 16,
            `lat` decimal(10,7) DEFAULT NULL,
            `lng` decimal(10,7) DEFAULT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_name` (`name`),
            CONSTRAINT `fk_odbs_zone` FOREIGN KEY (`zone_id`) REFERENCES `zones` (`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS `onu_types` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(60) NOT NULL,
            `eth_ports` tinyint unsigned NOT NULL DEFAULT 1,
            `pots_ports` tinyint unsigned NOT NULL DEFAULT 0,
            `wifi` tinyint(1) NOT NULL DEFAULT 0,
            `catv` tinyint(1) NOT NULL DEFAULT 0,
            `default_mode` varchar(10) NOT NULL DEFAULT 'bridge',
            PRIMARY KEY (`id`), UNIQUE KEY `uq_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ins = $db->prepare('INSERT IGNORE INTO onu_types (name, eth_ports, pots_ports, wifi, catv, default_mode) VALUES (?,?,?,?,?,?)');
        foreach ([
            ['HG8010H', 1, 0, 0, 0, 'bridge'], ['HG8310M', 1, 0, 0, 0, 'bridge'], ['HG8546M', 4, 1, 1, 0, 'router'],
            ['HG8245H', 4, 2, 1, 0, 'router'], ['EG8145V5', 4, 1, 1, 0, 'router'], ['EG8141A5', 4, 1, 1, 0, 'router'],
            ['HG8247H', 4, 2, 1, 1, 'router'], ['F601', 1, 0, 0, 0, 'bridge'], ['F660', 4, 2, 1, 0, 'router'],
            ['F670L', 4, 1, 1, 0, 'router'], ['V2801RW', 1, 0, 1, 0, 'router'],
        ] as $t) {
            $ins->execute($t);
        }

        // Speeds in kbps. The OLT side (traffic tables) is created per OLT when a profile is first used there.
        $db->exec("CREATE TABLE IF NOT EXISTS `speed_profiles` (
            `id` int unsigned NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL,
            `down_kbps` int unsigned NOT NULL,
            `up_kbps` int unsigned NOT NULL,
            `created_at` datetime NOT NULL,
            PRIMARY KEY (`id`), UNIQUE KEY `uq_name` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Which traffic table on which OLT carries a given rate, so it is made once and reused.
        $db->exec("CREATE TABLE IF NOT EXISTS `olt_traffic_tables` (
            `olt_id` int unsigned NOT NULL,
            `kbps` int unsigned NOT NULL,
            `idx` smallint unsigned NOT NULL,
            PRIMARY KEY (`olt_id`, `kbps`),
            CONSTRAINT `fk_ott_olt` FOREIGN KEY (`olt_id`) REFERENCES `olts` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'zone_id'          => 'int unsigned DEFAULT NULL',
            'odb_id'           => 'int unsigned DEFAULT NULL',
            'odb_port'         => 'tinyint unsigned DEFAULT NULL',
            'onu_type_id'      => 'int unsigned DEFAULT NULL',
            'speed_profile_id' => 'int unsigned DEFAULT NULL',
            'mode'             => "varchar(10) NOT NULL DEFAULT ''",
            'gemport'          => 'smallint unsigned DEFAULT NULL',
            'address'          => "varchar(255) NOT NULL DEFAULT ''",
            'lat'              => 'decimal(10,7) DEFAULT NULL',
            'lng'              => 'decimal(10,7) DEFAULT NULL',
            'model'            => "varchar(60) NOT NULL DEFAULT ''",
            'distance_m'       => 'int DEFAULT NULL',
            'last_down_cause'  => "varchar(40) NOT NULL DEFAULT ''",
            // online, power_fail, los, other, disabled: what the lists filter and count on
            'status_group'     => "varchar(12) NOT NULL DEFAULT ''",
            'last_up_at'       => 'datetime DEFAULT NULL',
            'last_down_at'     => 'datetime DEFAULT NULL',
            'history_at'       => 'datetime DEFAULT NULL',
        ] as $col => $def) {
            self::addColumn($db, 'onus', $col, $def);
        }
        self::addIndex($db, 'onus', 'idx_zone', '(zone_id)');
        self::addIndex($db, 'onus', 'idx_odb', '(odb_id)');

        $db->exec("CREATE TABLE IF NOT EXISTS `signal_history` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `onu_id` int unsigned NOT NULL,
            `rx_power` decimal(6,2) DEFAULT NULL,
            `olt_rx_power` decimal(6,2) DEFAULT NULL,
            `at` datetime NOT NULL,
            PRIMARY KEY (`id`), KEY `idx_onu_time` (`onu_id`, `at`),
            CONSTRAINT `fk_sh_onu` FOREIGN KEY (`onu_id`) REFERENCES `onus` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Each time an ONU goes offline or comes back, with the cause the OLT gave.
        $db->exec("CREATE TABLE IF NOT EXISTS `status_events` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `onu_id` int unsigned NOT NULL,
            `state` varchar(20) NOT NULL,
            `cause` varchar(40) NOT NULL DEFAULT '',
            `at` datetime NOT NULL,
            PRIMARY KEY (`id`), KEY `idx_onu_time` (`onu_id`, `at`), KEY `idx_time` (`at`),
            CONSTRAINT `fk_se_onu` FOREIGN KEY (`onu_id`) REFERENCES `onus` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // One row per OLT per 5 minute read: the network status chart.
        $db->exec("CREATE TABLE IF NOT EXISTS `olt_stats` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `olt_id` int unsigned NOT NULL,
            `at` datetime NOT NULL,
            `total` int unsigned NOT NULL DEFAULT 0,
            `online` int unsigned NOT NULL DEFAULT 0,
            `los` int unsigned NOT NULL DEFAULT 0,
            `power_fail` int unsigned NOT NULL DEFAULT 0,
            `other_off` int unsigned NOT NULL DEFAULT 0,
            `warning` int unsigned NOT NULL DEFAULT 0,
            `critical` int unsigned NOT NULL DEFAULT 0,
            `waiting` int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`), KEY `idx_olt_time` (`olt_id`, `at`),
            CONSTRAINT `fk_os_olt` FOREIGN KEY (`olt_id`) REFERENCES `olts` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // The ONUs waiting for authorization as of the last read, so the dashboard can count them.
        $db->exec("CREATE TABLE IF NOT EXISTS `autofind_cache` (
            `olt_id` int unsigned NOT NULL,
            `onus_json` mediumtext,
            `at` datetime NOT NULL,
            PRIMARY KEY (`olt_id`),
            CONSTRAINT `fk_af_olt` FOREIGN KEY (`olt_id`) REFERENCES `olts` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        self::addColumn($db, 'olts', 'traffic_json', 'mediumtext');
    }
}
