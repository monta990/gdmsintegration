<?php

namespace GlpiPlugin\Gdmsintegration;

use Glpi\RichText\RichText;

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
class Utils
{
    /**
     * Returns true when verbose logging should be written.
     * Verbose when: plugin debug_logging = 1  OR  GLPI $_SESSION['glpi_use_mode'] = 2 (debug).
     */
    public static function isDebug(): bool
    {
        // GLPI debug mode
        if (isset($_SESSION['glpi_use_mode']) && (int) $_SESSION['glpi_use_mode'] === \Session::DEBUG_MODE) {
            return true;
        }
        // Plugin-level debug toggle (cached in session for performance)
        if (!isset($_SESSION['_gdms_debug'])) {
            try {
                $cfg = new Config();
                $row = $cfg->getConfigByEntity(0);
                $_SESSION['_gdms_debug'] = !empty($row['debug_logging']) ? 1 : 0;
            } catch (\Throwable $e) {
                $_SESSION['_gdms_debug'] = 0;
            }
        }
        return (bool) $_SESSION['_gdms_debug'];
    }

    /**
     * Write a log entry.
     *
     * @param string $message  The message to log.
     * @param bool   $verbose  If true, only written when debug mode is active.
     *                         If false (default), always written.
     */
    public static function log(string $message, bool $verbose = false): void
    {
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
    public static function debug(string $message): void
    {
        self::log($message, true);
    }

    /**
     * Escape plain text before placing it inside plugin-generated ticket HTML.
     * Dynamic GDMS/GLPI values are always treated as text, never as markup.
     */
    private static function escapeTicketText(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitize the final, plugin-controlled HTML using GLPI's rich-text sanitizer.
     * GLPI 11 and 12 both provide this API. The fallback fails closed if a future
     * or unsupported GLPI runtime does not expose it.
     */
    private static function sanitizeTicketHtml(string $html): string
    {
        if (class_exists(RichText::class)) {
            return RichText::getSafeHtml($html);
        }

        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Build a consistent HTML body for automatically generated GDMS tickets.
     *
     * Rows must contain plain-text label/value pairs. All values are escaped,
     * the markup and inline styles are static, and the final HTML is sanitized
     * by GLPI before it is stored. Table markup and inline styling are deliberately
     * simple for GLPI's light/dark themes and HTML email clients.
     *
     * @param array<int, array{label:string, value:mixed}> $rows
     */
    public static function buildTicketHtml(
        string $heading,
        string $summary,
        array $rows,
        string $footer = '',
        string $reference = '',
    ): string {
        $html = '<div class="gdms-ticket-content" style="font-size:14px;line-height:1.5;">';

        if ($reference !== '') {
            $html .= '<p style="margin:0 0 8px 0;font-size:12px;">'
                . '<strong>' . self::escapeTicketText($reference) . '</strong>'
                . '</p>';
        }

        if ($heading !== '') {
            $html .= '<h3 style="margin:0 0 10px 0;font-size:16px;line-height:1.4;font-weight:600;">'
                . self::escapeTicketText($heading)
                . '</h3>';
        }

        if ($summary !== '') {
            $html .= '<p style="margin:0 0 14px 0;">'
                . nl2br(self::escapeTicketText($summary), false)
                . '</p>';
        }

        if ($rows !== []) {
            $borderStyle = 'border:1px solid #9aa4b2;padding:7px 9px;vertical-align:top;';
            $html .= '<table border="1" cellpadding="6" cellspacing="0" '
                . 'style="width:100%;border-collapse:collapse;margin:0 0 14px 0;">'
                . '<thead><tr>'
                . '<th scope="col" style="' . $borderStyle . 'text-align:left;font-weight:600;width:32%;">'
                . self::escapeTicketText(__('Field', 'gdmsintegration'))
                . '</th>'
                . '<th scope="col" style="' . $borderStyle . 'text-align:left;font-weight:600;">'
                . self::escapeTicketText(__('Value', 'gdmsintegration'))
                . '</th>'
                . '</tr></thead><tbody>';

            foreach ($rows as $row) {
                if (!is_array($row) || !array_key_exists('label', $row) || !array_key_exists('value', $row)) {
                    continue;
                }

                $label = is_scalar($row['label']) || $row['label'] === null
                    ? (string) ($row['label'] ?? '')
                    : '';
                $value = is_scalar($row['value']) || $row['value'] === null
                    ? (string) ($row['value'] ?? '')
                    : '';

                // Light striping is avoided deliberately: it has poor contrast in
                // some dark themes and is inconsistently supported by email clients.
                $html .= '<tr>'
                    . '<td style="' . $borderStyle . 'font-weight:600;">'
                    . self::escapeTicketText($label)
                    . '</td>'
                    . '<td style="' . $borderStyle . '">'
                    . nl2br(self::escapeTicketText($value), false)
                    . '</td>'
                    . '</tr>';
            }

            $html .= '</tbody></table>';
        }

        if ($footer !== '') {
            $html .= '<p style="margin:12px 0 0 0;font-size:12px;">'
                . self::escapeTicketText($footer)
                . '</p>';
        }

        $html .= '</div>';

        return self::sanitizeTicketHtml($html);
    }

    /**
     * Format an automatic ticket follow-up as safe HTML without interpreting
     * any part of the supplied message as markup.
     */
    public static function ticketHtmlNote(string $message): string
    {
        $html = '<p style="margin:0;font-size:14px;line-height:1.5;">'
            . nl2br(self::escapeTicketText($message), false)
            . '</p>';

        return self::sanitizeTicketHtml($html);
    }

    public static function encrypt(string $value): string
    {
        return (new \GLPIKey())->encrypt($value);
    }

    public static function decrypt(string $value): string
    {
        try {
            return (new \GLPIKey())->decrypt($value);
        } catch (\Throwable $e) {
            return $value;
        }
    }
    /** Structured severity helpers. Debug remains opt-in; warnings are always retained. */
    public static function warning(string $message): void
    {
        self::log('[WARNING] ' . $message);
    }

    /** Remove operational rows older than the configured retention window. */
    public static function cleanupOperationalHistory(int $entities_id, int $days): void
    {
        global $DB;
        $days = max(7, min(3650, $days));
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        foreach (['glpi_plugin_gdmsintegration_client_history' => 'seen_at', 'glpi_plugin_gdmsintegration_action_log' => 'date'] as $table => $field) {
            try {
                $DB->delete($table, ['entities_id' => $entities_id, $field => ['<', $cutoff]]);
            } catch (\Throwable $e) {
                self::debug("Retention cleanup skipped for {$table}: " . $e->getMessage());
            }
        }
    }


    /**
     * Return the remaining seconds for a provider-wide cooldown.
     * The state is stored in the plugin lock table so web and cron workers on
     * different hosting nodes observe the same rate-limit window.
     *
     * @return int<0, max>
     * @phpstan-impure Other workers may extend the value between separate reads.
     */
    public static function getProviderCooldownRemaining(string $provider): int
    {
        global $DB;
        $provider = strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $provider));
        if ($provider === '') {
            return 0;
        }

        $name = 'gdmsinteg_cooldown_' . substr($provider, 0, 80);
        $table = 'glpi_plugin_gdmsintegration_locks';
        try {
            foreach ($DB->request(['FROM' => $table, 'WHERE' => ['lock_name' => $name]]) as $row) {
                $token = (string) ($row['lock_token'] ?? '');
                if (!preg_match('/^cooldown:(\d+)$/', $token, $match)) {
                    return 0;
                }
                // Readers must not delete expired rows: a concurrent writer may
                // be extending this exact row under the provider writer lock.
                return max(0, (int) $match[1] - time());
            }
        } catch (\Throwable $e) {
            self::debug('Provider cooldown lookup unavailable: ' . $e->getMessage());
        }
        return 0;
    }

    /**
     * Persist a provider-wide cooldown monotonically across PHP and cron workers.
     * A dedicated writer lock serializes the read/extend/write sequence, so a
     * shorter concurrent Retry-After can never reduce a longer cooldown.
     */
    public static function setProviderCooldown(string $provider, int $seconds): void
    {
        global $DB;
        $provider = strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $provider));
        if ($provider === '') {
            return;
        }

