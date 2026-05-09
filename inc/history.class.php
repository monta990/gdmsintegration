<?php
/**
 * GDMS Integration — PluginGdmsintegrationHistory
 * Stores per-sync status snapshots used to calculate uptime %.
 */
class PluginGdmsintegrationHistory extends PluginGdmsintegrationBaseTM {

    // GLPI auto-pluralizes to 'histories' — override to match the actual table
    public static function getTable($classname = null): string {
        return 'glpi_plugin_gdmsintegration_history';
    }



    public static function getTypeName($nb = 0): string {
        return 'GDMS History';
    }
}
