<?php
/**
 * GDMS Integration — setup.php
 * Author: Edwin Elias Alvarez
 * License: GPL v3+
 */

define('PLUGIN_GDMSINTEGRATION_VERSION', '1.0.0');
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
// Init — hooks & class registration
// ---------------------------------------------------------------------------
function plugin_init_gdmsintegration(): void {
    global $PLUGIN_HOOKS;

    $PLUGIN_HOOKS['csrf_compliant']['gdmsintegration'] = true;
    $PLUGIN_HOOKS['config_page']['gdmsintegration']    = 'front/config.form.php';

    if (Session::getLoginUserID()) {
        $PLUGIN_HOOKS['menu_toadd']['gdmsintegration'] = [
            'plugins' => 'PluginGdmsintegrationMenu',
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
                `webhook_secret` varchar(255) NOT NULL DEFAULT '',
                `date_creation`  datetime DEFAULT NULL,
                `date_mod`       datetime DEFAULT NULL,
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
                `date`   datetime DEFAULT NULL,
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
                `mac`    varchar(50) NOT NULL DEFAULT '',
                `status` varchar(20) NOT NULL DEFAULT '',
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

    // Register cron task (idempotent)
    CronTask::register(
        'PluginGdmsintegrationSync',
        'syncDevices',
        HOUR_TIMESTAMP,
        [
            'comment' => 'Synchronize GDMS cloud devices with GLPI network equipment',
            'mode'    => CronTask::MODE_INTERNAL,
        ]
    );

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
