<?php
/**
 * GDMS Integration — PluginGdmsintegrationLink
 * Stores uplink topology edges between devices.
 */
class PluginGdmsintegrationLink extends CommonDBTM {

    static $rightname = 'config';

    public static function getTypeName($nb = 0): string {
        return 'GDMS Link';
    }
}
