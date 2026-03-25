<?php
/**
 * GDMS Integration — PluginGdmsintegrationSync
 *
 * Handles synchronization of two GDMS device families into GLPI:
 *   1. Networking  (GWN series) → NetworkEquipment
 *   2. UC/VoIP     (GRP/GXP/GXV/WP/HT phones, UCM/GCC PBX)
 *        → Phone            if device is a phone/endpoint
 *        → NetworkEquipment if device is a PBX/appliance
 *        → Respects the itemtype the user already chose in GLPI
 *
 * Fields written (same for every itemtype):
 *   uuid   ← MAC address from GDMS
 *   serial ← serial number (sn / serial_number / serial) from GDMS
 *   name   ← device_name set in GDMS portal, fallback to model string
 *   <model_id_field> ← matched against existing GLPI models (never creates new)
 *   comment ← "GDMS:Online" / "GDMS:Offline"
 */
class PluginGdmsintegrationSync extends CommonGLPI {

    // -----------------------------------------------------------------------
    // GLPI Cron entry point — cronSyncDevices
    // -----------------------------------------------------------------------
    public static function cronSyncDevices(CronTask $task): int {
        $entities = getAllDataFromTable('glpi_entities', ['is_deleted' => 0]);
        $total    = 0;

        foreach ($entities as $entity) {
            $total += self::syncEntity((int) $entity['id']);
        }

        $task->addVolume($total);
        PluginGdmsintegrationUtils::log(
            sprintf("GDMS Sync completed — %d device(s) processed", $total)
        );

        return ($total > 0) ? 1 : 0;
    }

    public static function cronInfo(string $name): array {
        return match ($name) {
            'syncDevices' => [
                'description' => __('Synchronize GDMS cloud devices with GLPI', 'gdmsintegration'),
            ],
            default => [],
        };
    }

    // -----------------------------------------------------------------------
    // Per-entity sync — called from cron and webhook
    // -----------------------------------------------------------------------
    public static function syncEntity(int $entities_id): int {
        $config = PluginGdmsintegrationConfig::getConfigByEntity($entities_id);
        if (empty($config['client_id']) || empty($config['client_secret'])) {
            return 0;
        }

        $tokenData = PluginGdmsintegrationAPI::getToken($config);
        if ($tokenData === false) {
            PluginGdmsintegrationUtils::log(
                "GDMS: Failed to obtain token for entity {$entities_id}"
            );
            return 0;
        }

        // Merge token into config so signedUrl() has everything it needs
        $config['access_token']  = $tokenData['access_token'];
        $config['refresh_token'] = $tokenData['refresh_token'];

        $devices = PluginGdmsintegrationAPI::getDevices($config);

        $total = 0;
        $total += self::syncDeviceList($devices, $entities_id);

        self::cleanupHistory();
        return $total;
    }

