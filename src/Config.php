<?php

namespace GlpiPlugin\Gdmsintegration;
/**
 * GDMS Integration — \GlpiPlugin\Gdmsintegration\Config
 */
class Config extends \GlpiPlugin\Gdmsintegration\BaseTM {

    public static function getTable($classname = null): string {
        return 'glpi_plugin_gdmsintegration_configs';
    }


    public static function getTypeName($nb = 0): string {
        return __('GDMS Configuration', 'gdmsintegration');
    }

    /** Determine whether an ITIL category is visible for the configured entity. */
    private static function isTicketCategoryVisibleForEntity(array $row, int $entities_id): bool {
        $category_entity = (int)($row['entities_id'] ?? 0);
        if ($category_entity === $entities_id) {
            return true;
        }

        // Recursive categories assigned to an ancestor (including GLPI's global
        // entity 0) are inherited by the configured entity.
        if ((int)($row['is_recursive'] ?? 0) === 1 && function_exists('getAncestorsOf')) {
            $ancestors = getAncestorsOf('glpi_entities', $entities_id);
            return in_array($category_entity, array_map('intval', $ancestors), true);
        }

        return false;
    }

    /** Return incident-visible ITIL categories available to an entity. */
    public static function getTicketCategoryOptions(int $entities_id): array {
        global $DB;

        if (!class_exists('ITILCategory') || !isset($DB)) {
            return [];
        }

        // Use the real ITIL category table. The configuration form can edit a
        // selected entity while GLPI's active entity may be different, so the
        // current-session entity restriction is not suitable here.
        $rows = $DB->request([
            'SELECT' => ['id', 'name', 'completename', 'entities_id', 'is_recursive', 'is_incident'],
            'FROM'   => \ITILCategory::getTable(),
            'WHERE'  => ['is_incident' => 1],
            'ORDER'  => 'completename',
        ]);

        $options = [];
        foreach ($rows as $row) {
            $id = (int)($row['id'] ?? 0);
            if ($id <= 0 || !self::isTicketCategoryVisibleForEntity($row, $entities_id)) {
                continue;
            }

            $label = trim((string)($row['completename'] ?? ''));
            if ($label === '') {
                $label = trim((string)($row['name'] ?? ''));
            }
            if ($label !== '') {
                $options[] = ['id' => $id, 'label' => $label];
            }
        }

        return $options;
    }

    /** Validate an optional category against GLPI's real incident categories. */
    public static function validateTicketCategoryId(int $category_id, int $entities_id): int {
        if ($category_id <= 0 || !class_exists('ITILCategory')) {
            return 0;
        }

        global $DB;
        $row = $DB->request([
            'SELECT' => ['id', 'entities_id', 'is_recursive', 'is_incident'],
            'FROM'   => \ITILCategory::getTable(),
            'WHERE'  => ['id' => $category_id, 'is_incident' => 1],
            'LIMIT'  => 1,
        ])->current();

        return !empty($row['id']) && self::isTicketCategoryVisibleForEntity($row, $entities_id)
            ? (int)$row['id']
            : 0;
    }

    /**
     * Check the latest published stable plugin release on GitHub.
     *
     * Uses GitHub's public "latest release" endpoint. Results are cached in
     * the current GLPI session for 6 hours so the configuration page does not
     * contact GitHub on every request.
     */
    public static function getPluginUpdateInfo(): array {
        $current = defined('PLUGIN_GDMSINTEGRATION_VERSION')
            ? PLUGIN_GDMSINTEGRATION_VERSION
            : '0.0.0';

        $empty = [
            'current_version' => $current,
            'latest_version'  => null,
            'release_url'     => null,
            'checked_at'      => null,
            'update_available'=> false,
            'error'            => null,
        ];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return $empty;
        }

        $cache_key = '_gdms_plugin_update_check';
        $ttl = 21600; // 6 hours
        $cached = $_SESSION[$cache_key] ?? null;

        if (is_array($cached)
            && isset($cached['checked_at'])
            && (time() - (int)$cached['checked_at']) < $ttl
        ) {
            $cached['current_version'] = $current;
            $cached['update_available'] = !empty($cached['latest_version'])
                && version_compare((string)$current, (string)$cached['latest_version'], '<');
            return array_merge($empty, $cached);
        }

