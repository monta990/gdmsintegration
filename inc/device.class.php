<?php
/**
 * GDMS Integration — PluginGdmsintegrationDevice
 * Tracks last-known status per MAC to detect state transitions.
 */
class PluginGdmsintegrationDevice extends PluginGdmsintegrationBaseTM {

    // Override to match exact table name created in setup.php
    public static function getTable($classname = null): string {
        return 'glpi_plugin_gdmsintegration_devices';
    }



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
        string $ip             = '',
        string $firmware       = '',
        int    $uptime_sec     = 0,
        string $sn_cloud       = '',
        int    $network_id     = 0,
        string $wan_ports_json = '',
        string $model          = '',
        string $cloud_name     = '',
        int    $clients        = 0,
        int    $usage_bytes    = 0,
        int    $upload_bytes   = 0,
        int    $download_bytes = 0,
        int    $channel_2g     = 0,
        int    $channel_5g     = 0,
        ?string $first_seen    = null,
        ?string $last_seen     = null,
        string $mgmt_ip         = '',
        string $firmware_latest = '',
        string $sip_status      = '',
        int    $entities_id     = 0,
        string $ipv6            = '',
        string $private_ip      = '',
        string $sip_extension   = '',
        string $location        = '',
        int    $dnd             = 0,
        int    $is_synchronized = 0,
        string $sync_failure_msg = '',
        int    $scheduled_task  = 0
    ): bool {
        $rows = $this->find(['mac' => $mac]);
        $data = [
            'status'          => $status,
            'network_name'    => $network_name,
            'network_id'      => $network_id,
            'ip'              => $ip,
            'firmware'        => $firmware,
            'uptime_sec'      => $uptime_sec,
            'sn_cloud'        => $sn_cloud,
            'wan_ports_json'  => $wan_ports_json,
            'model'           => $model,
            'cloud_name'      => $cloud_name,
            'clients'         => $clients,
            'usage_bytes'     => $usage_bytes,
            'upload_bytes'    => $upload_bytes,
            'download_bytes'  => $download_bytes,
            'channel_2g'      => $channel_2g,
            'channel_5g'      => $channel_5g,
            'mgmt_ip'         => $mgmt_ip,
            'firmware_latest' => $firmware_latest,
            'sip_status'      => $sip_status,
            'ipv6'            => $ipv6,
            'private_ip'      => $private_ip,
            'sip_extension'   => $sip_extension,
            'location'        => $location,
            'dnd'             => $dnd,
            'is_synchronized' => $is_synchronized,
            'sync_failure_msg'=> $sync_failure_msg,
            'scheduled_task'  => $scheduled_task,
        ];
        if ($first_seen !== null) $data['first_seen'] = $first_seen;
        if ($last_seen  !== null) $data['last_seen']  = $last_seen;
        // Only set entities_id when caller knows it (>0) so that webhook updates
        // (which pass 0) never overwrite the entity set by a proper cron/ajax sync.
        if ($entities_id > 0) $data['entities_id'] = $entities_id;
        if (!empty($rows)) {
            return (bool) $this->update(array_merge(['id' => array_key_first($rows)], $data));
        }
        return (bool) $this->add(array_merge(['mac' => $mac, 'entities_id' => $entities_id], $data));
    }

    public function getWanPortsJson(string $mac): string {
        $fresh = new self();
        $rows  = $fresh->find(['mac' => $mac]);
        return empty($rows) ? '' : (reset($rows)['wan_ports_json'] ?? '');
    }

    public function getNetworkName(string $mac): string {
        $rows = $this->find(['mac' => $mac]);
        return empty($rows) ? '' : (reset($rows)['network_name'] ?? '');
    }
}
