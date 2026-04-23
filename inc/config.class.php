<?php
/**
 * GDMS Integration — PluginGdmsintegrationConfig
 */
class PluginGdmsintegrationConfig extends CommonDBTM {

    public static function getTable($classname = null): string {
        return 'glpi_plugin_gdmsintegration_configs';
    }

    static $rightname = 'config';

    public static function getTypeName($nb = 0): string {
        return __('GDMS Configuration', 'gdmsintegration');
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
            $c['client_secret'] = PluginGdmsintegrationUtils::decrypt($c['client_secret']);
        }
        if (!empty($c['password'])) {
            $c['password'] = PluginGdmsintegrationUtils::decrypt($c['password']);
        }
        if (!empty($c['gwn_client_secret'])) {
            $c['gwn_client_secret'] = PluginGdmsintegrationUtils::decrypt($c['gwn_client_secret']);
        }
        return $c;
    }

    /**
     * Upsert config for an entity.
     * If client_secret is empty the existing stored secret is preserved.
     */
    public function saveConfig(array $input): bool {
        $existing = $this->find(['entities_id' => (int) $input['entities_id']]);

        // Encrypt sensitive fields only when a new value is provided
        if (empty($input['client_secret'])) {
            unset($input['client_secret']);
        } else {
            $input['client_secret'] = PluginGdmsintegrationUtils::encrypt($input['client_secret']);
        }
        if (empty($input['password'])) {
            unset($input['password']);
        } else {
            $input['password'] = PluginGdmsintegrationUtils::encrypt($input['password']);
        }
        if (empty($input['gwn_client_secret'])) {
            unset($input['gwn_client_secret']);
        } else {
            $input['gwn_client_secret'] = PluginGdmsintegrationUtils::encrypt($input['gwn_client_secret']);
        }

        // Set defaults for new fields if not provided
        if (!isset($input['chart_days']) || (int)$input['chart_days'] < 1) {
            $input['chart_days'] = 60;
        }
        $input['chart_days']     = min((int)$input['chart_days'], 365);
        $input['show_topology']  = isset($input['show_topology']) ? (int)(bool)$input['show_topology'] : 1;
        $input['ticket_requester_id'] = (int)($input['ticket_requester_id'] ?? 0);
        $input['wan_debounce_seconds'] = max(0, min(3600, (int)($input['wan_debounce_seconds'] ?? 300)));

        $input['date_mod'] = date('Y-m-d H:i:s');

        if (!empty($existing)) {
            $input['id'] = array_key_first($existing);
            return (bool) $this->update($input);
        }

        $input['date_creation'] = date('Y-m-d H:i:s');
        return (bool) $this->add($input);
    }
}