        $url = 'https://api.github.com/repos/monta990/gdmsintegration/releases/latest';
        $ch = curl_init($url);
        if ($ch === false) {
            return $empty;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'X-GitHub-Api-Version: 2026-03-10',
                'User-Agent: GLPI-GDMSIntegration/' . $current,
            ],
        ]);

        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $result = $empty;
        $result['checked_at'] = time();

        if ($body === false || $http < 200 || $http >= 300) {
            $result['error'] = $http > 0 ? 'HTTP ' . $http : ($error !== '' ? $error : 'request_failed');
            $_SESSION[$cache_key] = $result;
            return $result;
        }

        $data = json_decode((string)$body, true);
        if (!is_array($data) || !empty($data['draft']) || !empty($data['prerelease'])) {
            $result['error'] = 'invalid_release_response';
            $_SESSION[$cache_key] = $result;
            return $result;
        }

        $tag = trim((string)($data['tag_name'] ?? ''));
        $latest = preg_replace('/^v/i', '', $tag);
        if ($latest === '' || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $latest)) {
            $result['error'] = 'invalid_version';
            $_SESSION[$cache_key] = $result;
            return $result;
        }

        $result['latest_version'] = $latest;
        $result['release_url'] = filter_var((string)($data['html_url'] ?? ''), FILTER_VALIDATE_URL)
            ? (string)$data['html_url']
            : null;
        $result['update_available'] = version_compare($current, $latest, '<');

        $_SESSION[$cache_key] = $result;
        return $result;
    }

    /**
     * Returns decrypted config array for the given entity, or [].
     */
    public static function getConfigByEntity(int $entities_id): array {
        $self  = new self();
        $found = $self->find(['entities_id' => $entities_id]);
        if (empty($found)) {
            return [];
        }
        $c = reset($found);
        if (!empty($c['client_secret'])) {
            $c['client_secret'] = \GlpiPlugin\Gdmsintegration\Utils::decrypt($c['client_secret']);
        }
        if (!empty($c['password'])) {
            $c['password'] = \GlpiPlugin\Gdmsintegration\Utils::decrypt($c['password']);
        }
        if (!empty($c['gdms_access_token'])) {
            $c['gdms_access_token'] = \GlpiPlugin\Gdmsintegration\Utils::decrypt($c['gdms_access_token']);
        }
        if (!empty($c['gdms_refresh_token'])) {
            $c['gdms_refresh_token'] = \GlpiPlugin\Gdmsintegration\Utils::decrypt($c['gdms_refresh_token']);
        }
        if (!empty($c['gwn_client_secret'])) {
            $c['gwn_client_secret'] = \GlpiPlugin\Gdmsintegration\Utils::decrypt($c['gwn_client_secret']);
        }
        if (!empty($c['gwn_access_token'])) {
            $c['gwn_access_token'] = \GlpiPlugin\Gdmsintegration\Utils::decrypt($c['gwn_access_token']);
        }
        return $c;
    }

    /**
     * Upsert config for an entity.
     * If client_secret is empty the existing stored secret is preserved.
     */
    public function saveConfig(array $input): bool {
        $existing = $this->find(['entities_id' => (int) $input['entities_id']]);

        // Configuration saves invalidate the persisted OAuth session. This prevents a
        // token issued for old credentials/region from surviving a credential change.
        $input['gdms_access_token'] = '';
        $input['gdms_refresh_token'] = '';
        $input['gdms_token_expires_at'] = 0;
        $input['gwn_access_token'] = '';
        $input['gwn_token_expires_at'] = 0;

        // Encrypt sensitive fields only when a new value is provided
        if (empty($input['client_secret'])) {
            unset($input['client_secret']);
        } else {
            $input['client_secret'] = \GlpiPlugin\Gdmsintegration\Utils::encrypt($input['client_secret']);
        }
        if (empty($input['password'])) {
            unset($input['password']);
        } else {
            $input['password'] = \GlpiPlugin\Gdmsintegration\Utils::encrypt($input['password']);
        }
        if (empty($input['gwn_client_secret'])) {
            unset($input['gwn_client_secret']);
        } else {
            $input['gwn_client_secret'] = \GlpiPlugin\Gdmsintegration\Utils::encrypt($input['gwn_client_secret']);
        }

        // Set defaults for new fields if not provided
        if (!isset($input['chart_days']) || (int)$input['chart_days'] < 1) {
            $input['chart_days'] = 60;
        }
        $input['chart_days']     = min((int)$input['chart_days'], 365);
        $input['show_topology']  = isset($input['show_topology']) ? (int)(bool)$input['show_topology'] : 1;
        $input['ticket_requester_id'] = (int)($input['ticket_requester_id'] ?? 0);
        $input['wan_debounce_seconds'] = max(0, min(3600, (int)($input['wan_debounce_seconds'] ?? 300)));
        $input['wan_tickets_enabled']  = isset($input['wan_tickets_enabled']) ? (int)(bool)$input['wan_tickets_enabled'] : 1;
        $input['tickets_phone']  = isset($input['tickets_phone'])  ? (int)(bool)$input['tickets_phone']  : 1;
        $input['tickets_router'] = isset($input['tickets_router']) ? (int)(bool)$input['tickets_router'] : 1;
        $input['tickets_switch'] = isset($input['tickets_switch']) ? (int)(bool)$input['tickets_switch'] : 1;
        $input['tickets_ap']     = isset($input['tickets_ap'])     ? (int)(bool)$input['tickets_ap']     : 1;
        $input['tickets_pbx']    = isset($input['tickets_pbx'])    ? (int)(bool)$input['tickets_pbx']    : 1;

        // Optional ticket categories: preserve existing values when importing older configs.
        $existing_row = !empty($existing) ? reset($existing) : [];
        $network_category = array_key_exists('ticket_category_network_id', $input)
            ? (int)$input['ticket_category_network_id']
            : (int)($existing_row['ticket_category_network_id'] ?? 0);
        $telephony_category = array_key_exists('ticket_category_telephony_id', $input)
            ? (int)$input['ticket_category_telephony_id']
            : (int)($existing_row['ticket_category_telephony_id'] ?? 0);
        $input['ticket_category_network_id'] = self::validateTicketCategoryId($network_category, (int)$input['entities_id']);
        $input['ticket_category_telephony_id'] = self::validateTicketCategoryId($telephony_category, (int)$input['entities_id']);

        $input['gdms_region']    = in_array(strtolower((string)($input['gdms_region'] ?? 'us')), ['us', 'eu'], true) ? strtolower((string)$input['gdms_region']) : 'us';
        $input['history_retention_days'] = max(7, min(3650, (int)($input['history_retention_days'] ?? 90)));
        $input['ip_version']     = in_array($input['ip_version'] ?? '', ['ipv4', 'ipv6'], true) ? $input['ip_version'] : 'ipv4';

        $input['date_mod'] = date('Y-m-d H:i:s');

        if (!empty($existing)) {
            $input['id'] = array_key_first($existing);
            return (bool) $this->update($input);
        }

        $input['date_creation'] = date('Y-m-d H:i:s');
        return (bool) $this->add($input);
    }
}
