<?php

/**
 * GDMS Integration — installation and uninstallation hooks.
 *
 * Database creation, schema migrations and plugin-owned scheduled tasks are
 * deliberately kept here, as required by GLPI's plugin lifecycle. Runtime
 * hooks and class registration remain in setup.php.
 */

/**
 * Ensure GDMS's native GLPI automatic action is registered correctly.
 *
 * $force is used during installation to refresh mode/frequency. At runtime the
 * fast path only checks the canonical task, allowing a replaced 1.6.6 candidate
 * to self-heal its malformed leading-slash row without requiring a reinstall.
 */
function plugin_gdmsintegration_ensure_cron_task(bool $force = false): bool {
    global $DB;

    $itemtype = \GlpiPlugin\Gdmsintegration\Sync::class;
    if (!$force) {
        $existingTask = new CronTask();
        if ($existingTask->getFromDBbyName($itemtype, 'SyncDevices')) {
            return true;
        }
    }

    // Avoid duplicate repair/registration when several web requests start just
    // after an in-place plugin update. This lock is taken only when repair is needed.
    $repairLock = 'gdmsintegration.cron.repair';
    if (!$DB->getLock($repairLock)) {
        // Another process is repairing the task. A later request will verify it.
        return !$force;
    }

    try {
        // Recheck after acquiring the lock in case another request completed repair.
        if (!$force) {
            $existingTask = new CronTask();
            if ($existingTask->getFromDBbyName($itemtype, 'SyncDevices')) {
                return true;
            }
        }

        // Remove well-formed plugin-owned task entries with GLPI's native API.
        CronTask::unregister('gdmsintegration');

        // The prior 1.6.6 candidate stored a leading slash in itemtype. GLPI's
        // isPluginItemType() and unregister() do not recognize that malformed row.
        $legacyItemtype = '\\GlpiPlugin\\Gdmsintegration\\Sync';
        $legacyCronTask = new CronTask();
        foreach ($DB->request([
            'FROM'  => CronTask::getTable(),
            'WHERE' => [
                'itemtype' => $legacyItemtype,
                'name'     => ['IN', ['SyncDevices', 'syncDevices']],
            ],
        ]) as $legacyTaskRow) {
            if (!$legacyCronTask->delete($legacyTaskRow)) {
                \GlpiPlugin\Gdmsintegration\Utils::warning(
                    'Could not remove legacy malformed SyncDevices cron task (id=' . (int)($legacyTaskRow['id'] ?? 0) . ').'
                );
            }
        }

        // The canonical ::class value has no leading slash and is recognized by
        // GLPI 11/12 plugin-itemtype detection and disabled-plugin checks.
        $registered = CronTask::register(
            $itemtype,
            'SyncDevices',
            10 * MINUTE_TIMESTAMP,
            [
                'comment' => 'Synchronize GDMS/GWN cloud devices with GLPI',
                'mode'    => CronTask::MODE_EXTERNAL,
            ]
        );
        if (!$registered) {
            // If a competing request won registration, treat the now-existing row as success.
            $existingTask = new CronTask();
            if (!$force && $existingTask->getFromDBbyName($itemtype, 'SyncDevices')) {
                return true;
            }
            \GlpiPlugin\Gdmsintegration\Utils::warning(
                'Could not register the SyncDevices automatic action. Check GLPI CronTask configuration and logs.'
            );
            return false;
        }

        return true;
    } finally {
        $DB->releaseLock($repairLock);
    }
}

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
            `ticket_requesttype_id` int unsigned NOT NULL DEFAULT 0,
            `ticket_requesttype_default_id` int unsigned NOT NULL DEFAULT 0,
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
            `entities_id` int unsigned NOT NULL DEFAULT 0,
            `mac` varchar(50) NOT NULL DEFAULT '',
            `status` varchar(20) NOT NULL DEFAULT '',
            `date` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `mac` (`mac`),
            KEY `date` (`date`),
            KEY `entity_date` (`entities_id`,`date`)
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
            UNIQUE KEY `entity_mac` (`entities_id`, `mac`),
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
        'ticket_requesttype_id' => ['int unsigned NOT NULL DEFAULT 0'],
        'ticket_requesttype_default_id' => ['int unsigned NOT NULL DEFAULT 0'],
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

    // History became entity-scoped in 1.6.3. Existing rows are backfilled
    // from the current device-state entity where the MAC still exists. Rows
    // that cannot be mapped safely remain in entity 0 and are excluded from
    // non-global entity views.
    if ($DB->tableExists('glpi_plugin_gdmsintegration_history')) {
        if (!$DB->fieldExists('glpi_plugin_gdmsintegration_history', 'entities_id')) {
            $migration->addField('glpi_plugin_gdmsintegration_history', 'entities_id', 'int unsigned NOT NULL DEFAULT 0');
        }
        if (!isIndex('glpi_plugin_gdmsintegration_history', 'entity_date')) {
            // Migration::addKey() receives the indexed field(s) separately from
            // the index name. Passing 'entity_date' as the field name generated
            // invalid SQL because there is no column named entity_date.
            $migration->addKey(
                'glpi_plugin_gdmsintegration_history',
                ['entities_id', 'date'],
                'entity_date'
            );
        }
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

    // Device state is per entity. Older releases used UNIQUE(mac), which prevented
    // the same cloud device from being represented independently in two entities.
    // Drop the legacy index and create the composite unique key idempotently.
    if ($DB->tableExists('glpi_plugin_gdmsintegration_devices')) {
        if (isIndex('glpi_plugin_gdmsintegration_devices', 'mac')) {
            $migration->dropKey('glpi_plugin_gdmsintegration_devices', 'mac');
        }
        if (!isIndex('glpi_plugin_gdmsintegration_devices', 'entity_mac')) {
            $migration->addKey(
                'glpi_plugin_gdmsintegration_devices',
                ['entities_id', 'mac'],
                'entity_mac',
                'UNIQUE'
            );
        }
    }

    $migration->executeMigration();

    // Best-effort backfill of legacy history rows to the current device entity.
    // This runs only during install/update, never during normal dashboard requests.
    if ($DB->tableExists('glpi_plugin_gdmsintegration_history') && $DB->tableExists('glpi_plugin_gdmsintegration_devices')) {
        try {
            $entity_by_mac = [];
            $ambiguous_macs = [];
            foreach ($DB->request([
                'SELECT' => ['mac', 'entities_id'],
                'FROM'   => 'glpi_plugin_gdmsintegration_devices',
                'WHERE'  => ['entities_id' => ['>', 0]],
            ]) as $device_row) {
                $device_mac = strtolower(trim((string)($device_row['mac'] ?? '')));
                $device_entity = (int)($device_row['entities_id'] ?? 0);
                if ($device_mac === '' || $device_entity <= 0) {
                    continue;
                }
                if (isset($entity_by_mac[$device_mac]) && $entity_by_mac[$device_mac] !== $device_entity) {
                    $ambiguous_macs[$device_mac] = true;
                    unset($entity_by_mac[$device_mac]);
                    continue;
                }
                if (!isset($ambiguous_macs[$device_mac])) {
                    $entity_by_mac[$device_mac] = $device_entity;
                }
            }
            foreach ($entity_by_mac as $device_mac => $device_entity) {
                $DB->update('glpi_plugin_gdmsintegration_history',
                    ['entities_id' => $device_entity],
                    ['mac' => $device_mac, 'entities_id' => 0]
                );
            }
        } catch (\Throwable $e) {
            \GlpiPlugin\Gdmsintegration\Utils::debug('Legacy history entity backfill skipped: ' . $e->getMessage());
        }
    }

    // Register/reuse the native GLPI request source and initialize defaults.
    // The request source is deliberately not removed during uninstall so
    // historical tickets keep a valid source value.
    \GlpiPlugin\Gdmsintegration\Config::ensureRequestTypeConfiguration();

    // Install hook also repairs the native task; plugin_init can self-heal an
    // existing installation when a candidate ZIP replaces files in place.
    if (!plugin_gdmsintegration_ensure_cron_task(true)) {
        return false;
    }

    return true;
}

/** Ensure the native automatic action is repaired whenever the plugin is enabled. */
function plugin_gdmsintegration_activate(): bool {
    return plugin_gdmsintegration_ensure_cron_task();
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