        $seconds = max(10, min(86400, $seconds));
        $name = 'gdmsinteg_cooldown_' . substr($provider, 0, 80);
        $writerLock = 'gdmsinteg_cooldown_writer_' . substr($provider, 0, 60);
        $writerToken = null;

        // Ordinary contention gets a short bounded wait. A database failure
        // is surfaced separately and must not be mistaken for a busy lock.
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $lock = self::acquireLock($writerLock, 30);
            if (is_string($lock)) {
                $writerToken = $lock;
                break;
            }
            if ($lock === false) {
                self::debug('Provider cooldown writer lock failed because of a database error.');
                return;
            }
            usleep(10000);
        }
        if ($writerToken === null) {
            self::debug('Provider cooldown writer lock remained busy; current expiry was left unchanged.');
            return;
        }

        try {
            $now = time();
            $requestedUntil = $now + $seconds;
            $table = 'glpi_plugin_gdmsintegration_locks';
            $existingToken = '';
            $existingUntil = 0;

            // Compare absolute expiries while holding the provider writer lock.
            // Readers never delete expired rows, so this row cannot disappear
            // between the read and update.
            foreach ($DB->request(['FROM' => $table, 'WHERE' => ['lock_name' => $name]]) as $row) {
                $existingToken = (string) ($row['lock_token'] ?? '');
                if (preg_match('/^cooldown:(\d+)$/', $existingToken, $match) === 1) {
                    $existingUntil = (int) $match[1];
                }
                break;
            }

            $until = max($requestedUntil, $existingUntil);
            $values = [
                'lock_token' => 'cooldown:' . $until,
                'acquired_at' => gmdate('Y-m-d H:i:s'),
            ];

            if ($existingToken !== '') {
                $updated = $DB->update($table, $values, ['lock_name' => $name, 'lock_token' => $existingToken]);
                if ($updated === false) {
                    throw new \RuntimeException('Could not update the provider cooldown row.');
                }
            } else {
                $inserted = $DB->insert($table, array_merge(['lock_name' => $name], $values));
                if ($inserted === false) {
                    // A competing first insert may have happened outside this
                    // lock discipline. Re-read its absolute expiry and only extend.
                    $latestToken = '';
                    $latestUntil = 0;
                    foreach ($DB->request(['FROM' => $table, 'WHERE' => ['lock_name' => $name]]) as $row) {
                        $latestToken = (string) ($row['lock_token'] ?? '');
                        if (preg_match('/^cooldown:(\d+)$/', $latestToken, $match) === 1) {
                            $latestUntil = (int) $match[1];
                        }
                        break;
                    }
                    $until = max($until, $latestUntil);
                    if ($latestToken !== '') {
                        $updated = $DB->update($table, [
                            'lock_token' => 'cooldown:' . $until,
                            'acquired_at' => gmdate('Y-m-d H:i:s'),
                        ], ['lock_name' => $name, 'lock_token' => $latestToken]);
                        if ($updated === false) {
                            throw new \RuntimeException('Could not extend the concurrently-created provider cooldown row.');
                        }
                    } else {
                        throw new \RuntimeException('Provider cooldown insert failed and no row was available to extend.');
                    }
                }
            }
        } catch (\Throwable $e) {
            self::debug('Provider cooldown persistence unavailable: ' . $e->getMessage());
        } finally {
            self::releaseLock($writerLock, $writerToken);
        }
    }

    /**
     * Acquire a short-lived application lock using GLPI's DB abstraction.
     *
     * @return string|null|false Token on success, null on contention, false on DB error.
     */
    public static function acquireLock(string $name, int $ttl = 900): string|false|null
    {
        global $DB;
        $name = substr($name, 0, 190);
        $token = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d H:i:s');
        $staleTimestamp = time() - max(30, $ttl);
        $stale = gmdate('Y-m-d H:i:s', $staleTimestamp);
        $table = 'glpi_plugin_gdmsintegration_locks';

        try {
            $DB->delete($table, [
                'lock_name' => $name,
                'acquired_at' => ['<', $stale],
            ]);
            $inserted = $DB->insert($table, [
                'lock_name' => $name,
                'lock_token' => $token,
                'acquired_at' => $now,
            ]);
            if ($inserted !== false && $DB->affectedRows() > 0) {
                return $token;
            }

            foreach ($DB->request(['FROM' => $table, 'WHERE' => ['lock_name' => $name]]) as $row) {
                if ((string) ($row['lock_token'] ?? '') === $token) {
                    return $token;
                }
                $existingAt = strtotime((string) ($row['acquired_at'] ?? '') . ' UTC');
                if ($existingAt !== false && $existingAt >= $staleTimestamp) {
                    return null;
                }
                self::debug("Lock row could not be reclaimed for {$name}; treating acquisition as a database failure.");
                return false;
            }
            self::debug("Lock acquisition failed without an existing row: {$name}");
            return false;
        } catch (\Throwable $e) {
            // A recent row means genuine contention. A stale row that could not
            // be reclaimed, or a failed lookup, indicates a database problem.
            try {
                foreach ($DB->request(['FROM' => $table, 'WHERE' => ['lock_name' => $name]]) as $row) {
                    $existingAt = strtotime((string) ($row['acquired_at'] ?? '') . ' UTC');
                    if ($existingAt !== false && $existingAt >= $staleTimestamp) {
                        return null;
                    }
                    self::debug("Stale lock row could not be reclaimed for {$name}: " . $e->getMessage());
                    return false;
                }
            } catch (\Throwable $lookupError) {
                self::debug('Lock acquisition failed and lock state could not be checked: ' . $e->getMessage());
                return false;
            }
            self::debug('Lock acquisition failed: ' . $e->getMessage());
            return false;
        }
    }

    public static function releaseLock(string $name, string $token): void
    {
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
