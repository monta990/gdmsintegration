<?php

namespace GlpiPlugin\Gdmsintegration;
/**
 * GDMS Integration — \GlpiPlugin\Gdmsintegration\History
 * Stores per-sync status snapshots used to calculate uptime %.
 */
class History extends \GlpiPlugin\Gdmsintegration\BaseTM {

    // GLPI auto-pluralizes to 'histories' — override to match the actual table
    public static function getTable($classname = null): string {
        return 'glpi_plugin_gdmsintegration_history';
    }

    public static function getTypeName($nb = 0): string {
        return 'GDMS History';
    }
}
