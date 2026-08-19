<?php

use Glpi\Plugin\Hooks;

// GLPI's plugin lifecycle manager may inspect the installation hooks while
// loading setup.php. Keep the lifecycle implementation in hook.php, but make
// those functions available from the plugin bootstrap as well. require_once
// avoids duplicate declarations when GLPI subsequently loads hook.php itself.
require_once __DIR__ . '/hook.php';

/**
 * GDMS Integration — setup.php
 * Author: Edwin Elias Alvarez
 * License: GPL v3+
 */

define('PLUGIN_GDMSINTEGRATION_VERSION', '1.6.1');
define('PLUGIN_GDMSINTEGRATION_MIN_GLPI',  '11.0');
define('PLUGIN_GDMSINTEGRATION_MAX_GLPI',  '12.99');

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
            'php'  => ['min' => '8.2'],
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

    // GLPI 11 exposes the legacy CSRF-compliance hook; GLPI 12 removed it.
    // Keep the declaration only for GLPI 11 so the plugin can initialize on both targets.
    if (version_compare(GLPI_VERSION, '12.0.0', '<')) {
        $PLUGIN_HOOKS['csrf_compliant']['gdmsintegration'] = true;
    }
    // Keep the plugin configuration link compatible with GLPI's plugin UI.
    // Plugin::getConfigPage() prefixes this value with /plugins/gdmsintegration/.
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['gdmsintegration'] = 'config';

    // plugin_init() runs before the user session is loaded. Class registration
    // must therefore never depend on Session state. Permission checks belong
    // to the menu provider and Controllers.
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['gdmsintegration'] = [
        'tools' => '\GlpiPlugin\Gdmsintegration\Menu',
    ];

    Plugin::registerClass(\GlpiPlugin\Gdmsintegration\Config::class);
    Plugin::registerClass(\GlpiPlugin\Gdmsintegration\Device::class, ['addtabon' => ['NetworkEquipment', 'Phone']]);
    Plugin::registerClass(\GlpiPlugin\Gdmsintegration\History::class);
    Plugin::registerClass(\GlpiPlugin\Gdmsintegration\Link::class);
}
