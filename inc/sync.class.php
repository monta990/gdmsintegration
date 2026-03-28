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
        // glpi_entities has no is_deleted column — use Entity ORM to get all active entities
        $entity_obj = new Entity();
        $entities   = $entity_obj->find();
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

        $total = 0;
        $ts    = date('Y-m-d H:i:s');

        // Identify caller: cron task, ajax button, or auto-refresh
$source = $_GET['source'] ?? null;
if (defined('GLPI_CRON') || php_sapi_name() === 'cli') {
    $caller = 'cron';
} elseif ($source === 'button') {
    $caller = 'ajax-button';
} elseif ($source === 'auto-refresh') {
    $caller = 'auto-refresh';
} else {
    $caller = 'ajax';
}
PluginGdmsintegrationUtils::log("[{$ts}] syncEntity called — source={$caller} entity={$entities_id}");

        // ── GWN API (networking: APs, Switches, Routers) ──────────────────
        if (!empty($config['gwn_client_id']) && !empty($config['gwn_client_secret'])) {
            PluginGdmsintegrationUtils::log("[{$ts}] GWN sync start — entity {$entities_id}");

            $gwnToken = PluginGdmsintegrationAPI::gwnGetToken($config);
            if ($gwnToken !== false) {
                PluginGdmsintegrationUtils::log("[{$ts}] GWN token obtained OK — API ID: {$config['gwn_client_id']}");
                $config['gwn_access_token'] = $gwnToken;
                $gwnDevices = PluginGdmsintegrationAPI::gwnGetDevices($config);
                $gwnCount   = count($gwnDevices);
                PluginGdmsintegrationUtils::log("[{$ts}] GWN API returned {$gwnCount} device(s)");
                $synced = self::syncDeviceList($gwnDevices, $entities_id, $config);
                PluginGdmsintegrationUtils::log("[{$ts}] GWN sync complete — {$synced} device(s) processed");
                $total += $synced;
            } else {
                PluginGdmsintegrationUtils::log("[{$ts}] GWN ERROR — could not obtain token for entity {$entities_id}. Check gwn_client_id and gwn_client_secret.");
            }
        } else {
            PluginGdmsintegrationUtils::log("[{$ts}] GWN skipped — no credentials configured for entity {$entities_id}");
        }

        // ── GDMS API (UC/VoIP devices: phones, UCM, GCC) ──────────────────
        if (!empty($config['client_id']) && !empty($config['client_secret'])
            && !empty($config['username']) && !empty($config['password'])) {
            PluginGdmsintegrationUtils::log("[{$ts}] GDMS sync start — entity {$entities_id} — user: {$config['username']}");

            $gdmsToken = PluginGdmsintegrationAPI::gdmsGetToken($config);
            if ($gdmsToken !== false) {
                PluginGdmsintegrationUtils::log("[{$ts}] GDMS token obtained OK — username: {$config['username']} | API ID: {$config['client_id']} | expires in: {$gdmsToken['expires_in']}s");
                $config['access_token'] = $gdmsToken['access_token'];
                $gdmsDevices = PluginGdmsintegrationAPI::gdmsGetDevices($config);
                $gdmsCount   = count($gdmsDevices);
                PluginGdmsintegrationUtils::log("[{$ts}] GDMS API returned {$gdmsCount} device(s)");
                $synced = self::syncDeviceList($gdmsDevices, $entities_id, $config);
                PluginGdmsintegrationUtils::log("[{$ts}] GDMS sync complete — {$synced} device(s) processed");
                $total += $synced;
            } else {
                PluginGdmsintegrationUtils::log("[{$ts}] GDMS ERROR — could not obtain token for entity {$entities_id}. Check username, password, client_id and client_secret.");
            }
        } else {
            PluginGdmsintegrationUtils::log("[{$ts}] GDMS skipped — incomplete credentials for entity {$entities_id} (need username + password + client_id + client_secret)");
        }

        if ($total === 0 && empty($config['client_id']) && empty($config['gwn_client_id'])) {
            PluginGdmsintegrationUtils::log("[{$ts}] Nothing to sync — no API credentials configured for entity {$entities_id}");
            return 0;
        }

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
        [$mac_cache, $serial_cache, $name_cache] = self::buildAssetCaches($entities_id);

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
            // Do NOT htmlspecialchars() here — escape at output time only, not before DB write
            $name = $raw_name !== '' ? trim($raw_name) : 'GDMS Device';

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

            // Priority: 1) serial  2) MAC  3) name (normalized)
            $name_key = strtolower(trim(preg_replace('/\s+/', ' ', $name)));
            if (!empty($serial) && isset($serial_cache[$serial])) {
                $matched_row  = $serial_cache[$serial];
                $matched_type = $matched_row['_itemtype'];
                PluginGdmsintegrationUtils::log(
                    "  MATCH by serial '{$serial}' → {$matched_type} #{$matched_row['id']}"
                );
            } elseif (!empty($mac) && isset($mac_cache[$mac])) {
                $matched_row  = $mac_cache[$mac];
                $matched_type = $matched_row['_itemtype'];
                PluginGdmsintegrationUtils::log(
                    "  MATCH by MAC '{$mac}' → {$matched_type} #{$matched_row['id']}"
                );
            } elseif (!empty($name_key) && isset($name_cache[$name_key])) {
                $matched_row  = $name_cache[$name_key];
                $matched_type = $matched_row['_itemtype'];
                PluginGdmsintegrationUtils::log(
                    "  MATCH by name '{$name}' → {$matched_type} #{$matched_row['id']}"
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
            if ($matched_row === null) {
                PluginGdmsintegrationUtils::log(
                    "  NO MATCH for '{$name}' — tried: serial='{$serial}' mac='{$mac}' name_key='{$name_key}'"
                    . " — will CREATE new asset (delete the duplicate in GLPI if wrong)"
                );
            }
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
            // Store full cloud state for dashboard display
            // GDMS fields: publicIp, privateip, firmwareVersion, siteName, sn
            // GWN fields:  ip/ipv4, versionFirmware, networkName, upTime, sn (enriched)
            // Read previous status BEFORE updating state (order matters for transitions)
            $prevStatus = $state->getState($mac ?: $serial);
            PluginGdmsintegrationUtils::debug(
                "  STATE {$name}: prev=" . ($prevStatus ?? 'null') . " → new={$status}"
            );

            // For routers: fetch WAN port info and check for state changes
            $wan_ports_json = '';
            $network_id_val = (int)($d['networkId'] ?? 0);
            $is_gwn_router  = !empty($d['networkId']) && !empty($mac)
                              && ($matched_type === 'NetworkEquipment')
                              && ($is_online); // only fetch if online
            if ($is_gwn_router && !empty($config_data['gwn_client_id'])) {
                $port_data = PluginGdmsintegrationAPI::gwnGetRouterPortInfo(
                    $config_data, strtoupper(str_replace(':', '', $mac)), $network_id_val
                );
                if (!empty($port_data['portInfo'])) {
                    // Summarize WAN ports for storage and comparison
                    $wan_summary = [];
                    foreach ($port_data['portInfo'] as $port) {
                        $role        = (int)($port['role']     ?? 0);
                        $embedded    = $port['ipv4Info']          ?? [];
                        $wan_summary[] = [
                            'id'              => $port['portId']         ?? $port['silkScreenPort'] ?? '',
                            'name'            => $port['portName']        ?? '',
                            'silk'            => $port['silkScreenPort']  ?? '',
                            'role'            => $role,
                            'link'            => (int)($port['linkStatus'] ?? 0),
                            'speed'           => (int)($port['portSpeed']  ?? 0),
                            'type'            => ($port['type'] ?? 0) == 1 ? 'SFP' : 'GE',
                            'wanName'         => $port['wanName']          ?? '',
                            'connectDuration' => (int)($port['connectDuration'] ?? 0),
                            'ip'              => $embedded['ip4Address']   ?? '',
                            'connectStatus'   => isset($embedded['connectStatus'])
                                                  ? (int)$embedded['connectStatus'] : -1,
                        ];
                    }
                    // ipv4Info is embedded inside each port object — already extracted above
                    $wan_ports_json = json_encode($wan_summary);

                    // Check for WAN port state changes and create tickets
                    $prev_ports_json = $state->getWanPortsJson($mac);
                    if (!empty($prev_ports_json) && $glpi_id > 0) {
                        $prev_ports = json_decode($prev_ports_json, true) ?? [];
                        $prev_map   = array_column($prev_ports, null, 'id');
                        foreach ($wan_summary as $wp) {
                            // All port types generate tickets on link-down transition
                            $prev_wp = $prev_map[$wp['id']] ?? null;
                            if ($prev_wp && ($prev_wp['link'] == 1) && ($wp['link'] == 0)) {
                                // WAN link was up, now down → create ticket
                                self::createWanDownTicket(
                                    $name, $mac, $serial, $entities_id,
                                    $matched_type, $glpi_id,
                                    $wp['silk'] ?: $wp['name'],
                                    $wp['wanName'] ?? '',
                                    $d['networkName'] ?? $d['siteName'] ?? ''
                                );
                            }
                        }
                    }
                }
            }

            $state->saveStateWithNetwork(
                $mac ?: $serial,
                $status,
                $d['networkName'] ?? $d['siteName'] ?? '',
                $d['publicIp']        ?? $d['ip']    ?? $d['ipv4'] ?? '',
                $d['firmwareVersion'] ?? $d['versionFirmware'] ?? $d['firmware'] ?? '',
                (int)($d['upTime']    ?? 0),
                $d['sn']              ?? $d['SN']    ?? $serial,
                $network_id_val,
                $wan_ports_json,
                $d['apType']          ?? $d['deviceType'] ?? $d['type'] ?? '',
                $d['deviceName']      ?? $d['name'] ?? '',
                (int)($d['clients']   ?? 0),
                (int)($d['usage']     ?? 0),
                (int)($d['upload']    ?? 0),
                (int)($d['download']  ?? 0),
                (int)($d['channel']   ?? 0),
                (int)($d['channel5g'] ?? 0),
                isset($d['firstSeen']) ? date('Y-m-d H:i:s', (int)($d['firstSeen']/1000)) : null,
                isset($d['lastSeen'])  ? date('Y-m-d H:i:s', (int)($d['lastSeen']/1000))  : null,
                $d['ipv4']            ?? ''
            );

            // Ticket transitions: offline → create ticket, offline→online → resolve
            if ($glpi_id > 0) {
                // Also create ticket if device is offline and was ALREADY offline in DB
                // (catches devices that were offline before ticket logic was working)
                $alreadyOffline = ($prevStatus === 'offline' && $status === 'offline');
                if (($prevStatus === 'online' && $status === 'offline') || $alreadyOffline) {
                    self::createOfflineTicket(
                        $name,
                        $mac,
                        $serial,
                        $entities_id,
                        $matched_type,
                        $glpi_id,
                        $d['publicIp']        ?? $d['ip']    ?? $d['ipv4'] ?? '',
                        $d['networkName']     ?? $d['siteName'] ?? '',
                        $d['firmwareVersion'] ?? $d['versionFirmware'] ?? '',
                        (int)($d['upTime'] ?? 0)
                    );
                } elseif ($prevStatus === 'offline' && $status === 'online') {
                    self::resolveOfflineTicket($name, $matched_type, $glpi_id);
                }
            }
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
        string  $comment,  // kept for signature compat but NOT written to GLPI
        string  $model_field,
        int     $model_id
    ): int {
        /** @var CommonDBTM $obj */
        $obj = new $itemtype();

        if ($matched_row !== null) {
            // Existing asset — NEVER change name, comment, description, serial or uuid
            // if the field already has a value set by the user in GLPI.
            // Only fill in fields that are currently EMPTY (helping complete the record).
            // Model is matched-only, never forced.
            $glpi_id = (int) $matched_row['id'];
            $update  = ['id' => $glpi_id];

            // Only set uuid (MAC) if currently empty in GLPI
            if (!empty($mac) && empty(trim($matched_row['uuid'] ?? ''))) {
                $update['uuid'] = $mac;
            }
            // Only set serial if currently empty in GLPI
            if (!empty($serial) && empty(trim($matched_row['serial'] ?? ''))) {
                $update['serial'] = $serial;
            }
            // Model: only fill if not already set
            if ($model_id > 0 && empty((int)($matched_row[$model_field] ?? 0))) {
                $update[$model_field] = $model_id;
            }

            if (count($update) > 1) {
                $obj->update($update);
            }
            PluginGdmsintegrationUtils::log("  UPDATE {$itemtype} #{$glpi_id} — {$name} (MAC:{$mac} SN:{$serial})");
            return $glpi_id;
        }

        // New asset — set name from GDMS, no comment or description
        $add_data = [
            'name'        => $name,
            'entities_id' => $entities_id,
        ];
        if (!empty($mac))    { $add_data['uuid']   = $mac;    }
        if (!empty($serial)) { $add_data['serial'] = $serial; }
        if ($model_id > 0)   { $add_data[$model_field] = $model_id; }

        $new_id = (int) $obj->add($add_data);
        PluginGdmsintegrationUtils::log("  CREATE {$itemtype} #{$new_id} — {$name} (MAC:{$mac} SN:{$serial})");
        return $new_id;
    }

    // -----------------------------------------------------------------------
    // -----------------------------------------------------------------------
    // Public wrappers for webhook to trigger ticket logic
    // -----------------------------------------------------------------------
    public static function triggerOfflineTicket(
        string $name, string $mac, string $serial,
        int $entities_id, string $itemtype, int $glpi_id,
        string $ip = '', string $network = '', string $firmware = '', int $uptime_sec = 0
    ): void {
        self::createOfflineTicket($name, $mac, $serial, $entities_id, $itemtype, $glpi_id, $ip, $network, $firmware, $uptime_sec);
    }

    public static function triggerResolveTicket(string $name, string $itemtype, int $glpi_id): void {
        self::resolveOfflineTicket($name, $itemtype, $glpi_id);
    }

        // -----------------------------------------------------------------------
    // Open an offline incident ticket and link the asset as an element.
    // Skips creation if an open GDMS ticket already exists for this asset.
    // -----------------------------------------------------------------------
    private static function createWanDownTicket(
        string $deviceName,
        string $mac,
        string $serial,
        int    $entities_id,
        string $itemtype,
        int    $glpi_id,
        string $portSilk,
        string $wanName  = '',
        string $network  = ''
    ): void {
        // Duplicate guard: check for existing open WAN ticket for same port
        $existing = new Item_Ticket();
        $marker   = "[GDMS-WAN:{$portSilk}]";
        foreach ($existing->find(['itemtype' => $itemtype, 'items_id' => $glpi_id]) as $link) {
            $t = new Ticket();
            if ($t->getFromDB($link['tickets_id'])) {
                $open = [Ticket::INCOMING, Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING];
                if (in_array((int)$t->fields['status'], $open) && str_contains($t->fields['name'], $marker)) {
                    PluginGdmsintegrationUtils::log("GDMS: WAN ticket already open for {$deviceName} {$portSilk} — skipping");
                    return;
                }
            }
        }
        $now     = date('Y-m-d H:i:s');
        $wanLabel = $wanName ? " ({$wanName})" : '';
        $content = sprintf(
            "**%s** — WAN port **%s**%s went down.\n\n" .
            "| Field | Value |\n|---|---|\n" .
            "| MAC | %s |\n| Serial | %s |\n| Network | %s |\n| Detected | %s |\n\n" .
            "*Automatically generated by GDMS Integration.*",
            $deviceName, $portSilk, $wanLabel,
            strtoupper($mac), strtoupper($serial) ?: 'N/A', $network ?: 'N/A', $now
        );
        $ticket    = new Ticket();
        // Resolve tech assigned to this asset in GLPI
        $tech_id = 0;
        if ($glpi_id > 0) {
            $assetObj = new $itemtype();
            if ($assetObj->getFromDB($glpi_id)) {
                $tech_id = (int)($assetObj->fields['users_id_tech'] ?? 0);
            }
        }
        $cfg_req      = PluginGdmsintegrationConfig::getConfigByEntity($entities_id);
        $requester_id = (int)($cfg_req['ticket_requester_id'] ?? 0);

        $ticket_data = [
            'name'        => sprintf('%s [GDMS] %s — WAN %s%s down', '', $deviceName, $portSilk, $wanLabel),
            'content'     => $content,
            'entities_id' => $entities_id,
            'urgency'     => 4,
            'impact'      => 4,
            'priority'    => 4,
            'type'        => Ticket::INCIDENT_TYPE,
            'status'      => ($tech_id > 0) ? Ticket::ASSIGNED : Ticket::INCOMING,
        ];
        if ($tech_id > 0) {
            $ticket_data['_actors']['assign'] = [
                ['itemtype' => 'User', 'items_id' => $tech_id, 'use_notification' => 1],
            ];
        }
        if ($requester_id > 0) {
            $ticket_data['_actors']['requester'] = [
                ['itemtype' => 'User', 'items_id' => $requester_id, 'use_notification' => 0],
            ];
        }
        $ticket_id = (int) $ticket->add($ticket_data);
        if ($ticket_id > 0) {
            (new Item_Ticket())->add(['tickets_id' => $ticket_id, 'itemtype' => $itemtype, 'items_id' => $glpi_id, '_disablenotif' => true]);
            $tech_info = $tech_id > 0 ? " | assigned→user#{$tech_id}" : '';
            PluginGdmsintegrationUtils::log("GDMS: WAN ticket #{$ticket_id} → {$deviceName} port {$portSilk}{$tech_info}");
        }
    }

    private static function createOfflineTicket(
        string $name,
        string $mac,
        string $serial,
        int    $entities_id,
        string $itemtype,
        int    $glpi_id,
        string $ip          = '',
        string $network     = '',
        string $firmware    = '',
        int    $uptime_sec  = 0
    ): void {
        // Guard: skip if there's already an open ticket linked to this asset
        // (prevents duplicate tickets if device stays offline across multiple syncs)
        $existing = new Item_Ticket();
        $linked   = $existing->find([
            'itemtype'   => $itemtype,
            'items_id'   => $glpi_id,
        ]);
        foreach ($linked as $link_row) {
            $t = new Ticket();
            if ($t->getFromDB($link_row['tickets_id'])) {
                $open_statuses = [Ticket::INCOMING, Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING];
                // Check if title contains our GDMS offline marker
                if (in_array((int)$t->fields['status'], $open_statuses)
                    && str_contains($t->fields['name'], '[GDMS]')) {
                    PluginGdmsintegrationUtils::log(
                        "GDMS: Ticket already open for {$name} (#" . $link_row['tickets_id'] . ") — skipping"
                    );
                    return;
                }
            }
        }

        // Urgency/Impact: routers = high (4), switches/phones = medium (3)
        $isRouter  = ($itemtype === 'NetworkEquipment');
        $urgency   = $isRouter ? 4 : 3;
        $impact    = $isRouter ? 4 : 3;
        $priority  = $isRouter ? 4 : 3;

        // Build rich content
        $now     = date('Y-m-d H:i:s');
        $uptimeStr = $uptime_sec > 0
            ? sprintf('%dd %dh %dm', intdiv($uptime_sec, 86400), intdiv($uptime_sec % 86400, 3600), intdiv($uptime_sec % 3600, 60))
            : __('N/A', 'gdmsintegration');

        $content = sprintf(
            "**%s** %s

" .
            "| %s | %s |
|---|---|
" .
            "| **MAC** | %s |
" .
            "| **Serie** | %s |
" .
            "| **IP** | %s |
" .
            "| **Red** | %s |
" .
            "| **Firmware** | %s |
" .
            "| **Último tiempo activo** | %s |
" .
            "| **Detectado** | %s |

" .
            "*%s*",
            $name,
            __('went offline and is no longer reachable.', 'gdmsintegration'),
            __('Field', 'gdmsintegration'),
            __('Value', 'gdmsintegration'),
            strtoupper($mac),
            strtoupper($serial) ?: __('N/A', 'gdmsintegration'),
            $ip ?: __('N/A', 'gdmsintegration'),
            $network ?: __('N/A', 'gdmsintegration'),
            $firmware ?: __('N/A', 'gdmsintegration'),
            $uptimeStr,
            $now,
            __('This ticket was automatically generated by the GDMS Integration plugin.', 'gdmsintegration')
        );

        // Resolve tech assigned to this asset in GLPI
        $tech_id = 0;
        if ($glpi_id > 0) {
            $assetObj = new $itemtype();
            if ($assetObj->getFromDB($glpi_id)) {
                $tech_id = (int)($assetObj->fields['users_id_tech'] ?? 0);
            }
        }
        // Resolve configured requester (plugin config) — fallback 0 = cron/system user
        $cfg_req      = PluginGdmsintegrationConfig::getConfigByEntity($entities_id);
        $requester_id = (int)($cfg_req['ticket_requester_id'] ?? 0);

        $ticket_data = [
            'name'        => sprintf('[GDMS] %s: %s',
                __('Device Offline', 'gdmsintegration'),
                $name
            ),
            'content'     => $content,
            'entities_id' => $entities_id,
            'urgency'     => $urgency,
            'impact'      => $impact,
            'priority'    => $priority,
            'type'        => Ticket::INCIDENT_TYPE,
            'status'      => ($tech_id > 0) ? Ticket::ASSIGNED : Ticket::INCOMING,
        ];
        if ($tech_id > 0) {
            $ticket_data['_actors']['assign'] = [
                ['itemtype' => 'User', 'items_id' => $tech_id, 'use_notification' => 1],
            ];
        }
        if ($requester_id > 0) {
            $ticket_data['_actors']['requester'] = [
                ['itemtype' => 'User', 'items_id' => $requester_id, 'use_notification' => 0],
            ];
        }

        $ticket    = new Ticket();
        $ticket_id = (int) $ticket->add($ticket_data);

        if ($ticket_id > 0) {
            $item_ticket = new Item_Ticket();
            $item_ticket->add([
                'tickets_id'    => $ticket_id,
                'itemtype'      => $itemtype,
                'items_id'      => $glpi_id,
                '_disablenotif' => true,
            ]);
            $tech_info = $tech_id > 0 ? " | assigned→user#{$tech_id}" : '';
            PluginGdmsintegrationUtils::log(sprintf(
                "GDMS: Ticket #%d created → [GDMS] Device Offline: %s | linked to %s #%d%s",
                $ticket_id, $name, $itemtype, $glpi_id, $tech_info
            ));
        }
    }

    // -----------------------------------------------------------------------
    // Re-open or note recovery — add a followup to open offline ticket when
    // device comes back online and close it automatically.
    // -----------------------------------------------------------------------
    private static function resolveOfflineTicket(
        string $name,
        string $itemtype,
        int    $glpi_id
    ): void {
        $existing = new Item_Ticket();
        $linked   = $existing->find(['itemtype' => $itemtype, 'items_id' => $glpi_id]);
        foreach ($linked as $link_row) {
            $t = new Ticket();
            if (!$t->getFromDB($link_row['tickets_id'])) continue;
            $open = [Ticket::INCOMING, Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING];
            if (!in_array((int)$t->fields['status'], $open)) continue;
            if (!str_contains($t->fields['name'], '[GDMS]')) continue;

            // Add followup noting recovery
            $followup = new ITILFollowup();
            $followup->add([
                'itemtype'        => 'Ticket',
                'items_id'        => $link_row['tickets_id'],
                'content'         => sprintf(
                    __('✅ Device **%s** is back online as of %s. Ticket auto-resolved by GDMS Integration.', 'gdmsintegration'),
                    $name,
                    date('Y-m-d H:i:s')
                ),
                'is_private'      => 0,
                '_disablenotif'   => true,
            ]);

            // Close the ticket
            $t->update([
                'id'     => $link_row['tickets_id'],
                'status' => Ticket::SOLVED,
            ]);

            PluginGdmsintegrationUtils::log(
                "GDMS: Ticket #{$link_row['tickets_id']} auto-resolved — {$name} back online"
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
        $name_cache   = [];

        // No entity filter — plugin syncs devices globally (entity 0 + sub-entities)
        foreach (['NetworkEquipment', 'Phone'] as $itemtype) {
            $obj  = new $itemtype();
            $rows = $obj->find([], [], 0); // all entities, no limit

            foreach ($rows as $row) {
                $row['_itemtype'] = $itemtype;

                // Index by uuid (MAC address)
                if (!empty($row['uuid'])) {
                    $key = strtolower(trim($row['uuid']));
                    if (!isset($mac_cache[$key])) $mac_cache[$key] = $row;
                }
                // Also index by MAC-like patterns in otherserial or contact fields
                foreach (['serial', 'otherserial'] as $sf) {
                    if (!empty($row[$sf])) {
                        $key = strtolower(trim($row[$sf]));
                        if (!isset($serial_cache[$key])) $serial_cache[$key] = $row;
                    }
                }
                // Name index (normalized)
                if (!empty($row['name'])) {
                    $key = strtolower(trim(preg_replace('/\s+/', ' ', $row['name'])));
                    if (!isset($name_cache[$key])) $name_cache[$key] = $row;
                }
            }
        }

        return [$mac_cache, $serial_cache, $name_cache];
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
        // Use CommonDBTM::deleteByCriteria() — GLPI ORM, no raw SQL.
        // The second parameter (1) enables purge (permanent delete, not trashbin).
        $history = new PluginGdmsintegrationHistory();
        $history->deleteByCriteria(
            ['date' => ['<', date('Y-m-d H:i:s', strtotime('-60 days'))]],
            1
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
