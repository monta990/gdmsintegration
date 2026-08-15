<?php

namespace GlpiPlugin\Gdmsintegration;
/**
 * GDMS Integration — \GlpiPlugin\Gdmsintegration\Utils
 *
 * Encryption uses \GLPIKey (sodium-based, GLPI 11 standard).
 * \Toolbox::encrypt/decrypt were removed in GLPI 11.
 *
 * Logging has two tiers:
 *   - MINIMAL  (default)  — only errors and key sync results (token OK, device count,
 *                            match/create/update per device, ticket events).
 *   - VERBOSE  (debug on) — full API URLs, request bodies, raw responses, signature
 *                            inputs, SN diagnostics. Enabled when "Debug logging" is
 *                            checked in plugin config OR when GLPI debug mode is active.
 */
class Utils {

    /**
     * Returns true when verbose logging should be written.
     * Verbose when: plugin debug_logging = 1  OR  GLPI $_SESSION['glpi_use_mode'] = 2 (debug).
     */
    public static function isDebug(): bool {
        // GLPI debug mode
        if (isset($_SESSION['glpi_use_mode']) && (int)$_SESSION['glpi_use_mode'] === \Session::DEBUG_MODE) {
            return true;
        }
        // Plugin-level debug toggle (cached in session for performance)
        if (!isset($_SESSION['_gdms_debug'])) {
            try {
                $cfg = new \GlpiPlugin\Gdmsintegration\Config();
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
        // Redact tokens from URLs before writing to disk
        $safe = preg_replace('/([?&](?:access_token|refresh_token|token|Authorization|client_secret|secretKey|password)=)[^&\s"\']+/i', '$1[REDACTED]', $message);
        \Toolbox::logInFile('gdmsintegration', ($safe ?? $message) . PHP_EOL);
    }

    /**
     * Shorthand — write only in debug/verbose mode.
     */
    public static function debug(string $message): void {
        self::log($message, true);
    }

    public static function encrypt(string $value): string {
        return (new \GLPIKey())->encrypt($value);
    }

    public static function decrypt(string $value): string {
        try {
            return (new \GLPIKey())->decrypt($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }
    /** Structured severity helpers. Debug remains opt-in; warnings are always retained. */
    public static function warning(string $message): void { self::log('[WARNING] ' . $message); }

    /** Remove operational rows older than the configured retention window. */
    public static function cleanupOperationalHistory(int $entities_id, int $days): void {
        global $DB;
        $days = max(7, min(3650, $days));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        foreach (['glpi_plugin_gdmsintegration_client_history' => 'seen_at', 'glpi_plugin_gdmsintegration_action_log' => 'date'] as $table => $field) {
            try { $DB->delete($table, ['entities_id' => $entities_id, [$field, '<'] => $cutoff]); } catch (\Throwable $e) { self::debug("Retention cleanup skipped for {$table}: " . $e->getMessage()); }
        }
    }


    /**
     * Acquire a short-lived application lock using GLPI's DB abstraction.
     * Returns a token that must be passed to releaseLock().
     */
    public static function acquireLock(string $name, int $ttl = 900): ?string {
        global $DB;
        $name = substr($name, 0, 190);
        $token = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $stale = date('Y-m-d H:i:s', time() - max(30, $ttl));
        $table = 'glpi_plugin_gdmsintegration_locks';

        try {
            $DB->delete($table, [
                'lock_name' => $name,
                'acquired_at' => ['<', $stale],
            ]);
            $DB->insert($table, [
                'lock_name' => $name,
                'lock_token' => $token,
                'acquired_at' => $now,
            ]);
            return $DB->affectedRows() > 0 ? $token : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function releaseLock(string $name, string $token): void {
        global $DB;
        try {
            $DB->delete('glpi_plugin_gdmsintegration_locks', [
                'lock_name' => substr($name, 0, 190),
                'lock_token' => $token,
            ]);
        } catch (\Throwable $e) {
            self::debug('Lock release skipped: ' . $e->getMessage());
        }
    }
}
