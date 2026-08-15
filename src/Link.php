<?php

namespace GlpiPlugin\Gdmsintegration;
/**
 * GDMS Integration — \GlpiPlugin\Gdmsintegration\Link
 * Stores uplink topology edges between devices.
 */
class Link extends \GlpiPlugin\Gdmsintegration\BaseTM {

    public static function getTable($classname = null): string {
        return 'glpi_plugin_gdmsintegration_links';
    }

    public static function getTypeName($nb = 0): string {
        return 'GDMS Link';
    }
}
