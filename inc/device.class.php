<?php
/**
 * GDMS Integration — PluginGdmsintegrationDevice
 * Tracks last-known status per MAC to detect state transitions.
 */
class PluginGdmsintegrationDevice extends CommonDBTM {

    // Override to match exact table name created in setup.php
    public static function getTable($classname = null): string {
        return 'glpi_plugin_gdmsintegration_devices';
    }

    static $rightname = 'config';

    public static function getTypeName($nb = 0): string {
        return 'GDMS Device State';
    }

    public function getState(string $mac): ?string {
        // Use a fresh instance to avoid CommonDBTM internal field cache
        $fresh = new self();
        $rows  = $fresh->find(['mac' => $mac]);
        if (empty($rows)) {
            return null;
        }
        return reset($rows)['status'] ?? null;
    }

    public function saveState(string $mac, string $status): bool {
        return $this->saveStateWithNetwork($mac, $status, '');
    }

    public function saveStateWithNetwork(
        string $mac,
        string $status,
        string $network_name,
        string $ip         = '',
        string $firmware   = '',
        int    $uptime_sec = 0,
        string $sn_cloud   = '',
        int    $network_id = 0
    ): bool {
        $rows = $this->find(['mac' => $mac]);
        $data = [
            'status'       => $status,
            'network_name' => $network_name,
            'network_id'   => $network_id,
            'ip'           => $ip,
            'firmware'     => $firmware,
            'uptime_sec'   => $uptime_sec,
            'sn_cloud'     => $sn_cloud,
        ];
        if (!empty($rows)) {
            return (bool) $this->update(array_merge(['id' => array_key_first($rows)], $data));
        }
        return (bool) $this->add(array_merge(['mac' => $mac], $data));
    }

    public function getNetworkName(string $mac): string {
        $rows = $this->find(['mac' => $mac]);
        return empty($rows) ? '' : (reset($rows)['network_name'] ?? '');
    }
}
