<?php

use Glpi\Plugin\Hooks;

/**
 * GDMS Integration — setup.php
 * Author: Edwin Elias Alvarez
 * License: GPL v3+
 */

define('PLUGIN_GDMSINTEGRATION_VERSION', '1.1.0');
define('PLUGIN_GDMSINTEGRATION_MIN_GLPI',  '11.0');
define('PLUGIN_GDMSINTEGRATION_MAX_GLPI',  '11.99');

// ---------------------------------------------------------------------------
// Metadata
// ---------------------------------------------------------------------------
function plugin_version_gdmsintegration(): array {
    return [
        'name'         => 'GDMS Integration',
        'version'      => PLUGIN_GDMSINTEGRATION_VERSION,
        'author'       => 'Edwin Elias Alvarez',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://github.com/monta990/gdmsintegration',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_GDMSINTEGRATION_MIN_GLPI,
                'max' => PLUGIN_GDMSINTEGRATION_MAX_GLPI,
            ],
            'php'  => ['min' => '8.1'],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Prerequisites
// ---------------------------------------------------------------------------
function plugin_gdmsintegration_check_prerequisites(): bool {
    if (version_compare(GLPI_VERSION, PLUGIN_GDMSINTEGRATION_MIN_GLPI, '<')) {
        echo __('This plugin requires GLPI 11.0 or later.', 'gdmsintegration');
        return false;
    }
    if (!extension_loaded('curl')) {
        echo __('This plugin requires the PHP cURL extension.', 'gdmsintegration');
        return false;
    }
    return true;
}

function plugin_gdmsintegration_check_config(bool $verbose = false): bool {
    return true;
}

// ---------------------------------------------------------------------------
// Boot — runs before session is loaded, used for stateless path registration
// ---------------------------------------------------------------------------
function plugin_gdmsintegration_boot(): void {
    // webhook.php — called by GDMS Cloud servers, no browser session
    \Glpi\Http\SessionManager::registerPluginStatelessPath(
        'gdmsintegration',
        '#^/front/webhook\.php#'
    );
    // firmware.ajax.php: NOT stateless — uses session normally, CSRF via X-Glpi-Csrf-Token header
    // sync.ajax.php uses normal GLPI session (browser sends cookie automatically)
}

// ---------------------------------------------------------------------------
// Init — hooks & class registration
// ---------------------------------------------------------------------------
function plugin_init_gdmsintegration(): void {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['gdmsintegration'] = true;
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['gdmsintegration']    = 'front/config.form.php';

    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS[Hooks::MENU_TOADD]['gdmsintegration'] = [
            'tools' => 'PluginGdmsintegrationMenu',
        ];

        Plugin::registerClass('PluginGdmsintegrationConfig');
        Plugin::registerClass('PluginGdmsintegrationDevice');
        Plugin::registerClass('PluginGdmsintegrationHistory');
        Plugin::registerClass('PluginGdmsintegrationLink');
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

    if (!$DB->tableExists('glpi_plugin_gdmsintegration_configs')) {
        $DB->doQueryOrDie(
            "CREATE TABLE `glpi_plugin_gdmsintegration_configs` (
                `id`             int {$sign} NOT NULL AUTO_INCREMENT,
                `entities_id`    int {$sign} NOT NULL DEFAULT '0',
                `username`       varchar(255) NOT NULL DEFAULT '',
                `password`       text,
                `client_id`      varchar(255) NOT NULL DEFAULT '',
                `client_secret`  text,
                `gwn_client_id`  varchar(255) NOT NULL DEFAULT '',
                `gwn_client_secret` text,
                `webhook_secret`    varchar(255) NOT NULL DEFAULT '',
                `refresh_interval`  int unsigned NOT NULL DEFAULT 300,
                `debug_logging`     tinyint unsigned NOT NULL DEFAULT 0,
                `date_creation`  TIMESTAMP NULL DEFAULT NULL,
                `date_mod`       TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `entities_id` (`entities_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
        );
    }

    if (!$DB->tableExists('glpi_plugin_gdmsintegration_history')) {
        $DB->doQueryOrDie(
            "CREATE TABLE `glpi_plugin_gdmsintegration_history` (
                `id`     int {$sign} NOT NULL AUTO_INCREMENT,
                `mac`    varchar(50) NOT NULL DEFAULT '',
                `status` varchar(20) NOT NULL DEFAULT '',
                `date`   TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `mac`  (`mac`),
                KEY `date` (`date`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
        );
    }

    if (!$DB->tableExists('glpi_plugin_gdmsintegration_devices')) {
        $DB->doQueryOrDie(
            "CREATE TABLE `glpi_plugin_gdmsintegration_devices` (
                `id`     int {$sign} NOT NULL AUTO_INCREMENT,
                `mac`          varchar(50) NOT NULL DEFAULT '',
                `status`       varchar(20) NOT NULL DEFAULT '',
                `network_name` varchar(255) NOT NULL DEFAULT '',
                `network_id`   int unsigned NOT NULL DEFAULT 0,
                `ip`           varchar(50) NOT NULL DEFAULT '',
                `firmware`     varchar(50) NOT NULL DEFAULT '',
                `uptime_sec`   bigint unsigned NOT NULL DEFAULT 0,
                `sn_cloud`     varchar(100) NOT NULL DEFAULT '',
                `wan_ports_json` text NOT NULL DEFAULT '',
                `model`         varchar(100) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `mac` (`mac`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
        );
    }

    if (!$DB->tableExists('glpi_plugin_gdmsintegration_links')) {
        $DB->doQueryOrDie(
            "CREATE TABLE `glpi_plugin_gdmsintegration_links` (
                `id`         int {$sign} NOT NULL AUTO_INCREMENT,
                `source_mac` varchar(50) NOT NULL DEFAULT '',
                `target_mac` varchar(50) NOT NULL DEFAULT '',
                `type`       varchar(20) NOT NULL DEFAULT '',
                PRIMARY KEY (`id`),
                UNIQUE KEY `source_target` (`source_mac`, `target_mac`)
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$collation}"
        );
    }

    // Clean up any orphaned crontask from previous installs, then re-register
    CronTask::unregister('gdmsintegration');
    CronTask::register(
        'PluginGdmsintegrationSync',
        'syncDevices',
        30 * MINUTE_TIMESTAMP,
        [
            'comment' => 'Synchronize GDMS/GWN cloud devices with GLPI',
            'mode'    => CronTask::MODE_EXTERNAL,
            'state'   => CronTask::STATE_WAITING,
        ]
    );
    // Force-update existing crontask — only frequency and mode
    // log_interval/logs_days column name varies between GLPI versions; skip it here
    $DB->update(
        CronTask::getTable(),
        [
            'frequency' => 30 * MINUTE_TIMESTAMP,
            'mode'      => CronTask::MODE_EXTERNAL,
            'state'     => CronTask::STATE_WAITING,
        ],
        [
            'itemtype' => 'PluginGdmsintegrationSync',
            'name'     => 'syncDevices',
        ]
    );
    // Note: log retention is managed by GLPI's own cron interface (Setup → Automatic Actions)

    // Add columns that may be missing in existing installs (upgrade-safe)
    foreach ([
        "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `debug_logging` tinyint unsigned NOT NULL DEFAULT 0",
        "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `chart_days` smallint unsigned NOT NULL DEFAULT 60",
        "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `show_topology` tinyint unsigned NOT NULL DEFAULT 1",
        "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `ticket_requester_id` int unsigned NOT NULL DEFAULT 0",
        "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `network_id` int unsigned NOT NULL DEFAULT 0",
        "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `cloud_name` varchar(255) NOT NULL DEFAULT ''",
        "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `clients` smallint unsigned NOT NULL DEFAULT 0",
        "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `wan_ports_json` text NOT NULL DEFAULT ''",
        "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `model` varchar(100) NOT NULL DEFAULT ''",
    ] as $alter) {
        try { $DB->doQuery($alter); } catch (\Throwable $e) { /* column already exists */ }
    }

    return true;
}

// ---------------------------------------------------------------------------
// Uninstall
// ---------------------------------------------------------------------------
function plugin_gdmsintegration_uninstall(): bool {
    global $DB;

    foreach ([
        'glpi_plugin_gdmsintegration_configs',
        'glpi_plugin_gdmsintegration_history',
        'glpi_plugin_gdmsintegration_devices',
        'glpi_plugin_gdmsintegration_links',
    ] as $table) {
        if ($DB->tableExists($table)) {
            $DB->doQueryOrDie("DROP TABLE `{$table}`");
        }
    }

    CronTask::unregister('gdmsintegration');
    return true;
}