    // -----------------------------------------------------------------------
    // Core upsert loop (shared by both families)
    // -----------------------------------------------------------------------
    private static function syncDeviceList(
        array $devices,
        int   $entities_id
    ): int {
        if (empty($devices)) {
            return 0;
        }

        // ---------------------------------------------------------------
        // Pre-load model caches (one query per model table)
        // ---------------------------------------------------------------
        $ne_model_cache    = self::buildModelCache('glpi_networkequipmentmodels');
        $phone_model_cache = self::buildModelCache('glpi_phonemodels');

        // ---------------------------------------------------------------
        // Pre-load existing GLPI assets by UUID (MAC) and serial.
        // We search BOTH NetworkEquipment AND Phone so we can respect
        // the itemtype the user already assigned.
        // Structure: [ 'MAC/serial' => ['itemtype' => ..., 'id' => ..., ...] ]
        // ---------------------------------------------------------------
        [$mac_cache, $serial_cache] = self::buildAssetCaches($entities_id);

        $state   = new PluginGdmsintegrationDevice();
        $history = new PluginGdmsintegrationHistory();
        $link    = new PluginGdmsintegrationLink();

        foreach ($devices as $d) {
            // Official API fields: deviceName, deviceType, mac, sn, status (1/0/-1)
            $mac    = strtolower(trim($d['mac'] ?? ''));
            $serial = strtolower(trim($d['sn']  ?? ''));

            if (empty($mac) && empty($serial)) {
                continue;
            }

            // deviceName = name set in GDMS portal; deviceType = model string
            $raw_name = $d['deviceName'] ?? $d['deviceType'] ?? '';
            $name     = htmlspecialchars(
                $raw_name !== '' ? $raw_name : 'GDMS Device',
                ENT_QUOTES,
                'UTF-8'
            );

            $gdms_model = trim($d['deviceType'] ?? '');
            // status: 1=online, 0=offline, -1=abnormal
            $is_online  = (int) ($d['status'] ?? 0) === 1;
            $status     = $is_online ? 'online' : 'offline';
            $comment    = 'GDMS:' . ucfirst($status);

            // ---------------------------------------------------------------
            // Determine itemtype:
            //   1. If asset already exists in GLPI → keep its itemtype
            //   2. Otherwise classify by GDMS model prefix
            //   3. Network family defaults to NetworkEquipment
            // ---------------------------------------------------------------
            $matched_row  = null;
            $matched_type = null;

            if (!empty($mac) && isset($mac_cache[$mac])) {
                $matched_row  = $mac_cache[$mac];
                $matched_type = $matched_row['_itemtype'];
            } elseif (!empty($serial) && isset($serial_cache[$serial])) {
                $matched_row  = $serial_cache[$serial];
                $matched_type = $matched_row['_itemtype'];
                PluginGdmsintegrationUtils::log(
                    "GDMS: matched '{$name}' by serial '{$serial}' in {$matched_type}"
                );
            }

            if ($matched_type === null) {
                // New device — classify by model prefix
                // GWN series (null from classifier) → NetworkEquipment
                // GRP/GXP/WP/HT/etc. → Phone
                // UCM/GCC/etc. → NetworkEquipment
                $classified   = PluginGdmsintegrationAPI::classifyModel($gdms_model);
                $matched_type = $classified ?? 'NetworkEquipment';
            }

            // ---------------------------------------------------------------
            // Resolve model ID in the correct GLPI table
            // ---------------------------------------------------------------
            $model_field = null;
            $model_id    = 0;

            if ($matched_type === 'Phone') {
                $model_field = 'phonemodels_id';
                $model_id    = $phone_model_cache[strtolower($gdms_model)] ?? 0;
            } else {
                $model_field = 'networkequipmentmodels_id';
                $model_id    = $ne_model_cache[strtolower($gdms_model)] ?? 0;
            }

            // ---------------------------------------------------------------
            // Upsert
            // ---------------------------------------------------------------
            $glpi_id = self::upsertAsset(
                $matched_type,
                $matched_row,
                $entities_id,
                $name,
                $mac,
                $serial,
                $comment,
                $model_field,
                $model_id
            );

            // Update caches for subsequent iterations
            if ($glpi_id > 0) {
                $new_row = [
                    '_itemtype' => $matched_type,
                    'id'        => $glpi_id,
                    'name'      => $name,
                    'uuid'      => $mac,
                    'serial'    => $serial,
                    'comment'   => $comment,
                    $model_field => $model_id,
                ];
                if (!empty($mac))    { $mac_cache[$mac]       = $new_row; }
                if (!empty($serial)) { $serial_cache[$serial] = $new_row; }
            }

            // History
            $history->add([
                'mac'    => $mac ?: $serial,
                'status' => $status,
                'date'   => date('Y-m-d H:i:s'),
            ]);

            // Incident ticket on online → offline transition
            $prevStatus = $state->getState($mac ?: $serial);
            if ($prevStatus === 'online' && $status === 'offline' && $glpi_id > 0) {
                self::createOfflineTicket(
                    $name,
                    $mac,
                    $serial,
                    $entities_id,
                    $matched_type,
                    $glpi_id
                );
            }
            $state->saveState($mac ?: $serial, $status);

            // Topology link (networking devices only)
            if (!empty($d['uplink_mac'])) {
                $uplink = strtolower(trim($d['uplink_mac']));
                if (empty($link->find(['source_mac' => $mac, 'target_mac' => $uplink]))) {
                    $link->add([
                        'source_mac' => $mac,
                        'target_mac' => $uplink,
                        'type'       => 'uplink',
                    ]);
                }
            }
        }

        return count($devices);
    }

    // -----------------------------------------------------------------------
    // Upsert a single asset (NetworkEquipment or Phone)
    // Returns GLPI item ID (>0) or 0 on failure.
    // -----------------------------------------------------------------------
    private static function upsertAsset(
        string  $itemtype,
        ?array  $matched_row,
        int     $entities_id,
        string  $name,
        string  $mac,
        string  $serial,
        string  $comment,
        string  $model_field,
        int     $model_id
    ): int {
        /** @var CommonDBTM $obj */
        $obj = new $itemtype();

        if ($matched_row !== null) {
            // Existing asset — update only changed fields
            $glpi_id = (int) $matched_row['id'];
            $update  = ['id' => $glpi_id, 'comment' => $comment];

            if (($matched_row['name'] ?? '') !== $name) {
                $update['name'] = $name;
            }
            if (!empty($mac) && strtolower(trim($matched_row['uuid'] ?? '')) !== $mac) {
                $update['uuid'] = $mac;
            }
            if (!empty($serial) && strtolower(trim($matched_row['serial'] ?? '')) !== $serial) {
                $update['serial'] = $serial;
            }
            if (
                $model_id > 0 &&
                (int) ($matched_row[$model_field] ?? 0) !== $model_id
            ) {
                $update[$model_field] = $model_id;
            }

            $obj->update($update);
            return $glpi_id;
        }

        // New asset
        $add_data = [
            'name'        => $name,
            'entities_id' => $entities_id,
            'comment'     => $comment,
        ];
        if (!empty($mac))    { $add_data['uuid']   = $mac;    }
        if (!empty($serial)) { $add_data['serial'] = $serial; }
        if ($model_id > 0)   { $add_data[$model_field] = $model_id; }

        return (int) $obj->add($add_data);
    }

