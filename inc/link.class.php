<?php
/**
 * GDMS Integration — PluginGdmsintegrationLink
 * Stores uplink topology edges between devices.
 */
class PluginGdmsintegrationLink extends PluginGdmsintegrationBaseTM {

    public static function getTable($classname = null): string {
        return 'glpi_plugin_gdmsintegration_links';
    }

    public static function getTypeName($nb = 0): string {
        return 'GDMS Link';
    }
}
