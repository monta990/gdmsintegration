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

    // In-process cache: mac → row. Populated by primeCache() once per sync cycle.
    private static array $stateCache = [];

    public static function primeCache(): void {
        self::$stateCache = [];
        $obj = new self();
        foreach ($obj->find() as $row) {
            self::$stateCache[strtolower($row['mac'])] = $row;
        }
    }

    public function getState(string $mac): ?string {
        if (isset(self::$stateCache[$mac])) {
            return self::$stateCache[$mac]['status'] ?? null;
        }
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
        // Use in-process cache to skip a DB round-trip when primeCache() was called
        if (isset(self::$stateCache[$mac])) {
            $cached = self::$stateCache[$mac];
            $rows   = [$cached['id'] => $cached];
        } else {
            $rows = $this->find(['mac' => $mac]);
        }
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
        // Only set entities_id when caller knows it (>0) — never overwrite entity set by cron/ajax sync.
        if ($entities_id > 0) $data['entities_id'] = $entities_id;
        if (!empty($rows)) {
            $id  = array_key_first($rows);
            $ok  = (bool) $this->update(array_merge(['id' => $id], $data));
            self::$stateCache[$mac] = array_merge($rows[$id], $data, ['id' => $id]);
            return $ok;
        }
        $new_id = (int) $this->add(array_merge(['mac' => $mac, 'entities_id' => $entities_id], $data));
        if ($new_id > 0) {
            self::$stateCache[$mac] = array_merge(['mac' => $mac, 'id' => $new_id, 'entities_id' => $entities_id], $data);
        }
        return $new_id > 0;
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
