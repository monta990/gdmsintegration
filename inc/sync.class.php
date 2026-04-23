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
        global $DB;
        $config = PluginGdmsintegrationConfig::getConfigByEntity($entities_id);
        $hasGwn  = !empty($config['gwn_client_id'])  && !empty($config['gwn_client_secret']);
        $hasGdms = !empty($config['client_id'])       && !empty($config['client_secret'])
                   && !empty($config['username'])     && !empty($config['password']);
        if (!$hasGwn && !$hasGdms) {
            return 0;
        }

        $total = 0;
        $ts    = gmdate('Y-m-d H:i:s');

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

        $seen_macs = []; // Collect all MACs returned by API this cycle
        // Track whether each configured API succeeded — if any fails we must NOT run
        // markRemovedDevicesOffline, because an empty $seen_macs would cause all stored
        // device state records to be deleted, triggering ghost WAN tickets on the next sync.
        $gwn_api_ok  = true; // assume OK when not configured
        $gdms_api_ok = true;

        // ── GWN API (networking: APs, Switches, Routers) ──────────────────
        if (!empty($config['gwn_client_id']) && !empty($config['gwn_client_secret'])) {
            $gwn_api_ok = false; // will be set true only on success
            PluginGdmsintegrationUtils::log("[{$ts}] GWN sync start — entity {$entities_id}");

            $gwnToken = PluginGdmsintegrationAPI::gwnGetToken($config);
            if ($gwnToken !== false) {
                PluginGdmsintegrationUtils::log("[{$ts}] GWN token obtained OK — API ID: {$config['gwn_client_id']}");
                $config['gwn_access_token'] = $gwnToken;
                $gwnDevices = PluginGdmsintegrationAPI::gwnGetDevices($config);
                if ($gwnDevices === false) {
                    // network/list API call failed — treat same as token failure
                    PluginGdmsintegrationUtils::log("[{$ts}] GWN ERROR — network list failed for entity {$entities_id}");
                } else {
                    $gwnCount   = count($gwnDevices);
                    $gwn_api_ok = true; // API responded — even 0 devices is a valid (empty account) state
                    PluginGdmsintegrationUtils::log("[{$ts}] GWN API returned {$gwnCount} device(s)");

                    // Inject firmware_latest from /upgrade/version — one call per unique networkId
                    if (!empty($gwnDevices)) {
                        $net_ids = array_unique(array_filter(array_column($gwnDevices, 'networkId')));
                        $fw_by_mac = [];
                        foreach ($net_ids as $nid) {
                            $fwList = PluginGdmsintegrationAPI::gwnGetFirmwareVersions($config, (int)$nid);
                            foreach ($fwList as $fw) {
                                $fmac = strtolower(str_replace(':', '', $fw['mac'] ?? ''));
                                if ($fmac && isset($fw['lastVersion'])) {
                                    $fw_by_mac[$fmac] = $fw['lastVersion'];
                                }
                            }
                        }
                        foreach ($gwnDevices as &$gdev) {
                            $dmac = strtolower(str_replace(':', '', $gdev['mac'] ?? ''));
                            $gdev['firmware_latest'] = $fw_by_mac[$dmac] ?? '';
                        }
                        unset($gdev);
                    }

                    $synced = self::syncDeviceList($gwnDevices, $entities_id, $seen_macs);
                    PluginGdmsintegrationUtils::log("[{$ts}] GWN sync complete — {$synced} device(s) processed");
                    $total += $synced;
                }
            } else {
                PluginGdmsintegrationUtils::log("[{$ts}] GWN ERROR — could not obtain token for entity {$entities_id}. Check gwn_client_id and gwn_client_secret.");
            }
        } else {
            PluginGdmsintegrationUtils::log("[{$ts}] GWN skipped — no credentials configured for entity {$entities_id}");
        }

        // ── GDMS API (UC/VoIP devices: phones, UCM, GCC) ──────────────────
        if (!empty($config['client_id']) && !empty($config['client_secret'])
            && !empty($config['username']) && !empty($config['password'])) {
            $gdms_api_ok = false; // will be set true only on success
            PluginGdmsintegrationUtils::log("[{$ts}] GDMS sync start — entity {$entities_id} — user: {$config['username']}");

            $gdmsToken = PluginGdmsintegrationAPI::gdmsGetToken($config);
            if ($gdmsToken !== false) {
                PluginGdmsintegrationUtils::log("[{$ts}] GDMS token obtained OK — username: {$config['username']} | API ID: {$config['client_id']} | expires in: {$gdmsToken['expires_in']}s");
                $config['access_token'] = $gdmsToken['access_token'];
                $gdmsDevices = PluginGdmsintegrationAPI::gdmsGetDevices($config);
                $gdmsCount   = count($gdmsDevices);
                PluginGdmsintegrationUtils::log("[{$ts}] GDMS API returned {$gdmsCount} device(s)");

$synced = self::syncDeviceList($gdmsDevices, $entities_id, $seen_macs);
                PluginGdmsintegrationUtils::log("[{$ts}] GDMS sync complete — {$synced} device(s) processed");
                $total += $synced;
                $gdms_api_ok = true;
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

        // Mark as offline any device in DB that the API no longer returns.
        // Guard: skip entirely if any configured API call failed this cycle.
        // A token failure or curl error would leave $seen_macs incomplete, causing
        // markRemovedDevicesOffline to delete state for devices it simply didn't hear
        // about — which triggers ghost WAN tickets on the very next successful sync.
        $removed = 0;
        if (!empty($seen_macs) && $gwn_api_ok && $gdms_api_ok) {
            $removed = self::markRemovedDevicesOffline($seen_macs, $ts, $entities_id);
        } elseif (!$gwn_api_ok || !$gdms_api_ok) {
            PluginGdmsintegrationUtils::log("[{$ts}] markRemovedDevicesOffline skipped — API error this cycle (gwn_ok=" . ($gwn_api_ok ? '1' : '0') . " gdms_ok=" . ($gdms_api_ok ? '1' : '0') . ")");
        }

        // Save last successful sync timestamp to config
        $DB->update(
            PluginGdmsintegrationConfig::getTable(),
            ['last_sync_at' => gmdate('Y-m-d H:i:s')],
            ['entities_id'  => $entities_id]
        );

        self::cleanupHistory();
        PluginGdmsintegrationUtils::log(
            "[{$ts}] Sync summary — entity={$entities_id} total={$total} removed={$removed}"
        );
        return $total;
    }

    // -----------------------------------------------------------------------
    // Core upsert loop (shared by both families)
    // -----------------------------------------------------------------------
    private static function syncDeviceList(
        array  $devices,
        int    $entities_id,
        array &$seen_macs = []
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

        // Load config so router/switch port API calls have credentials
        $config_data = PluginGdmsintegrationConfig::getConfigByEntity($entities_id);

        foreach ($devices as $d) {
            // Official API fields: deviceName, deviceType, mac, sn, status (1/0/-1)
            $mac    = strtolower(trim($d['mac'] ?? ''));
            $serial = strtolower(trim($d['sn']  ?? ''));
            if ($mac) { $seen_macs[] = $mac; }

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
                'date'   => gmdate('Y-m-d H:i:s'),
            ]);
            // Store full cloud state for dashboard display
            // GDMS fields: publicIp, privateip, firmwareVersion, siteName, sn
            // GWN fields:  ip/ipv4, versionFirmware, networkName, upTime, sn (enriched)
            // Read previous status BEFORE updating state (order matters for transitions)
            $prevStatus = $state->getState($mac ?: $serial);
            // Log state transition; note when persisting offline (no ticket generated)
            $stateNote = ($prevStatus === 'offline' && $status === 'offline') ? ' — no ticket (persists offline)' : '';
            PluginGdmsintegrationUtils::debug(
                "  STATE {$name}: prev=" . ($prevStatus ?? 'null') . " → new={$status}{$stateNote}"
            );

            // For routers/switches: fetch port info and check for state changes
            $wan_ports_json = '';
            $network_id_val = (int)($d['networkId'] ?? 0);
            $is_gwn_switch  = !empty($d['networkId']) && !empty($mac)
                              && ($matched_type === 'NetworkEquipment')
                              && $is_online
                              && preg_match('/^GWN78|^GSS/i', $gdms_model);
            $is_gwn_router  = !empty($d['networkId']) && !empty($mac)
                              && ($matched_type === 'NetworkEquipment')
                              && $is_online
                              && preg_match('/^GWN700[123]/i', $gdms_model); // explicit router prefix — APs/UCM excluded
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
                        $agg = $port['aggregate'] ?? [];
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
                            // WAN connection type (0=DHCP, 1=Static, 2=PPPoE, 3=PPTP, 4=L2TP)
                            'wanType'         => isset($embedded['wanType']) ? (int)$embedded['wanType']
                                                  : (isset($embedded['type']) ? (int)$embedded['type'] : -1),
                            // Gateway and DNS
                            'gateway'         => $embedded['gateway']    ?? '',
                            'gatewayStatus'   => isset($embedded['gatewayStatus']) ? (int)$embedded['gatewayStatus'] : -1,
                            'firstDns'        => $embedded['firstDns']   ?? $embedded['dns1'] ?? '',
                            'secondDns'       => $embedded['secondDns']  ?? $embedded['dns2'] ?? '',
                            // WAN port MAC address
                            'wamMac'          => $port['wamMac']          ?? $port['portMac'] ?? '',
                            // IPv6 on this WAN port
                            'portIpv6'        => $port['ipv6Info']['ipv6Address'] ?? $port['ipv6Address'] ?? '',
                            // isCombo port (shared SFP/GE)
                            'isCombo'         => !empty($port['isCombo']),
                            // Per-port traffic aggregate (v1.2.5)
                            'txBytes'         => (int)($agg['txBytes']     ?? 0),
                            'rxBytes'         => (int)($agg['rxBytes']     ?? 0),
                            // Packet counters
                            'txPackets'       => (int)($agg['txPackets']   ?? 0),
                            'rxPackets'       => (int)($agg['rxPackets']   ?? 0),
                        ];
                    }
                    // Check for WAN port state changes and create tickets
                    $prev_ports_json = $state->getWanPortsJson($mac);
                    if ($glpi_id > 0) {
                        $prev_ports = !empty($prev_ports_json) ? (json_decode($prev_ports_json, true) ?? []) : [];
                        $prev_map   = array_column($prev_ports, null, 'id');
                        $debounce_secs = max(0, (int)($config_data['wan_debounce_seconds'] ?? 300));
                        foreach ($wan_summary as &$wp) {
                            // Only process WAN ports (role=1). LAN ports (role=0) never get tickets.
                            if (($wp['role'] ?? 0) != 1) continue;

                            $prev_wp     = $prev_map[$wp['id']] ?? null;
                            $portLabel   = $wp['silk'] ?: $wp['name'];
                            $networkName = $d['networkName'] ?? $d['siteName'] ?? '';

                            // No previous state — first time we see this port.
                            if (!$prev_wp) {
                                if ($wp['link'] == 0) {
                                    // Physical link-down: no debounce — open immediately
                                    self::createWanDownTicket(
                                        $name, $mac, $serial, $entities_id,
                                        $matched_type, $glpi_id,
                                        $portLabel, $wp['wanName'] ?? '', $networkName,
                                        'link_down', ''
                                    );
                                } elseif ($wp['link'] == 1 && ($wp['connectStatus'] ?? -1) == 0) {
                                    if ($debounce_secs === 0) {
                                        self::createWanDownTicket(
                                            $name, $mac, $serial, $entities_id,
                                            $matched_type, $glpi_id,
                                            $portLabel, $wp['wanName'] ?? '', $networkName,
                                            'no_internet', ''
                                        );
                                    } else {
                                        $wp['no_inet_since'] = time();
                                        PluginGdmsintegrationUtils::log("GDMS: WAN no-internet debounce started — {$name} port {$portLabel} (first seen, waiting " . ($debounce_secs / 60) . " min)");
                                    }
                                }
                                continue;
                            }

                            // Case A: physical link went down — no debounce, unambiguous hardware event
                            if ($prev_wp['link'] == 1 && $wp['link'] == 0) {
                                $wp['no_inet_since'] = null; // clear any pending no-internet timer
                                $failoverNote = '';
                                foreach ($wan_summary as $other) {
                                    if ($other['id'] === $wp['id']) continue;
                                    if ($other['role'] != 1) continue;
                                    if ($other['link'] == 1 && ($other['connectStatus'] ?? -1) == 1) {
                                        $failoverNote = $other['wanName'] ?: ('Port ' . ($other['silk'] ?: $other['id']));
                                        break;
                                    }
                                }
                                self::createWanDownTicket(
                                    $name, $mac, $serial, $entities_id,
                                    $matched_type, $glpi_id,
                                    $portLabel, $wp['wanName'] ?? '', $networkName,
                                    'link_down', $failoverNote
                                );
                            }

                            // Case B: internet lost (link up but connectStatus flipped to 0) — start debounce
                            elseif ($prev_wp['link'] == 1 && $wp['link'] == 1
                                 && ($prev_wp['connectStatus'] ?? -1) == 1
                                 && ($wp['connectStatus'] ?? -1) == 0) {
                                if ($debounce_secs === 0) {
                                    $failoverNote = '';
                                    foreach ($wan_summary as $other) {
                                        if ($other['id'] === $wp['id']) continue;
                                        if ($other['role'] != 1) continue;
                                        if ($other['link'] == 1 && ($other['connectStatus'] ?? -1) == 1) {
                                            $failoverNote = $other['wanName'] ?: ('Port ' . ($other['silk'] ?: $other['id']));
                                            break;
                                        }
                                    }
                                    self::createWanDownTicket(
                                        $name, $mac, $serial, $entities_id,
                                        $matched_type, $glpi_id,
                                        $portLabel, $wp['wanName'] ?? '', $networkName,
                                        'no_internet', $failoverNote
                                    );
                                } else {
                                    $wp['no_inet_since'] = time();
                                    PluginGdmsintegrationUtils::log("GDMS: WAN no-internet debounce started — {$name} port {$portLabel} (waiting {$debounce_secs}s)");
                                }
                            }

                            // Case B2: internet still down — check if debounce window has expired
                            elseif ($wp['link'] == 1 && ($wp['connectStatus'] ?? -1) == 0
                                 && ($prev_wp['connectStatus'] ?? -1) == 0
                                 && isset($prev_wp['no_inet_since'])) {
                                $elapsed = time() - (int)$prev_wp['no_inet_since'];
                                if ($elapsed >= $debounce_secs) {
                                    // Debounce expired — open ticket; duplicate guard prevents re-open on subsequent syncs
                                    $failoverNote = '';
                                    foreach ($wan_summary as $other) {
                                        if ($other['id'] === $wp['id']) continue;
                                        if ($other['role'] != 1) continue;
                                        if ($other['link'] == 1 && ($other['connectStatus'] ?? -1) == 1) {
                                            $failoverNote = $other['wanName'] ?: ('Port ' . ($other['silk'] ?: $other['id']));
                                            break;
                                        }
                                    }
                                    self::createWanDownTicket(
                                        $name, $mac, $serial, $entities_id,
                                        $matched_type, $glpi_id,
                                        $portLabel, $wp['wanName'] ?? '', $networkName,
                                        'no_internet', $failoverNote
                                    );
                                } else {
                                    // Still within debounce window — carry forward the timer
                                    $wp['no_inet_since'] = $prev_wp['no_inet_since'];
                                    $remaining = (int)ceil($debounce_secs - $elapsed);
                                    PluginGdmsintegrationUtils::log("GDMS: WAN no-internet debounce pending — {$name} port {$portLabel} (~{$remaining}s remaining)");
                                }
                            }

                            // Case C: port recovered — clear debounce timer and resolve any open ticket
                            elseif ($wp['link'] == 1 && ($wp['connectStatus'] ?? -1) == 1) {
                                $wp['no_inet_since'] = null;
                                if (($prev_wp['link'] ?? 1) == 0) {
                                    self::resolveWanTicket($name, $portLabel, 'link_down', $matched_type, $glpi_id);
                                } elseif (($prev_wp['link'] ?? 0) == 1 && ($prev_wp['connectStatus'] ?? -1) == 0) {
                                    self::resolveWanTicket($name, $portLabel, 'no_internet', $matched_type, $glpi_id);
                                }
                            }
                        }
                        unset($wp);
                    }
                    // Encode after loop so no_inet_since timestamps are persisted in wan_ports_json
                    $wan_ports_json = json_encode($wan_summary);
                }
            }

            // For switches: fetch LAN port status and store in wan_ports_json
            if ($is_gwn_switch && !empty($config_data['gwn_client_id'])) {
                $raw_sw_ports = PluginGdmsintegrationAPI::gwnGetSwitchPortInfo(
                    $config_data, strtoupper(str_replace(':', '', $mac)), $network_id_val
                );
                if (!empty($raw_sw_ports)) {
                    $sw_summary = [];
                    foreach ($raw_sw_ports as $port) {
                        $sw_summary[] = [
                            'id'         => $port['portId']          ?? $port['silkScreenPort'] ?? '',
                            'name'       => $port['portName']         ?? '',
                            'silk'       => $port['silkScreenPort']   ?? '',
                            'role'       => 0, // LAN always for switches
                            'link'       => (int)($port['linkStatus'] ?? 0),
                            'speed'      => (int)($port['portSpeed']  ?? 0),
                            'type'       => ($port['type'] ?? 0) == 1 ? 'SFP' : 'GE',
                            'customName' => $port['portCustomName']   ?? '',
                            'desc'       => $port['portDesc']         ?? '',
                            'txBytes'    => (int)($port['aggregate']['txBytes'] ?? 0),
                            'rxBytes'    => (int)($port['aggregate']['rxBytes'] ?? 0),
                            'vlan'       => (int)($port['vlan']       ?? 0),
                        ];
                    }
                    $wan_ports_json = json_encode($sw_summary);
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
                isset($d['firstSeen']) ? gmdate('Y-m-d H:i:s', (int)($d['firstSeen']/1000)) : null,
                isset($d['lastSeen'])  ? gmdate('Y-m-d H:i:s', (int)($d['lastSeen']/1000))  : null,
                $d['ipv4']            ?? '',
                $d['firmware_latest'] ?? '',
                $d['sip_status']      ?? '',
                $entities_id,
                $d['ipv6']            ?? '',
                $d['privateIp']       ?? $d['privateip'] ?? '',
                $d['sip_extension']   ?? '',
                $d['location']        ?? $d['site']      ?? ''
            );

            // Ticket transitions: ONLY on true online→offline transition.
            // Devices removed from the cloud, moved between networks, or that stay offline
            // do NOT generate new tickets — only a genuine state change does.
            if ($glpi_id > 0) {
                if ($prevStatus === 'online' && $status === 'offline') {
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
        string $wanName      = '',
        string $network      = '',
        string $reason       = 'link_down', // 'link_down' | 'no_internet'
        string $failoverWan  = ''           // name of WAN that took over, if detected
    ): void {
        global $DB;

        // Advisory lock — prevents race condition when two concurrent syncs both
        // attempt to open a WAN ticket for the same port at the same time.
        $lock_name = 'gdmsinteg_' . substr(md5("wan_{$reason}_{$itemtype}_{$glpi_id}_{$portSilk}"), 0, 24);
        $locked    = 0;
        $lk_res = $DB->doQuery("SELECT GET_LOCK('{$lock_name}', 5) AS lk");
        if ($lk_res) {
            $lk_row = $DB->fetchAssoc($lk_res);
            $locked = (int)($lk_row['lk'] ?? 0);
        }
        if ($locked !== 1) {
            PluginGdmsintegrationUtils::log("GDMS: Lock busy for {$deviceName} WAN {$portSilk} — concurrent sync, skipping");
            return;
        }

        try {
        // Duplicate guard: check for existing open WAN ticket for same port
        $existing = new Item_Ticket();
        // Marker includes reason so link-down and no-internet are tracked as separate tickets
        $marker   = $reason === 'no_internet' ? "[GDMS-WAN-NOINET:{$portSilk}]" : "[GDMS-WAN:{$portSilk}]";
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
        $now       = gmdate('Y-m-d H:i:s');
        $wanLabel  = $wanName ? " ({$wanName})" : '';
        $isNoInet  = ($reason === 'no_internet');
        $eventDesc = $isNoInet
            ? __('WAN link is up but internet connectivity was lost (ISP / PPPoE failure).', 'gdmsintegration')
            : __('WAN port physical link went down.', 'gdmsintegration');
        $failoverLine = $failoverWan
            ? "\n| **Failover** | Active → {$failoverWan} |"
            : '';
        $content  = sprintf(
            "**%s** — WAN port **%s**%s\n%s\n\n" .
            "| Field | Value |\n|---|---|\n" .
            "| MAC | %s |\n| Serial | %s |\n| Network | %s |\n| Detected | %s |%s\n\n" .
            "*Automatically generated by GDMS Integration.*",
            $deviceName, $portSilk, $wanLabel, $eventDesc,
            strtoupper($mac), strtoupper($serial) ?: 'N/A', $network ?: 'N/A', $now, $failoverLine
        );
        $ticket  = new Ticket();
        // Resolve tech and use asset's own entity for the ticket location
        $tech_id       = 0;
        $asset_user_id = 0;
        $locations_id  = 0;
        if ($glpi_id > 0) {
            $assetObj = new $itemtype();
            if ($assetObj->getFromDB($glpi_id)) {
                $tech_id       = (int)($assetObj->fields['users_id_tech'] ?? 0);
                $asset_user_id = (int)($assetObj->fields['users_id']      ?? 0);
                $entities_id   = (int)($assetObj->fields['entities_id']   ?? $entities_id);
                $locations_id  = (int)($assetObj->fields['locations_id']  ?? 0);
            }
        }
        $cfg_req      = PluginGdmsintegrationConfig::getConfigByEntity($entities_id);
        // Asset's assigned user takes priority over the entity-level default requester
        $requester_id = $asset_user_id > 0
            ? $asset_user_id
            : (int)($cfg_req['ticket_requester_id'] ?? 0);
        $ticketSuffix = $isNoInet ? __('No Internet', 'gdmsintegration') : __('Link Down', 'gdmsintegration');

        $ticket_data = [
            'name'        => sprintf('[GDMS] %s — WAN %s%s: %s %s', $deviceName, $portSilk, $wanLabel, $ticketSuffix, $marker),
            'content'     => $content,
            'entities_id' => $entities_id,
            'urgency'     => 4,
            'impact'      => 4,
            'priority'    => 4,
            'type'        => Ticket::INCIDENT_TYPE,
            'status'      => ($tech_id > 0) ? Ticket::ASSIGNED : Ticket::INCOMING,
        ];
        if ($locations_id > 0) {
            $ticket_data['locations_id'] = $locations_id;
        }
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
            PluginGdmsintegrationUtils::log("GDMS: WAN ticket #{$ticket_id} → {$deviceName} port {$portSilk} | entity={$entities_id}{$tech_info}");
        }
        } finally {
            $DB->doQuery("SELECT RELEASE_LOCK('{$lock_name}')");
        }
    }


    // -----------------------------------------------------------------------
    // Auto-resolve an open WAN ticket when the port recovers.
    // -----------------------------------------------------------------------
    private static function resolveWanTicket(
        string $deviceName,
        string $portSilk,
        string $reason,
        string $itemtype,
        int    $glpi_id
    ): void {
        $marker   = $reason === 'no_internet'
            ? "[GDMS-WAN-NOINET:{$portSilk}]"
            : "[GDMS-WAN:{$portSilk}]";

        $existing = new Item_Ticket();
        foreach ($existing->find(['itemtype' => $itemtype, 'items_id' => $glpi_id]) as $link) {
            $t = new Ticket();
            if (!$t->getFromDB($link['tickets_id'])) continue;
            $open = [Ticket::INCOMING, Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING];
            if (!in_array((int)$t->fields['status'], $open)) continue;
            if (!str_contains($t->fields['name'], $marker)) continue;

            $followup = new ITILFollowup();
            $followup->add([
                'itemtype'      => 'Ticket',
                'items_id'      => $link['tickets_id'],
                'content'       => sprintf(
                    __('✅ WAN port **%s** on **%s** has recovered — link up and internet connectivity confirmed as of %s. Ticket auto-resolved by GDMS Integration.', 'gdmsintegration'),
                    $portSilk, $deviceName, gmdate('Y-m-d H:i:s')
                ),
                'is_private'    => 0,
                '_disablenotif' => true,
            ]);
            $t->update(['id' => $link['tickets_id'], 'status' => Ticket::SOLVED]);
            PluginGdmsintegrationUtils::log(
                "GDMS: WAN ticket #{$link['tickets_id']} auto-resolved — {$deviceName} port {$portSilk} recovered"
            );
        }
    }

    private static function createOfflineTicket(
        string $name,
        string $mac,
        string $serial,
        int    $entities_id,
        string $itemtype,
        int    $glpi_id,
        string $ip         = '',
        string $network    = '',
        string $firmware   = '',
        int    $uptime_sec = 0
    ): void {
        global $DB;

        // ── Idempotency: advisory lock prevents duplicate tickets when two
        //    concurrent syncs (e.g. two users refreshing the dashboard at the
        //    same time) both pass the open-ticket check simultaneously. ──────
        $lock_name = 'gdmsinteg_' . substr(md5("offline_{$itemtype}_{$glpi_id}"), 0, 24);
        $locked    = 0;
        $lk_res = $DB->doQuery("SELECT GET_LOCK('{$lock_name}', 5) AS lk");
        if ($lk_res) {
            $lk_row = $DB->fetchAssoc($lk_res);
            $locked = (int)($lk_row['lk'] ?? 0);
        }
        if ($locked !== 1) {
            PluginGdmsintegrationUtils::log(
                "GDMS: Lock busy for {$name} (offline ticket) — concurrent sync, skipping"
            );
            return;
        }

        try {
            // Guard: skip if there is already an open [GDMS] ticket linked to this asset.
            // This check runs inside the advisory lock so only one process reaches it at a time.
            $existing = new Item_Ticket();
            $linked   = $existing->find(['itemtype' => $itemtype, 'items_id' => $glpi_id]);
            foreach ($linked as $link_row) {
                $t = new Ticket();
                if ($t->getFromDB($link_row['tickets_id'])) {
                    $open_statuses = [Ticket::INCOMING, Ticket::ASSIGNED, Ticket::PLANNED, Ticket::WAITING];
                    if (in_array((int)$t->fields['status'], $open_statuses)
                        && str_contains($t->fields['name'], '[GDMS]')
                        && !str_contains($t->fields['name'], '— WAN ')) {
                        PluginGdmsintegrationUtils::log(
                            "GDMS: Ticket already open for {$name} (#" . $link_row['tickets_id'] . ") — skipping"
                        );
                        return;
                    }
                }
            }

            // Use the asset's own entity so the ticket lands in the correct location.
            $tech_id      = 0;
            $asset_user_id = 0;
            $locations_id = 0;
            if ($glpi_id > 0) {
                $assetObj = new $itemtype();
                if ($assetObj->getFromDB($glpi_id)) {
                    $tech_id       = (int)($assetObj->fields['users_id_tech'] ?? 0);
                    $asset_user_id = (int)($assetObj->fields['users_id']      ?? 0);
                    $entities_id   = (int)($assetObj->fields['entities_id']   ?? $entities_id);
                    $locations_id  = (int)($assetObj->fields['locations_id']  ?? 0);
                }
            }

            // Urgency/Impact: routers = high (4), switches / APs / phones = medium (3)
            $isRouter = false;
            if (!empty($mac)) {
                $devState = new PluginGdmsintegrationDevice();
                $stRows   = $devState->find(['mac' => $mac], [], 1);
                if (!empty($stRows)) {
                    $m = strtoupper(reset($stRows)['model'] ?? '');
                    $isRouter = (bool) preg_match('/^GWN700[123]/', $m);
                }
            }
            $urgency  = $isRouter ? 4 : 3;
            $impact   = $isRouter ? 4 : 3;
            $priority = $isRouter ? 4 : 3;

            $now       = gmdate('Y-m-d H:i:s');
            $uptimeStr = $uptime_sec > 0
                ? sprintf('%dd %dh %dm', intdiv($uptime_sec, 86400), intdiv($uptime_sec % 86400, 3600), intdiv($uptime_sec % 3600, 60))
                : __('N/A', 'gdmsintegration');

            $content = sprintf(
                "**%s** %s\n\n" .
                "| %s | %s |\n|---|---|\n" .
                "| **MAC** | %s |\n" .
                "| **%s** | %s |\n" .
                "| **%s** | %s |\n" .
                "| **%s** | %s |\n" .
                "| **%s** | %s |\n" .
                "| **%s** | %s |\n" .
                "| **%s** | %s |\n\n" .
                "*%s*",
                $name,
                __('went offline and is no longer reachable.', 'gdmsintegration'),
                __('Field', 'gdmsintegration'),
                __('Value', 'gdmsintegration'),
                strtoupper($mac),
                __('Serial', 'gdmsintegration'),      strtoupper($serial) ?: __('N/A', 'gdmsintegration'),
                __('IP', 'gdmsintegration'),          $ip       ?: __('N/A', 'gdmsintegration'),
                __('Network', 'gdmsintegration'),     $network  ?: __('N/A', 'gdmsintegration'),
                __('Firmware', 'gdmsintegration'),    $firmware ?: __('N/A', 'gdmsintegration'),
                __('Last uptime', 'gdmsintegration'), $uptimeStr,
                __('Detected', 'gdmsintegration'),    $now,
                __('This ticket was automatically generated by the GDMS Integration plugin.', 'gdmsintegration')
            );

            $cfg_req      = PluginGdmsintegrationConfig::getConfigByEntity($entities_id);
            // Asset's assigned user takes priority over the entity-level default requester
            $requester_id = $asset_user_id > 0
                ? $asset_user_id
                : (int)($cfg_req['ticket_requester_id'] ?? 0);

            $ticket_data = [
                'name'        => sprintf('[GDMS] %s: %s', __('Device Offline', 'gdmsintegration'), $name),
                'content'     => $content,
                'entities_id' => $entities_id,
                'urgency'     => $urgency,
                'impact'      => $impact,
                'priority'    => $priority,
                'type'        => Ticket::INCIDENT_TYPE,
                'status'      => ($tech_id > 0) ? Ticket::ASSIGNED : Ticket::INCOMING,
            ];
            if ($locations_id > 0) {
                $ticket_data['locations_id'] = $locations_id;
            }
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
                (new Item_Ticket())->add([
                    'tickets_id'    => $ticket_id,
                    'itemtype'      => $itemtype,
                    'items_id'      => $glpi_id,
                    '_disablenotif' => true,
                ]);
                $tech_info = $tech_id > 0 ? " | assigned→user#{$tech_id}" : '';
                PluginGdmsintegrationUtils::log(sprintf(
                    "GDMS: Ticket #%d created → [GDMS] Device Offline: %s | linked to %s #%d | entity=%d%s",
                    $ticket_id, $name, $itemtype, $glpi_id, $entities_id, $tech_info
                ));
            }
        } finally {
            // Always release the advisory lock, even if an exception was thrown
            $DB->doQuery("SELECT RELEASE_LOCK('{$lock_name}')");
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
            if (str_contains($t->fields['name'], '— WAN ')) continue; // skip WAN tickets

            // Add followup noting recovery
            $followup = new ITILFollowup();
            $followup->add([
                'itemtype'        => 'Ticket',
                'items_id'        => $link_row['tickets_id'],
                'content'         => sprintf(
                    __('✅ Device **%s** is back online as of %s. Ticket auto-resolved by GDMS Integration.', 'gdmsintegration'),
                    $name,
                    gmdate('Y-m-d H:i:s')
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
    // -----------------------------------------------------------------------
    // Mark devices no longer returned by the API as offline
    // -----------------------------------------------------------------------
    private static function markRemovedDevicesOffline(array $seen_macs, string $ts, int $entities_id = 0): int {
        global $DB;

        $device  = new PluginGdmsintegrationDevice();
        $history = new PluginGdmsintegrationHistory();

        $query = [
            'SELECT' => ['id', 'mac', 'cloud_name'],
            'FROM'   => PluginGdmsintegrationDevice::getTable(),
        ];
        // Filter by entity so that syncing entity A never purges entity B's device state.
        // Rows with entities_id=0 are legacy records (pre-1.2.8) or webhook-only entries;
        // include them only when syncing the root entity (0).
        if ($entities_id > 0) {
            $query['WHERE'] = ['entities_id' => $entities_id];
        }
        $all_rows = $DB->request($query);

        $removed = 0;
        foreach ($all_rows as $row) {
            $mac = strtolower($row['mac']);
            if (in_array($mac, $seen_macs, true)) {
                continue; // Device is still in the cloud account — nothing to do
            }

            // Device was not returned by the API this cycle — the user removed it
            // from their cloud account (or moved it between networks temporarily).
            // Action: delete the plugin state record and its history entirely.
            // No ticket is opened — this is a deliberate administrative action.
            // If the device is re-added to the cloud in the future, the next sync
            // will insert it fresh and re-link it to the existing GLPI asset by
            // serial / MAC match.
            $name = $row['cloud_name'] ?? $mac;
            PluginGdmsintegrationUtils::log(
                "  REMOVED {$mac} ({$name}): not returned by API — deleting plugin state (no ticket)"
            );

            $device->deleteByCriteria(['mac' => $mac], 1);   // purge=1
            $history->deleteByCriteria(['mac' => $mac], 1);  // purge=1
            $removed++;
        }
        return $removed;
    }

    public static function cleanupHistory(): void {
        // Use CommonDBTM::deleteByCriteria() — GLPI ORM, no raw SQL.
        // The second parameter (1) enables purge (permanent delete, not trashbin).
        $history = new PluginGdmsintegrationHistory();
        $history->deleteByCriteria(
            ['date' => ['<', gmdate('Y-m-d H:i:s', strtotime('-60 days'))]],
            1
        );
    }

    // -----------------------------------------------------------------------
    // Uptime / SLA helpers — used by dashboard
    // -----------------------------------------------------------------------

    /**
     * Batch version — single DB query for all MACs. Returns [mac => float].
     */
    public static function calculateUptimeBatch(array $macs): array {
        if (empty($macs)) return [];
        $history = new PluginGdmsintegrationHistory();
        $rows    = $history->find(['mac' => $macs]);
        $total   = [];
        $online  = [];
        foreach ($rows as $r) {
            $m = $r['mac'];
            $total[$m]  = ($total[$m]  ?? 0) + 1;
            if ($r['status'] === 'online') $online[$m] = ($online[$m] ?? 0) + 1;
        }
        $result = [];
        foreach ($macs as $mac) {
            $t = $total[$mac] ?? 0;
            $result[$mac] = $t > 0 ? round(($online[$mac] ?? 0) / $t * 100, 2) : 0.0;
        }
        return $result;
    }

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

    public static function slaLabel(float $uptime): string {
        if ($uptime >= 99.9) return __('Gold',     'gdmsintegration');
        if ($uptime >= 99.0) return __('Silver',   'gdmsintegration');
        if ($uptime >= 95.0) return __('Bronze',   'gdmsintegration');
        return __('Critical', 'gdmsintegration');
    }

    public static function calculateSLA(string $mac): string {
        return self::slaLabel(self::calculateUptime($mac));
    }
}