    // -----------------------------------------------------------------------
    // Open an offline incident ticket and link the asset
    // -----------------------------------------------------------------------
    private static function createOfflineTicket(
        string $name,
        string $mac,
        string $serial,
        int    $entities_id,
        string $itemtype,
        int    $glpi_id
    ): void {
        $ticket    = new Ticket();
        $ticket_id = (int) $ticket->add([
            'name'        => sprintf(
                __('Device Offline: %s', 'gdmsintegration'),
                $name
            ),
            'content'     => sprintf(
                __('GDMS device %s (MAC: %s, S/N: %s) went offline.', 'gdmsintegration'),
                $name,
                strtoupper($mac),
                strtoupper($serial) ?: __('N/A', 'gdmsintegration')
            ),
            'entities_id' => $entities_id,
            'urgency'     => 3,
            'impact'      => 3,
            'priority'    => 3,
            'type'        => Ticket::INCIDENT_TYPE,
            'status'      => Ticket::INCOMING,
        ]);

        if ($ticket_id > 0) {
            $item_ticket = new Item_Ticket();
            $item_ticket->add([
                'tickets_id'    => $ticket_id,
                'itemtype'      => $itemtype,
                'items_id'      => $glpi_id,
                '_disablenotif' => true,
            ]);

            PluginGdmsintegrationUtils::log(
                sprintf(
                    "GDMS: Ticket #%d created and linked to %s #%d (%s)",
                    $ticket_id,
                    $itemtype,
                    $glpi_id,
                    $name
                )
            );
        }
    }

    // -----------------------------------------------------------------------
    // Build asset caches from both NetworkEquipment and Phone
    // Returns [$mac_cache, $serial_cache]
    // Each entry carries '_itemtype' so we know where the asset lives.
    // -----------------------------------------------------------------------
    private static function buildAssetCaches(int $entities_id): array {
        $mac_cache    = [];
        $serial_cache = [];

        foreach (['NetworkEquipment', 'Phone'] as $itemtype) {
            /** @var CommonDBTM $obj */
            $obj  = new $itemtype();
            $rows = $obj->find(['entities_id' => $entities_id]);

            foreach ($rows as $row) {
                $row['_itemtype'] = $itemtype;

                if (!empty($row['uuid'])) {
                    $key = strtolower(trim($row['uuid']));
                    // NetworkEquipment wins over Phone if same MAC exists in both
                    if (!isset($mac_cache[$key])) {
                        $mac_cache[$key] = $row;
                    }
                }
                if (!empty($row['serial'])) {
                    $key = strtolower(trim($row['serial']));
                    if (!isset($serial_cache[$key])) {
                        $serial_cache[$key] = $row;
                    }
                }
            }
        }

        return [$mac_cache, $serial_cache];
    }

    // -----------------------------------------------------------------------
    // Build model name → ID cache from a GLPI model table
    // -----------------------------------------------------------------------
    private static function buildModelCache(string $table): array {
        $cache = [];
        foreach (getAllDataFromTable($table) as $m) {
            $cache[strtolower(trim($m['name']))] = (int) $m['id'];
        }
        return $cache;
    }

    // -----------------------------------------------------------------------
    // History cleanup (60-day retention)
    // -----------------------------------------------------------------------
    public static function cleanupHistory(): void {
        global $DB;
        $cutoff = date('Y-m-d H:i:s', strtotime('-60 days'));
        $DB->doQuery(
            "DELETE FROM `glpi_plugin_gdmsintegration_history`
             WHERE `date` < '{$cutoff}'"
        );
    }

    // -----------------------------------------------------------------------
    // Uptime / SLA helpers — used by dashboard
    // -----------------------------------------------------------------------
    public static function calculateUptime(string $mac): float {
        $history = new PluginGdmsintegrationHistory();
        $rows    = $history->find(['mac' => $mac]);
        $total   = count($rows);
        if ($total === 0) {
            return 0.0;
        }
        $online = 0;
        foreach ($rows as $r) {
            if ($r['status'] === 'online') {
                $online++;
            }
        }
        return round(($online / $total) * 100, 2);
    }

    public static function calculateSLA(string $mac): string {
        $uptime = self::calculateUptime($mac);
        if ($uptime >= 99.9) return __('Gold',     'gdmsintegration');
        if ($uptime >= 99.0) return __('Silver',   'gdmsintegration');
        if ($uptime >= 95.0) return __('Bronze',   'gdmsintegration');
        return __('Critical', 'gdmsintegration');
    }
}
