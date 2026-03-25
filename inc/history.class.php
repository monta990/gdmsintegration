<?php
/**
 * GDMS Integration — PluginGdmsintegrationHistory
 * Stores per-sync status snapshots used to calculate uptime %.
 */
class PluginGdmsintegrationHistory extends CommonDBTM {

    static $rightname = 'config';

    public static function getTypeName($nb = 0): string {
        return 'GDMS History';
    }
}
