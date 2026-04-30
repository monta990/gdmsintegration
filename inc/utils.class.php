<?php
/**
 * GDMS Integration — PluginGdmsintegrationUtils
 *
 * Encryption uses GLPIKey (sodium-based, GLPI 11 standard).
 * Toolbox::encrypt/decrypt were removed in GLPI 11.
 *
 * Logging has two tiers:
 *   - MINIMAL  (default)  — only errors and key sync results (token OK, device count,
 *                            match/create/update per device, ticket events).
 *   - VERBOSE  (debug on) — full API URLs, request bodies, raw responses, signature
 *                            inputs, SN diagnostics. Enabled when "Debug logging" is
 *                            checked in plugin config OR when GLPI debug mode is active.
 */
class PluginGdmsintegrationUtils {

    /**
     * Returns true when verbose logging should be written.
     * Verbose when: plugin debug_logging = 1  OR  GLPI $_SESSION['glpi_use_mode'] = 2 (debug).
     */
    public static function isDebug(): bool {
        // GLPI debug mode
        if (isset($_SESSION['glpi_use_mode']) && (int)$_SESSION['glpi_use_mode'] === Session::DEBUG_MODE) {
            return true;
        }
        // Plugin-level debug toggle (cached in session for performance)
        if (!isset($_SESSION['_gdms_debug'])) {
            try {
                $cfg = new PluginGdmsintegrationConfig();
                $row = $cfg->getConfigByEntity(0);
                $_SESSION['_gdms_debug'] = !empty($row['debug_logging']) ? 1 : 0;
            } catch (\Throwable $e) {
                $_SESSION['_gdms_debug'] = 0;
            }
        }
        return (bool)$_SESSION['_gdms_debug'];
    }

    /**
     * Write a log entry.
     *
     * @param string $message  The message to log.
     * @param bool   $verbose  If true, only written when debug mode is active.
     *                         If false (default), always written.
     */
    public static function log(string $message, bool $verbose = false): void {
        if ($verbose && !self::isDebug()) {
            return;
        }
        Toolbox::logInFile('gdmsintegration', $message . PHP_EOL);
    }

    /**
     * Shorthand — write only in debug/verbose mode.
     */
    public static function debug(string $message): void {
        self::log($message, true);
    }

    public static function encrypt(string $value): string {
        return (new GLPIKey())->encrypt($value);
    }

    public static function decrypt(string $value): string {
        try {
            return (new GLPIKey())->decrypt($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }

    /**
     * Upgrade-safe schema guard — runs all ADD COLUMN IF NOT EXISTS statements.
     * Called from setup.php (install/upgrade) AND from dashboard.php (FTP-only deployments
     * that never trigger the GLPI plugin upgrade path).
     * Keeping both call sites in sync is the responsibility of this single function.
     */
    public static function ensureSchema(): void {
        global $DB;
        $alters = [
            // configs
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `debug_logging` tinyint unsigned NOT NULL DEFAULT 0",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `chart_days` smallint unsigned NOT NULL DEFAULT 60",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `show_topology` tinyint unsigned NOT NULL DEFAULT 1",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `ticket_requester_id` int unsigned NOT NULL DEFAULT 0",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `wan_debounce_seconds` smallint unsigned NOT NULL DEFAULT 300",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `wan_tickets_enabled` tinyint unsigned NOT NULL DEFAULT 1",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `tickets_phone`  tinyint unsigned NOT NULL DEFAULT 1",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `tickets_router` tinyint unsigned NOT NULL DEFAULT 1",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `tickets_switch` tinyint unsigned NOT NULL DEFAULT 1",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `tickets_ap`     tinyint unsigned NOT NULL DEFAULT 1",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `tickets_pbx`   tinyint unsigned NOT NULL DEFAULT 1",
            "ALTER TABLE `glpi_plugin_gdmsintegration_configs` ADD COLUMN IF NOT EXISTS `last_sync_at` TIMESTAMP NULL DEFAULT NULL",
            // devices
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `entities_id` int unsigned NOT NULL DEFAULT 0",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD INDEX IF NOT EXISTS `entities_id` (`entities_id`)",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `network_id` int unsigned NOT NULL DEFAULT 0",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `cloud_name` varchar(255) NOT NULL DEFAULT ''",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `clients` smallint unsigned NOT NULL DEFAULT 0",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `wan_ports_json` text NOT NULL DEFAULT ''",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `model` varchar(100) NOT NULL DEFAULT ''",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `usage_bytes` bigint unsigned NOT NULL DEFAULT 0",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `upload_bytes` bigint unsigned NOT NULL DEFAULT 0",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `download_bytes` bigint unsigned NOT NULL DEFAULT 0",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `channel_2g` tinyint unsigned NOT NULL DEFAULT 0",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `channel_5g` tinyint unsigned NOT NULL DEFAULT 0",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `first_seen` TIMESTAMP NULL DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `last_seen` TIMESTAMP NULL DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `mgmt_ip` varchar(50) NOT NULL DEFAULT ''",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `last_sync_at` TIMESTAMP NULL DEFAULT NULL",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `firmware_latest` varchar(50) NOT NULL DEFAULT ''",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `sip_status` varchar(50) NOT NULL DEFAULT ''",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `ipv6` varchar(60) NOT NULL DEFAULT ''",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `private_ip` varchar(45) NOT NULL DEFAULT ''",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `sip_extension` varchar(50) NOT NULL DEFAULT ''",
            "ALTER TABLE `glpi_plugin_gdmsintegration_devices` ADD COLUMN IF NOT EXISTS `location` varchar(255) NOT NULL DEFAULT ''",
        ];
        foreach ($alters as $sql) {
            try { $DB->doQuery($sql); } catch (\Throwable $e) { /* already exists */ }
        }
    }
}
