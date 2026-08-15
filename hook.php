<?php

/**
 * GDMS Integration — installation and uninstallation hooks.
 *
 * Database creation, schema migrations and plugin-owned scheduled tasks are
 * deliberately kept here, as required by GLPI's plugin lifecycle. Runtime
 * hooks and class registration remain in setup.php.
 */

// ---------------------------------------------------------------------------
// Install
// ---------------------------------------------------------------------------
function plugin_gdmsintegration_install(): bool {
    global $DB;

    $charset   = DBConnection::getDefaultCharset();
    $collation = DBConnection::getDefaultCollation();
    $sign      = DBConnection::getDefaultPrimaryKeySignOption();
    $migration = new Migration(PLUGIN_GDMSINTEGRATION_VERSION);

    // Create the plugin-owned tables in their current complete form. Existing
    // installations are upgraded below through Migration, never by ALTER TABLE
    // calls from normal requests.
    $tables = [
        'glpi_plugin_gdmsintegration_configs' => "CREATE TABLE `glpi_plugin_gdmsintegration_configs` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` int {$sign} NOT NULL DEFAULT '0',
            `username` varchar(255) NOT NULL DEFAULT '',
            `password` text,
            `client_id` varchar(255) NOT NULL DEFAULT '',
            `client_secret` text,
            `gwn_client_id` varchar(255) NOT NULL DEFAULT '',
            `gwn_client_secret` text,
            `gdms_region` varchar(8) NOT NULL DEFAULT 'us',
            `gdms_access_token` text,
            `gdms_refresh_token` text,
            `gdms_token_expires_at` int unsigned NOT NULL DEFAULT 0,
            `gwn_access_token` text,
            `gwn_token_expires_at` int unsigned NOT NULL DEFAULT 0,
            `refresh_interval` int unsigned NOT NULL DEFAULT 300,
            `ip_version` varchar(4) NOT NULL DEFAULT 'ipv4',
            `debug_logging` tinyint unsigned NOT NULL DEFAULT 0,
            `chart_days` smallint unsigned NOT NULL DEFAULT 60,
            `show_topology` tinyint unsigned NOT NULL DEFAULT 1,
            `ticket_requester_id` int unsigned NOT NULL DEFAULT 0,
            `wan_debounce_seconds` smallint unsigned NOT NULL DEFAULT 300,
            `wan_tickets_enabled` tinyint unsigned NOT NULL DEFAULT 1,
            `tickets_phone` tinyint unsigned NOT NULL DEFAULT 1,
            `tickets_router` tinyint unsigned NOT NULL DEFAULT 1,
            `tickets_switch` tinyint unsigned NOT NULL DEFAULT 1,
            `tickets_ap` tinyint unsigned NOT NULL DEFAULT 1,
            `tickets_pbx` tinyint unsigned NOT NULL DEFAULT 1,
            `ticket_category_network_id` int unsigned NOT NULL DEFAULT 0,
            `ticket_category_telephony_id` int unsigned NOT NULL DEFAULT 0,
            `last_sync_at` TIMESTAMP NULL DEFAULT NULL,
            `max_xlsx_size_mb` tinyint unsigned NOT NULL DEFAULT 5,
            `history_retention_days` smallint unsigned NOT NULL DEFAULT 90,
            `last_sync_duration_ms` int unsigned NOT NULL DEFAULT 0,
            `last_sync_devices` int unsigned NOT NULL DEFAULT 0,
            `last_sync_status` varchar(20) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_gdmsintegration_history' => "CREATE TABLE `glpi_plugin_gdmsintegration_history` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `mac` varchar(50) NOT NULL DEFAULT '',
            `status` varchar(20) NOT NULL DEFAULT '',
            `date` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `mac` (`mac`),
            KEY `date` (`date`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_gdmsintegration_devices' => "CREATE TABLE `glpi_plugin_gdmsintegration_devices` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `mac` varchar(50) NOT NULL DEFAULT '',
            `status` varchar(20) NOT NULL DEFAULT '',
            `network_name` varchar(255) NOT NULL DEFAULT '',
            `network_id` int unsigned NOT NULL DEFAULT 0,
            `cloud_name` varchar(255) NOT NULL DEFAULT '',
            `ip` varchar(50) NOT NULL DEFAULT '',
            `mgmt_ip` varchar(50) NOT NULL DEFAULT '',
            `ipv6` varchar(60) NOT NULL DEFAULT '',
            `private_ip` varchar(45) NOT NULL DEFAULT '',
            `firmware` varchar(50) NOT NULL DEFAULT '',
            `firmware_latest` varchar(50) NOT NULL DEFAULT '',
            `uptime_sec` bigint unsigned NOT NULL DEFAULT 0,
            `sn_cloud` varchar(100) NOT NULL DEFAULT '',
            `wan_ports_json` text NOT NULL DEFAULT '',
            `model` varchar(100) NOT NULL DEFAULT '',
            `clients` smallint unsigned NOT NULL DEFAULT 0,
            `usage_bytes` bigint unsigned NOT NULL DEFAULT 0,
            `upload_bytes` bigint unsigned NOT NULL DEFAULT 0,
            `download_bytes` bigint unsigned NOT NULL DEFAULT 0,
            `channel_2g` tinyint unsigned NOT NULL DEFAULT 0,
            `channel_5g` tinyint unsigned NOT NULL DEFAULT 0,
            `first_seen` TIMESTAMP NULL DEFAULT NULL,
            `last_seen` TIMESTAMP NULL DEFAULT NULL,
            `last_sync_at` TIMESTAMP NULL DEFAULT NULL,
            `last_reboot_at` TIMESTAMP NULL DEFAULT NULL,
            `last_factory_reset_at` TIMESTAMP NULL DEFAULT NULL,
            `sip_status` varchar(50) NOT NULL DEFAULT '',
            `sip_extension` varchar(50) NOT NULL DEFAULT '',
            `location` varchar(255) NOT NULL DEFAULT '',
            `dnd` tinyint unsigned NOT NULL DEFAULT 0,
            `is_synchronized` tinyint unsigned NOT NULL DEFAULT 0,
            `sync_failure_msg` varchar(255) NOT NULL DEFAULT '',
            `scheduled_task` tinyint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `mac` (`mac`),
            KEY `entities_id` (`entities_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_gdmsintegration_links' => "CREATE TABLE `glpi_plugin_gdmsintegration_links` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `source_mac` varchar(50) NOT NULL DEFAULT '',
            `target_mac` varchar(50) NOT NULL DEFAULT '',
            `type` varchar(20) NOT NULL DEFAULT '',
            PRIMARY KEY (`id`),
            UNIQUE KEY `source_target` (`source_mac`, `target_mac`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_gdmsintegration_client_history' => "CREATE TABLE `glpi_plugin_gdmsintegration_client_history` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `client_mac` varchar(50) NOT NULL DEFAULT '',
            `ap_mac` varchar(50) NOT NULL DEFAULT '',
            `network_id` int unsigned NOT NULL DEFAULT 0,
            `hostname` varchar(255) NOT NULL DEFAULT '',
            `ip` varchar(50) NOT NULL DEFAULT '',
            `ssid` varchar(255) NOT NULL DEFAULT '',
            `rssi` smallint NOT NULL DEFAULT 0,
            `seen_at` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `client_seen` (`client_mac`,`seen_at`),
            KEY `entity_seen` (`entities_id`,`seen_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_gdmsintegration_action_log' => "CREATE TABLE `glpi_plugin_gdmsintegration_action_log` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `users_id` int unsigned NOT NULL DEFAULT 0,
            `action` varchar(50) NOT NULL DEFAULT '',
            `target_mac` varchar(50) NOT NULL DEFAULT '',
            `details` text NULL,
            `success` tinyint unsigned NOT NULL DEFAULT 0,
            `date` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entity_date` (`entities_id`,`date`),
            KEY `target_mac` (`target_mac`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_gdmsintegration_locks' => "CREATE TABLE `glpi_plugin_gdmsintegration_locks` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `lock_name` varchar(190) NOT NULL DEFAULT '',
            `lock_token` char(32) NOT NULL DEFAULT '',
            `acquired_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `lock_name` (`lock_name`),
            KEY `acquired_at` (`acquired_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
        'glpi_plugin_gdmsintegration_network_locations' => "CREATE TABLE `glpi_plugin_gdmsintegration_network_locations` (
            `id` int {$sign} NOT NULL AUTO_INCREMENT,
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `network_id` int unsigned NOT NULL DEFAULT 0,
            `network_name` varchar(255) NOT NULL DEFAULT '',
            `locations_id` int unsigned NOT NULL DEFAULT 0,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `entity_network` (`entities_id`,`network_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}",
    ];

    foreach ($tables as $table => $query) {
        if (!$DB->tableExists($table)) {
            $DB->doQuery($query);
        }
    }

    // Upgrade existing installations through GLPI's Migration API. This is
    // intentionally executed only from the install/update hook, never from a
    // dashboard or AJAX request.
    $configFields = [
        'gdms_region' => ['varchar(8) NOT NULL DEFAULT \'us\''],
        'gdms_access_token' => ['text NULL'],
        'gdms_refresh_token' => ['text NULL'],
        'gdms_token_expires_at' => ['int unsigned NOT NULL DEFAULT 0'],
        'gwn_access_token' => ['text NULL'],
        'gwn_token_expires_at' => ['int unsigned NOT NULL DEFAULT 0'],
        'ip_version' => ['varchar(4) NOT NULL DEFAULT \'ipv4\''],
        'debug_logging' => ['tinyint unsigned NOT NULL DEFAULT 0'],
        'chart_days' => ['smallint unsigned NOT NULL DEFAULT 60'],
        'show_topology' => ['tinyint unsigned NOT NULL DEFAULT 1'],
        'ticket_requester_id' => ['int unsigned NOT NULL DEFAULT 0'],
        'wan_debounce_seconds' => ['smallint unsigned NOT NULL DEFAULT 300'],
        'wan_tickets_enabled' => ['tinyint unsigned NOT NULL DEFAULT 1'],
        'tickets_phone' => ['tinyint unsigned NOT NULL DEFAULT 1'],
        'tickets_router' => ['tinyint unsigned NOT NULL DEFAULT 1'],
        'tickets_switch' => ['tinyint unsigned NOT NULL DEFAULT 1'],
        'tickets_ap' => ['tinyint unsigned NOT NULL DEFAULT 1'],
        'tickets_pbx' => ['tinyint unsigned NOT NULL DEFAULT 1'],
        'ticket_category_network_id' => ['int unsigned NOT NULL DEFAULT 0'],
        'ticket_category_telephony_id' => ['int unsigned NOT NULL DEFAULT 0'],
        'last_sync_at' => ['TIMESTAMP NULL DEFAULT NULL'],
        'max_xlsx_size_mb' => ['tinyint unsigned NOT NULL DEFAULT 5'],
        'history_retention_days' => ['smallint unsigned NOT NULL DEFAULT 90'],
        'last_sync_duration_ms' => ['int unsigned NOT NULL DEFAULT 0'],
        'last_sync_devices' => ['int unsigned NOT NULL DEFAULT 0'],
        'last_sync_status' => ['varchar(20) NOT NULL DEFAULT \'\''],
    ];
    foreach ($configFields as $field => [$type]) {
        if (!$DB->fieldExists('glpi_plugin_gdmsintegration_configs', $field)) {
            $migration->addField('glpi_plugin_gdmsintegration_configs', $field, $type);
        }
    }
    if ($DB->fieldExists('glpi_plugin_gdmsintegration_configs', 'webhook_secret')) {
        $migration->dropField('glpi_plugin_gdmsintegration_configs', 'webhook_secret');
    }

    $deviceFields = [
        'entities_id' => 'int unsigned NOT NULL DEFAULT 0',
        'network_id' => 'int unsigned NOT NULL DEFAULT 0',
        'cloud_name' => 'varchar(255) NOT NULL DEFAULT \'\'',
        'clients' => 'smallint unsigned NOT NULL DEFAULT 0',
        'wan_ports_json' => 'text NOT NULL DEFAULT \'\'',
        'model' => 'varchar(100) NOT NULL DEFAULT \'\'',
        'usage_bytes' => 'bigint unsigned NOT NULL DEFAULT 0',
        'upload_bytes' => 'bigint unsigned NOT NULL DEFAULT 0',
        'download_bytes' => 'bigint unsigned NOT NULL DEFAULT 0',
        'channel_2g' => 'tinyint unsigned NOT NULL DEFAULT 0',
        'channel_5g' => 'tinyint unsigned NOT NULL DEFAULT 0',
        'first_seen' => 'TIMESTAMP NULL DEFAULT NULL',
        'last_seen' => 'TIMESTAMP NULL DEFAULT NULL',
        'mgmt_ip' => 'varchar(50) NOT NULL DEFAULT \'\'',
        'last_sync_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'last_reboot_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'last_factory_reset_at' => 'TIMESTAMP NULL DEFAULT NULL',
        'firmware_latest' => 'varchar(50) NOT NULL DEFAULT \'\'',
        'sip_status' => 'varchar(50) NOT NULL DEFAULT \'\'',
        'ipv6' => 'varchar(60) NOT NULL DEFAULT \'\'',
        'private_ip' => 'varchar(45) NOT NULL DEFAULT \'\'',
        'sip_extension' => 'varchar(50) NOT NULL DEFAULT \'\'',
        'location' => 'varchar(255) NOT NULL DEFAULT \'\'',
        'dnd' => 'tinyint unsigned NOT NULL DEFAULT 0',
        'is_synchronized' => 'tinyint unsigned NOT NULL DEFAULT 0',
        'sync_failure_msg' => 'varchar(255) NOT NULL DEFAULT \'\'',
        'scheduled_task' => 'tinyint unsigned NOT NULL DEFAULT 0',
    ];
    foreach ($deviceFields as $field => $type) {
        if (!$DB->fieldExists('glpi_plugin_gdmsintegration_devices', $field)) {
            $migration->addField('glpi_plugin_gdmsintegration_devices', $field, $type);
        }
    }
    if (!$DB->fieldExists('glpi_plugin_gdmsintegration_devices', 'entities_id') || !isIndex('glpi_plugin_gdmsintegration_devices', 'entities_id')) {
        $migration->addKey('glpi_plugin_gdmsintegration_devices', 'entities_id');
    }

    $migration->executeMigration();

    // Remove the obsolete task name shipped by an earlier 1.6.0 build, then
    // register the task using GLPI's documented CronTask API. No direct writes
    // to GLPI's core cron table are required.
    CronTask::unregister('gdmsintegration');
    CronTask::register(
        '\GlpiPlugin\Gdmsintegration\Sync',
        'SyncDevices',
        10 * MINUTE_TIMESTAMP,
        [
            'comment' => 'Synchronize GDMS/GWN cloud devices with GLPI',
            'mode'    => CronTask::MODE_EXTERNAL,
        ]
    );

    return true;
}

// ---------------------------------------------------------------------------
// Uninstall
// ---------------------------------------------------------------------------
function plugin_gdmsintegration_uninstall(): bool {
    global $DB;

    $migration = new Migration(PLUGIN_GDMSINTEGRATION_VERSION);
    foreach ([
        'glpi_plugin_gdmsintegration_configs',
        'glpi_plugin_gdmsintegration_history',
        'glpi_plugin_gdmsintegration_devices',
        'glpi_plugin_gdmsintegration_links',
        'glpi_plugin_gdmsintegration_client_history',
        'glpi_plugin_gdmsintegration_action_log',
        'glpi_plugin_gdmsintegration_network_locations',
        'glpi_plugin_gdmsintegration_locks',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $migration->dropTable($table);
        }
    }
    $migration->executeMigration();

    // CronTask entries are owned and removed by GLPI when the plugin is uninstalled.
    return true;
}
