<?php

namespace GlpiPlugin\Gdmsintegration;
/**
 * GDMS Integration — \GlpiPlugin\Gdmsintegration\Sync
 *
 * Handles synchronization of two GDMS device families into GLPI:
 *   1. Networking  (GWN series) → NetworkEquipment
 *   2. UC/VoIP     (GRP/GXP/GXV/WP/HT phones, UCM/GCC PBX)
 *        → \Phone            if device is a phone/endpoint
 *        → \NetworkEquipment if device is a PBX/appliance
 *        → Respects the itemtype the user already chose in GLPI
 *
 * Fields written (same for every itemtype):
 *   uuid   ← MAC address from GDMS
 *   serial ← serial number (sn / serial_number / serial) from GDMS
 *   name   ← device_name set in GDMS portal, fallback to model string
 *   <model_id_field> ← matched against existing GLPI models (never creates new)
 *   comment ← "GDMS:Online" / "GDMS:Offline"
 */
class Sync extends \CommonGLPI {

    // -----------------------------------------------------------------------
    // GLPI Cron entry point — cronSyncDevices
    // -----------------------------------------------------------------------
    public static function cronSyncDevices(\CronTask $task): int {
        // glpi_entities has no is_deleted column — use \Entity ORM to get all active entities
        $entity_obj = new \Entity();
        $entities   = $entity_obj->find();
        $total    = 0;

        foreach ($entities as $entity) {
            $total += self::syncEntity((int) $entity['id']);
        }

        $task->addVolume($total);
        \GlpiPlugin\Gdmsintegration\Utils::log(
            sprintf("GDMS Sync completed — %d device(s) processed", $total)
        );

        return ($total > 0) ? 1 : 0;
    }

    public static function cronInfo(string $name): array {
        return match ($name) {
            'SyncDevices', 'syncDevices' => [
                'description' => __('Synchronize GDMS cloud devices with GLPI', 'gdmsintegration'),
            ],
            default => [],
        };
    }

    // -----------------------------------------------------------------------
    // Per-entity sync — called from cron
    // -----------------------------------------------------------------------
    public static function syncEntity(int $entities_id, ?string $source = null): int {
        global $DB;
        $config = \GlpiPlugin\Gdmsintegration\Config::getConfigByEntity($entities_id);
        $hasGwn  = !empty($config['gwn_client_id'])  && !empty($config['gwn_client_secret']);
        $hasGdms = !empty($config['client_id'])       && !empty($config['client_secret'])
                   && !empty($config['username'])     && !empty($config['password']);
        if (!$hasGwn && !$hasGdms) {
            return 0;
        }

        // One synchronization per entity at a time. Prevents cron/button/auto-refresh races.
        $syncLock = 'gdmsinteg_sync_' . $entities_id;
        $syncToken = \GlpiPlugin\Gdmsintegration\Utils::acquireLock($syncLock, 900);
        if ($syncToken === null) {
            \GlpiPlugin\Gdmsintegration\Utils::warning("Sync skipped — another synchronization is already running for entity {$entities_id}");
            return 0;
        }
        $syncStarted = microtime(true);
        $total = 0;
        $ts    = gmdate('Y-m-d H:i:s');

        // Identify caller without coupling synchronization logic to HTTP superglobals.
        if (defined('GLPI_CRON') || php_sapi_name() === 'cli') {
            $caller = 'cron';
        } elseif ($source === 'button') {
            $caller = 'ajax-button';
        } elseif ($source === 'auto-refresh') {
            $caller = 'auto-refresh';
        } elseif ($source !== null && $source !== '') {
            $caller = $source;
        } else {
            $caller = 'system';
        }
        \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] syncEntity called — source={$caller} entity={$entities_id}");

        $seen_macs = []; // Collect all MACs returned by API this cycle
        // Track whether each configured API succeeded — if any fails we must NOT run
        // markRemovedDevicesOffline, because an empty $seen_macs would cause all stored
        // device state records to be deleted, triggering ghost WAN tickets on the next sync.
        $gwn_api_ok  = true; // assume OK when not configured
        $gdms_api_ok = true;

        // ── GWN API (networking: APs, Switches, Routers) ──────────────────
        if (!empty($config['gwn_client_id']) && !empty($config['gwn_client_secret'])) {
            $gwn_api_ok = false; // will be set true only on success
            \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GWN sync start — entity {$entities_id}");

            $gwnToken = \GlpiPlugin\Gdmsintegration\API::gwnGetToken($config);
            if ($gwnToken !== false) {
                \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GWN token obtained OK — API ID: {$config['gwn_client_id']}");
                $config['gwn_access_token'] = $gwnToken;
                $gwn_list_complete = false;
                $gwnDevices = \GlpiPlugin\Gdmsintegration\API::gwnGetDevices($config, $gwn_list_complete);
                if ($gwnDevices === false) {
                    // network/list API call failed — treat same as token failure
                    \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GWN ERROR — network list failed for entity {$entities_id}");
                } else {
                    $gwnCount   = count($gwnDevices);
                    $gwn_api_ok = $gwn_list_complete; // only a complete list is authoritative for removal detection
                    \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GWN API returned {$gwnCount} device(s)");
                    if (!$gwn_list_complete) {
                        \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GWN list incomplete — returned devices will be updated, but removal detection is disabled for this cycle.");
                    }

                    // Inject firmware_latest — all networks fetched in parallel via curl_multi.
                    if (!empty($gwnDevices)) {
                        $net_ids   = array_unique(array_filter(array_column($gwnDevices, 'networkId')));
                        $fw_by_net = \GlpiPlugin\Gdmsintegration\API::gwnGetFirmwareVersionsBatch($config, array_map('intval', $net_ids));
                        $fw_errors = \GlpiPlugin\Gdmsintegration\API::gwnGetLastFirmwareErrors();
                        $fw_by_mac = [];
                        foreach ($fw_by_net as $fwList) {
                            foreach ($fwList as $fw) {
                                $fmac = strtolower(str_replace(':', '', $fw['mac'] ?? ''));
                                if ($fmac && !empty($fw['stableVersion'])) {
                                    $fw_by_mac[$fmac] = $fw['stableVersion'];
                                }
                            }
                        }
                        foreach ($gwnDevices as &$gdev) {
                            $dmac = strtolower(str_replace(':', '', $gdev['mac'] ?? ''));
                            $nid = (int)($gdev['networkId'] ?? $gdev['network_id'] ?? 0);
                            if (isset($fw_errors[$nid])) {
                                // Preserve the last known firmware_latest when GWN could not
                                // authoritatively answer upgrade/version for this network.
                                $gdev['_preserve_firmware_latest'] = true;
                            } else {
                                // A successful GWN response with only beta/RC/etc. means
                                // there is no newer stable firmware to advertise. Keep the
                                // current installed stable version as the compliance baseline
                                // instead of persisting the pre-release reference.
                                $gwnStable = $fw_by_mac[$dmac] ?? '';
                                if ($gwnStable === '') {
                                    $gwnStable = trim((string)($gdev['versionFirmware'] ?? $gdev['firmwareVersion'] ?? $gdev['firmware'] ?? ''));
                                }
                                $gdev['firmware_latest'] = $gwnStable;
                            }
                        }
                        unset($gdev);
                    }

                    // 1.6.0: ingest actionable cloud alerts into GLPI tickets.
                    if (!empty($net_ids)) {
                        self::syncGwnAlertTickets($config, $entities_id, array_map('intval', $net_ids), $gwnDevices);
                    }

                    $synced = self::syncDeviceList($gwnDevices, $entities_id, $seen_macs);
                    \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GWN sync complete — {$synced} device(s) processed");
                    $total += $synced;
                }
            } else {
                \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GWN ERROR — could not obtain token for entity {$entities_id}. Check gwn_client_id and gwn_client_secret.");
            }
        } else {
            \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GWN skipped — no credentials configured for entity {$entities_id}");
        }

        // ── GDMS API (UC/VoIP devices: phones, UCM, GCC) ──────────────────
        if (!empty($config['client_id']) && !empty($config['client_secret'])
            && !empty($config['username']) && !empty($config['password'])) {
            $gdms_api_ok = false; // will be set true only on success
            \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GDMS sync start — entity {$entities_id} — user: {$config['username']}");

            $gdmsToken = \GlpiPlugin\Gdmsintegration\API::gdmsGetToken($config);
            if ($gdmsToken !== false) {
                \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GDMS token obtained OK — username: {$config['username']} | API ID: {$config['client_id']} | expires in: {$gdmsToken['expires_in']}s");
                $config['access_token'] = $gdmsToken['access_token'];
                $gdms_list_complete = false;
                $gdmsDevices = \GlpiPlugin\Gdmsintegration\API::gdmsGetDevices($config, $gdms_list_complete);
                $gdmsCount   = count($gdmsDevices);
                \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GDMS API returned {$gdmsCount} device(s)");

$synced = self::syncDeviceList($gdmsDevices, $entities_id, $seen_macs);
                \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GDMS sync complete — {$synced} device(s) processed");
                $total += $synced;
                // Partial pages are still useful for updating devices we did receive,
                // but they are not authoritative for removal/offline inference.
                $gdms_api_ok = $gdms_list_complete;
                if (!$gdms_list_complete) {
                    \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GDMS device list incomplete — processed {$gdmsCount} returned device(s); removal detection disabled for this cycle.");
                }
            } else {
                \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GDMS ERROR — could not obtain token for entity {$entities_id}. Check username, password, client_id and client_secret.");
            }
        } else {
            \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] GDMS skipped — incomplete credentials for entity {$entities_id} (need username + password + client_id + client_secret)");
        }

        if ($total === 0 && empty($config['client_id']) && empty($config['gwn_client_id'])) {
            \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] Nothing to sync — no API credentials configured for entity {$entities_id}");
            \GlpiPlugin\Gdmsintegration\Utils::releaseLock($syncLock, $syncToken);
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
            \GlpiPlugin\Gdmsintegration\Utils::log("[{$ts}] markRemovedDevicesOffline skipped — API error this cycle (gwn_ok=" . ($gwn_api_ok ? '1' : '0') . " gdms_ok=" . ($gdms_api_ok ? '1' : '0') . ")");
        }

        // Save last successful sync timestamp to config
        $durationMs = (int)round((microtime(true) - $syncStarted) * 1000);
        $syncStatus = ($gwn_api_ok && $gdms_api_ok) ? 'ok' : 'partial';
        $DB->update(
            \GlpiPlugin\Gdmsintegration\Config::getTable(),
            ['last_sync_at' => gmdate('Y-m-d H:i:s'), 'last_sync_duration_ms' => $durationMs, 'last_sync_devices' => $total, 'last_sync_status' => $syncStatus],
            ['entities_id'  => $entities_id]
        );

        self::cleanupHistory();
        \GlpiPlugin\Gdmsintegration\Utils::cleanupOperationalHistory($entities_id, (int)($config['history_retention_days'] ?? 90));
        \GlpiPlugin\Gdmsintegration\Utils::log(
            "[{$ts}] Sync summary — entity={$entities_id} total={$total} removed={$removed}"
        );
        \GlpiPlugin\Gdmsintegration\Utils::releaseLock($syncLock, $syncToken);
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
        $ne_model_cache    = self::buildModelCache(\NetworkEquipmentModel::class);
        $phone_model_cache = self::buildModelCache(\PhoneModel::class);

        // ---------------------------------------------------------------
        // Pre-load existing GLPI assets by UUID (MAC) and serial.
        // We search BOTH \NetworkEquipment AND \Phone so we can respect
        // the itemtype the user already assigned.
        // Structure: [ 'MAC/serial' => ['itemtype' => ..., 'id' => ..., ...] ]
        // ---------------------------------------------------------------
        [$mac_cache, $serial_cache, $name_cache] = self::buildAssetCaches($entities_id);

        $state   = new \GlpiPlugin\Gdmsintegration\Device();
        $history = new \GlpiPlugin\Gdmsintegration\History();
        $link    = new \GlpiPlugin\Gdmsintegration\Link();

        // Prime device state cache — eliminates one DB query per device for getState()
        // and one for saveStateWithNetwork(). One bulk load instead of 2N queries.
        \GlpiPlugin\Gdmsintegration\Device::primeCache();

        // Pre-load existing topology links — eliminates one find() per device.
        $existing_links = [];
        foreach ($link->find() as $_lrow) {
            $existing_links[strtolower($_lrow['source_mac']) . '|' . strtolower($_lrow['target_mac'])] = true;
        }

        // History rows collected for bulk insert at the end of the loop.
        $history_batch = [];

        // Load config so router/switch port API calls have credentials
        $config_data = \GlpiPlugin\Gdmsintegration\Config::getConfigByEntity($entities_id);

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
                \GlpiPlugin\Gdmsintegration\Utils::log(
                    "  MATCH by serial '{$serial}' → {$matched_type} #{$matched_row['id']}"
                );
            } elseif (!empty($mac) && isset($mac_cache[$mac])) {
                $matched_row  = $mac_cache[$mac];
                $matched_type = $matched_row['_itemtype'];
                \GlpiPlugin\Gdmsintegration\Utils::log(
                    "  MATCH by MAC '{$mac}' → {$matched_type} #{$matched_row['id']}"
                );
            } elseif (!empty($name_key) && isset($name_cache[$name_key])) {
                // Name is intentionally a last-resort key. Never merge two cloud
                // devices merely because their display names match when GLPI already
                // carries a conflicting strong identifier.
                $candidate = $name_cache[$name_key];
                $candidateSerial = strtolower(trim((string)($candidate['serial'] ?? '')));
                $candidateOther  = strtolower(trim((string)($candidate['otherserial'] ?? '')));
                $candidateMac    = strtolower(trim((string)($candidate['uuid'] ?? '')));
                $serialConflict = $serial !== '' && $candidateSerial !== '' && $candidateSerial !== $serial
                                  && $candidateOther !== $serial;
                $macConflict = $mac !== '' && $candidateMac !== ''
                               && preg_replace('/[^0-9a-f]/', '', $candidateMac) !== preg_replace('/[^0-9a-f]/', '', $mac);
                if (!$serialConflict && !$macConflict) {
                    $matched_row  = $candidate;
                    $matched_type = $matched_row['_itemtype'];
                    \GlpiPlugin\Gdmsintegration\Utils::log(
                        "  MATCH by name '{$name}' → {$matched_type} #{$matched_row['id']} (no identifier conflict)"
                    );
                } else {
                    \GlpiPlugin\Gdmsintegration\Utils::log(
                        "  NAME match rejected for '{$name}' — existing asset has a different serial/MAC; creating or matching by strong identifier instead"
                    );
                }
            }

            if ($matched_type === null) {
                // New device — classify by model prefix
                // GWN series (null from classifier) → NetworkEquipment
                // GRP/GXP/WP/HT/etc. → Phone
                // UCM/GCC/etc. → \NetworkEquipment
                $classified   = \GlpiPlugin\Gdmsintegration\API::classifyModel($gdms_model);
                $matched_type = $classified ?? 'NetworkEquipment';
            }

            // Prefer GLPI asset name for ticket subjects; fall back to GDMS name
            $ticket_name = (!empty($matched_row['name'])) ? $matched_row['name'] : $name;

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
                \GlpiPlugin\Gdmsintegration\Utils::log(
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

            // Collect history for bulk insert at end of loop
            $history_batch[] = [
                'mac'    => $mac ?: $serial,
                'status' => $status,
                'date'   => gmdate('Y-m-d H:i:s'),
            ];
            // Store full cloud state for dashboard display
            // GDMS fields: publicIp, privateip, firmwareVersion, siteName, sn
            // GWN fields:  ip/ipv4, versionFirmware, networkName, upTime, sn (enriched)
            // Read previous status BEFORE updating state (order matters for transitions)
            $prevStatus = $state->getState($mac ?: $serial);
            // Log state transition; note when persisting offline (no ticket generated)
            $stateNote = '';
            \GlpiPlugin\Gdmsintegration\Utils::debug(
                "  STATE {$name}: prev=" . ($prevStatus ?? 'null') . " → new={$status}{$stateNote}"
            );

            // For routers/switches: fetch port info and check for state changes.
            // When a router is offline, GWN does not return portInfo. Preserve the
            // last known port inventory and mark its WAN ports as physically down so
            // the dashboard can still count those WANs as down instead of dropping
            // them from the totals. No WAN ticket is generated here; the device
            // offline ticket remains the single incident for the offline router.
            $wan_ports_json = '';
            $network_id_val = (int)($d['networkId'] ?? 0);
            if (!$is_online
                && $matched_type === 'NetworkEquipment'
                && preg_match('/^GWN700[123]/i', $gdms_model)
                && !empty($mac)
            ) {
                $previous_wan_ports_json = $state->getWanPortsJson($mac);
                if ($previous_wan_ports_json !== '') {
                    $previous_ports = json_decode($previous_wan_ports_json, true);
                    if (is_array($previous_ports)) {
                        foreach ($previous_ports as &$previous_port) {
                            if ((int)($previous_port['role'] ?? 0) !== 1) continue;
                            $previous_port['link'] = 0;
                            $previous_port['connectStatus'] = 0;
                            $previous_port['connectDuration'] = 0;
                            $previous_port['no_inet_since'] = null;
                        }
                        unset($previous_port);
                        $wan_ports_json = json_encode($previous_ports);
                    }
                }
            }
            $is_gwn_switch  = !empty($d['networkId']) && !empty($mac)
                              && ($matched_type === 'NetworkEquipment')
                              && $is_online
                              && preg_match('/^GWN78|^GSS/i', $gdms_model);
            $is_gwn_router  = !empty($d['networkId']) && !empty($mac)
                              && ($matched_type === 'NetworkEquipment')
                              && preg_match('/^GWN700[123]/i', $gdms_model); // explicit router prefix — APs/UCM excluded; port inventory is useful even while offline
            if ($is_gwn_router && !empty($config_data['gwn_client_id'])) {
                $port_data = \GlpiPlugin\Gdmsintegration\API::gwnGetRouterPortInfo(
                    $config_data, strtoupper(str_replace(':', '', $mac)), $network_id_val
                );
                if (!empty($port_data['portInfo'])) {
                    // Summarize WAN ports for storage and comparison
                    $wan_summary = [];
                    foreach ($port_data['portInfo'] as $port) {
                        $role        = (int)($port['role']     ?? 0);
                        $embedded    = is_array($port['ipv4Info'] ?? null) ? $port['ipv4Info'] : [];
                        $agg = $port['aggregate'] ?? [];
                        $wan_summary[] = [
                            'id'              => $port['portId']         ?? $port['silkScreenPort'] ?? '',
                            'name'            => $port['portName']        ?? '',
                            'silk'            => $port['silkScreenPort']  ?? '',
                            // GWN normally exposes role=1 for WAN. If a response omits
                            // the role, use WAN-specific identifiers only. Do NOT use the
                            // presence of ipv4Info/connectStatus/wanType/gateway because
                            // those fields are not sufficient to distinguish LAN records.
                            'role'            => (
                                $role === 1
                                || trim((string)($port['wanName'] ?? '')) !== ''
                                || array_key_exists('wanId', $port)
                            ) ? 1 : 0,
                            'link'            => (int)($port['linkStatus'] ?? 0),
                            'speed'           => (int)($port['portSpeed']  ?? 0),
                            'type'            => ($port['type'] ?? 0) == 1 ? 'SFP' : 'GE',
                            'wanName'         => $port['wanName']          ?? '',
                            'wanId'           => $port['wanId']            ?? '',
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
                    // When the router itself is offline, keep the WAN inventory but force
                    // its known WAN links to the red/down state. No WAN ticket processing
                    // occurs while offline; the device-level offline ticket is the only incident.
                    if (!$is_online) {
                        foreach ($wan_summary as &$offline_wp) {
                            if ((int)($offline_wp['role'] ?? 0) !== 1) continue;
                            $offline_wp['link'] = 0;
                            $offline_wp['connectStatus'] = 0;
                            $offline_wp['connectDuration'] = 0;
                            $offline_wp['no_inet_since'] = null;
                        }
                        unset($offline_wp);
                    }
                    // Check for WAN port state changes and create tickets
                    $prev_ports_json = $state->getWanPortsJson($mac);
                    if ($glpi_id > 0 && $is_online) {
                        $prev_ports = !empty($prev_ports_json) ? (json_decode($prev_ports_json, true) ?? []) : [];
                        $prev_map   = array_column($prev_ports, null, 'id');
                        $device_recovered = ($prevStatus === 'offline');
                        $debounce_secs       = max(0, (int)($config_data['wan_debounce_seconds'] ?? 300));
                        $wan_tickets_enabled = (int)($config_data['wan_tickets_enabled'] ?? 1) === 1;
                        foreach ($wan_summary as &$wp) {
                            // Only process WAN ports (role=1). LAN ports (role=0) never get tickets.
                            if (($wp['role'] ?? 0) != 1) continue;

                            $prev_wp     = $prev_map[$wp['id']] ?? null;
                            $portLabel   = $wp['silk'] ?: $wp['name'];
                            $networkName = $d['networkName'] ?? $d['siteName'] ?? '';

                            // If the router itself was offline, its previous WAN snapshot was
                            // intentionally marked down. On recovery, treat the first live WAN
                            // observation as a fresh state: a down WAN gets a ticket immediately,
                            // while a link-up/no-internet WAN starts the configured debounce.
                            if ($device_recovered) {
                                $wp['no_inet_since'] = null;
                                if ($wp['link'] == 0) {
                                    if ($wan_tickets_enabled) {
                                        self::createWanDownTicket(
                                            $ticket_name, $mac, $serial, $entities_id,
                                            $matched_type, $glpi_id,
                                            $portLabel, $wp['wanName'] ?? '', $networkName,
                                            'link_down', ''
                                        );
                                    }
                                } elseif (($wp['connectStatus'] ?? -1) == 0) {
                                    if ($debounce_secs === 0) {
                                        if ($wan_tickets_enabled) {
                                            self::createWanDownTicket(
                                                $ticket_name, $mac, $serial, $entities_id,
                                                $matched_type, $glpi_id,
                                                $portLabel, $wp['wanName'] ?? '', $networkName,
                                                'no_internet', ''
                                            );
                                        }
                                    } else {
                                        $wp['no_inet_since'] = time();
                                        \GlpiPlugin\Gdmsintegration\Utils::log(
                                            "GDMS: WAN no-internet debounce started after device recovery — {$name} port {$portLabel} (waiting " . ($debounce_secs / 60) . " min)"
                                        );
                                    }
                                }
                                continue;
                            }

                            // No previous state — first time we see this port.
                            if (!$prev_wp) {
                                if ($wp['link'] == 0) {
                                    // Physical link-down: no debounce — open immediately
                                    if ($wan_tickets_enabled) {
                                    self::createWanDownTicket(
                                        $ticket_name, $mac, $serial, $entities_id,
                                        $matched_type, $glpi_id,
                                        $portLabel, $wp['wanName'] ?? '', $networkName,
                                        'link_down', ''
                                    );
                                    }
                                } elseif ($wp['link'] == 1 && ($wp['connectStatus'] ?? -1) == 0) {
                                    if ($debounce_secs === 0) {
                                        if ($wan_tickets_enabled) {
                                        self::createWanDownTicket(
                                            $ticket_name, $mac, $serial, $entities_id,
                                            $matched_type, $glpi_id,
                                            $portLabel, $wp['wanName'] ?? '', $networkName,
                                            'no_internet', ''
                                        );
                                        }
                                    } else {
                                        $wp['no_inet_since'] = time();
                                        \GlpiPlugin\Gdmsintegration\Utils::log("GDMS: WAN no-internet debounce started — {$name} port {$portLabel} (first seen, waiting " . ($debounce_secs / 60) . " min)");
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
                                if ($wan_tickets_enabled) {
                                self::createWanDownTicket(
                                    $ticket_name, $mac, $serial, $entities_id,
                                    $matched_type, $glpi_id,
                                    $portLabel, $wp['wanName'] ?? '', $networkName,
                                    'link_down', $failoverNote
                                );
                                }
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
                                    if ($wan_tickets_enabled) {
                                    self::createWanDownTicket(
                                        $ticket_name, $mac, $serial, $entities_id,
                                        $matched_type, $glpi_id,
                                        $portLabel, $wp['wanName'] ?? '', $networkName,
                                        'no_internet', $failoverNote
                                    );
                                    }
                                } else {
                                    $wp['no_inet_since'] = time();
                                    \GlpiPlugin\Gdmsintegration\Utils::log("GDMS: WAN no-internet debounce started — {$name} port {$portLabel} (waiting {$debounce_secs}s)");
                                }
                            }

                            // Case B2: internet still down — check if debounce window has expired
                            elseif ($wp['link'] == 1 && ($wp['connectStatus'] ?? -1) == 0
                                 && ($prev_wp['connectStatus'] ?? -1) == 0) {
                                // The port was already in a no-internet state. Older stored
                                // snapshots may not contain no_inet_since, so initialize the
                                // timer instead of silently getting stuck forever.
                                if (!isset($prev_wp['no_inet_since'])) {
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
                                        if ($wan_tickets_enabled) {
                                        self::createWanDownTicket(
                                            $ticket_name, $mac, $serial, $entities_id,
                                            $matched_type, $glpi_id,
                                            $portLabel, $wp['wanName'] ?? '', $networkName,
                                            'no_internet', $failoverNote
                                        );
                                        }
                                    } else {
                                        $wp['no_inet_since'] = time();
                                        \GlpiPlugin\Gdmsintegration\Utils::log("GDMS: WAN no-internet debounce initialized for existing failed state — {$name} port {$portLabel} (waiting {$debounce_secs}s)");
                                    }
                                } else {
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
                                        if ($wan_tickets_enabled) {
                                        self::createWanDownTicket(
                                            $ticket_name, $mac, $serial, $entities_id,
                                            $matched_type, $glpi_id,
                                            $portLabel, $wp['wanName'] ?? '', $networkName,
                                            'no_internet', $failoverNote
                                        );
                                        }
                                    } else {
                                        // Still within debounce window — carry forward the timer
                                        $wp['no_inet_since'] = $prev_wp['no_inet_since'];
                                        $remaining = (int)ceil($debounce_secs - $elapsed);
                                        \GlpiPlugin\Gdmsintegration\Utils::log("GDMS: WAN no-internet debounce pending — {$name} port {$portLabel} (~{$remaining}s remaining)");
                                    }
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
                $raw_sw_ports = \GlpiPlugin\Gdmsintegration\API::gwnGetSwitchPortInfo(
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

            // Split IPv4 vs IPv6: privateIp may contain an IPv6 address on some devices
            $_raw_priv = $d['privateIp'] ?? $d['privateip'] ?? '';
            $_is_v6    = strpos($_raw_priv, ':') !== false;
            $_priv4    = $_is_v6 ? '' : $_raw_priv;
            $_priv6    = !empty($d['ipv6']) ? $d['ipv6'] : ($_is_v6 ? $_raw_priv : '');

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
                isset($d['lastSeen'])  ? gmdate('Y-m-d H:i:s', (int)($d['lastSeen']/1000))
                    : (isset($d['lastTime']) ? date('Y-m-d H:i:s', strtotime($d['lastTime'])) : null),
                $d['ipv4']            ?? '',
                !empty($d['_preserve_firmware_latest']) ? null : ($d['firmware_latest'] ?? ''),
                array_key_exists('accountStatus', $d)
                    ? ((int)$d['accountStatus'] === 1 ? 'registered' : 'unregistered')
                    : ($d['sip_status'] ?? ''),
                $entities_id,
                $_priv6,
                $_priv4,
                $d['sip_extension']   ?? '',
                $d['location']        ?? $d['site']      ?? '',
                (int)($d['dnd']            ?? 0),
                (int)($d['isSynchronized'] ?? 0),
                $d['syncFailureMsg']  ?? '',
                (int)($d['scheduledTask']  ?? 0)
            );

            // \Ticket transitions: ONLY on true online→offline transition.
            // Devices removed from the cloud, moved between networks, or that stay offline
            // do NOT generate new tickets — only a genuine state change does.
            if ($glpi_id > 0) {
                if (($prevStatus === 'online' && $status === 'offline') ||
                    ($prevStatus === 'offline' && $status === 'offline')) {
                    $dev_category   = \GlpiPlugin\Gdmsintegration\API::getDeviceCategory($gdms_model);
                    $cat_flag_map   = [
                        'phone'  => 'tickets_phone',
                        'router' => 'tickets_router',
                        'switch' => 'tickets_switch',
                        'ap'     => 'tickets_ap',
                        'pbx'    => 'tickets_pbx',
                    ];
                    $cat_flag       = $cat_flag_map[$dev_category] ?? null;
                    $cat_enabled    = $cat_flag === null || (int)($config_data[$cat_flag] ?? 1) === 1;
                    if ($cat_enabled) {
                    self::createOfflineTicket(
                        $ticket_name,
                        $mac,
                        $serial,
                        $entities_id,
                        $matched_type,
                        $glpi_id,
                        $d['publicIp']        ?? $d['ip']       ?? $d['ipv4'] ?? '',
                        $d['networkName']     ?? $d['siteName'] ?? '',
                        $d['firmwareVersion'] ?? $d['versionFirmware'] ?? '',
                        (int)($d['upTime'] ?? 0),
                        $d['privateIp']       ?? $d['privateip'] ?? ''
                    );
                    }
                } elseif ($prevStatus === 'offline' && $status === 'online') {
                    self::resolveOfflineTicket($ticket_name, $matched_type, $glpi_id);
                }
            }
            // Topology link (networking devices only)
            if (!empty($d['uplink_mac'])) {
                $uplink  = strtolower(trim($d['uplink_mac']));
                $link_key = $mac . '|' . $uplink;
                if (!isset($existing_links[$link_key])) {
                    $link->add([
                        'source_mac' => $mac,
                        'target_mac' => $uplink,
                        'type'       => 'uplink',
                    ]);
                    $existing_links[$link_key] = true;
                }
            }
        }

        // Insert history snapshots via ORM-approved insert() to comply with GLPI 11 query restrictions.
        if (!empty($history_batch)) {
            global $DB;
            $table = \GlpiPlugin\Gdmsintegration\History::getTable();
            foreach ($history_batch as $h) {
                $DB->insert($table, $h);
            }
        }

        return count($devices);
    }

    // -----------------------------------------------------------------------
    // Upsert a single asset (\NetworkEquipment or Phone)
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
        /** @var \CommonDBTM $obj */
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
            \GlpiPlugin\Gdmsintegration\Utils::log("  UPDATE {$itemtype} #{$glpi_id} — {$name} (MAC:{$mac} SN:{$serial})");
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
        \GlpiPlugin\Gdmsintegration\Utils::log("  CREATE {$itemtype} #{$new_id} — {$name} (MAC:{$mac} SN:{$serial})");
        return $new_id;
    }

    // -----------------------------------------------------------------------
    // Open an offline incident ticket and link the asset as an element.
    // Skips creation if an open GDMS ticket already exists for this asset.
    // -----------------------------------------------------------------------
    /** Resolve the configured ITIL category; never block ticket creation. */
    private static function getConfiguredTicketCategory(array $config, int $entities_id, string $itemtype): int {
        $key = $itemtype === 'Phone' ? 'ticket_category_telephony_id' : 'ticket_category_network_id';
        $category_id = (int)($config[$key] ?? 0);
        return $category_id > 0
            ? \GlpiPlugin\Gdmsintegration\Config::validateTicketCategoryId($category_id, $entities_id)
            : 0;
    }

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

        // Application lock — prevents race conditions when concurrent syncs
        // attempt to open a WAN ticket for the same port at the same time.
        $lock_name = 'gdmsinteg_' . substr(md5("wan_{$reason}_{$itemtype}_{$glpi_id}_{$portSilk}"), 0, 24);
        $lock_token = \GlpiPlugin\Gdmsintegration\Utils::acquireLock($lock_name, 300);
        if ($lock_token === null) {
            \GlpiPlugin\Gdmsintegration\Utils::log("GDMS: Lock busy for {$deviceName} WAN {$portSilk} — concurrent sync, skipping");
            return;
        }

        try {
        // Duplicate guard: check for existing open WAN ticket for same port
        $existing = new \Item_Ticket();
        // Marker includes reason so link-down and no-internet are tracked as separate tickets
        $marker   = $reason === 'no_internet' ? "[GDMS-WAN-NOINET:{$portSilk}]" : "[GDMS-WAN:{$portSilk}]";
        foreach ($existing->find(['itemtype' => $itemtype, 'items_id' => $glpi_id]) as $link) {
            $t = new \Ticket();
            if ($t->getFromDB($link['tickets_id'])) {
                $open = [\Ticket::INCOMING, \Ticket::ASSIGNED, \Ticket::PLANNED, \Ticket::WAITING];
                if (in_array((int)$t->fields['status'], $open) && str_contains($t->fields['name'], $marker)) {
                    \GlpiPlugin\Gdmsintegration\Utils::log("GDMS: WAN ticket already open for {$deviceName} {$portSilk} — skipping");
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
        $ticket  = new \Ticket();
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
        $cfg_req      = \GlpiPlugin\Gdmsintegration\Config::getConfigByEntity($entities_id);
        $ticket_category_id = self::getConfiguredTicketCategory($cfg_req, $entities_id, $itemtype);
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
            'type'        => \Ticket::INCIDENT_TYPE,
            'status'      => ($tech_id > 0) ? \Ticket::ASSIGNED : \Ticket::INCOMING,
        ];
        if ($ticket_category_id > 0) {
            $ticket_data['itilcategories_id'] = $ticket_category_id;
        }
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
            (new \Item_Ticket())->add(['tickets_id' => $ticket_id, 'itemtype' => $itemtype, 'items_id' => $glpi_id, '_disablenotif' => true]);
            $tech_info = $tech_id > 0 ? " | assigned→user#{$tech_id}" : '';
            \GlpiPlugin\Gdmsintegration\Utils::log("GDMS: WAN ticket #{$ticket_id} → {$deviceName} port {$portSilk} | entity={$entities_id}{$tech_info}");
        }
        } finally {
            \GlpiPlugin\Gdmsintegration\Utils::releaseLock($lock_name, $lock_token);
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

        $existing = new \Item_Ticket();
        foreach ($existing->find(['itemtype' => $itemtype, 'items_id' => $glpi_id]) as $link) {
            $t = new \Ticket();
            if (!$t->getFromDB($link['tickets_id'])) continue;
            $open = [\Ticket::INCOMING, \Ticket::ASSIGNED, \Ticket::PLANNED, \Ticket::WAITING];
            if (!in_array((int)$t->fields['status'], $open)) continue;
            if (!str_contains($t->fields['name'], $marker)) continue;

            $followup = new \ITILFollowup();
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
            $t->update(['id' => $link['tickets_id'], 'status' => \Ticket::SOLVED]);
            \GlpiPlugin\Gdmsintegration\Utils::log(
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
        int    $uptime_sec = 0,
        string $private_ip = ''
    ): void {

        // ── Idempotency: application lock prevents duplicate tickets when two
        //    concurrent syncs (e.g. two users refreshing the dashboard at the
        //    same time) both pass the open-ticket check simultaneously. ──────
        $lock_name = 'gdmsinteg_' . substr(md5("offline_{$itemtype}_{$glpi_id}"), 0, 24);
        $lock_token = \GlpiPlugin\Gdmsintegration\Utils::acquireLock($lock_name, 300);
        if ($lock_token === null) {
            \GlpiPlugin\Gdmsintegration\Utils::log(
                "GDMS: Lock busy for {$name} (offline ticket) — concurrent sync, skipping"
            );
            return;
        }

        try {
            // Guard: skip if there is already an open [GDMS] ticket linked to this asset.
            // This check runs inside the advisory lock so only one process reaches it at a time.
            $existing = new \Item_Ticket();
            $linked   = $existing->find(['itemtype' => $itemtype, 'items_id' => $glpi_id]);
            foreach ($linked as $link_row) {
                $t = new \Ticket();
                if ($t->getFromDB($link_row['tickets_id'])) {
                    $open_statuses = [\Ticket::INCOMING, \Ticket::ASSIGNED, \Ticket::PLANNED, \Ticket::WAITING];
                    if (in_array((int)$t->fields['status'], $open_statuses)
                        && str_contains($t->fields['name'], '[GDMS]')
                        && !str_contains($t->fields['name'], '— WAN ')) {
                        \GlpiPlugin\Gdmsintegration\Utils::log(
                            "GDMS: \Ticket already open for {$name} (#" . $link_row['tickets_id'] . ") — skipping"
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
                $devState = new \GlpiPlugin\Gdmsintegration\Device();
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
                "| **%s** | %s |\n" .
                "| **%s** | %s |\n\n" .
                "*%s*",
                $name,
                __('went offline and is no longer reachable.', 'gdmsintegration'),
                __('Field', 'gdmsintegration'),
                __('Value', 'gdmsintegration'),
                strtoupper($mac),
                __('Serial', 'gdmsintegration'),      strtoupper($serial) ?: __('N/A', 'gdmsintegration'),
                __('Public IP', 'gdmsintegration'),   $ip         ?: __('N/A', 'gdmsintegration'),
                __('Private IP', 'gdmsintegration'),  $private_ip ?: __('N/A', 'gdmsintegration'),
                __('Network', 'gdmsintegration'),     $network    ?: __('N/A', 'gdmsintegration'),
                __('Firmware', 'gdmsintegration'),    $firmware   ?: __('N/A', 'gdmsintegration'),
                __('Last uptime', 'gdmsintegration'), $uptimeStr,
                __('Detected', 'gdmsintegration'),    $now,
                __('This ticket was automatically generated by the GDMS Integration plugin.', 'gdmsintegration')
            );

            $cfg_req      = \GlpiPlugin\Gdmsintegration\Config::getConfigByEntity($entities_id);
            // Use the same configured ITIL category as WAN incidents. This keeps
            // automatically generated offline tickets classified consistently
            // without changing the existing category configuration.
            $ticket_category_id = self::getConfiguredTicketCategory($cfg_req, $entities_id, $itemtype);

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
                'type'        => \Ticket::INCIDENT_TYPE,
                'status'      => ($tech_id > 0) ? \Ticket::ASSIGNED : \Ticket::INCOMING,
            ];
            if ($locations_id > 0) {
                $ticket_data['locations_id'] = $locations_id;
            }
            if ($ticket_category_id > 0) {
                $ticket_data['itilcategories_id'] = $ticket_category_id;
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

            $ticket    = new \Ticket();
            $ticket_id = (int) $ticket->add($ticket_data);

            if ($ticket_id > 0) {
                (new \Item_Ticket())->add([
                    'tickets_id'    => $ticket_id,
                    'itemtype'      => $itemtype,
                    'items_id'      => $glpi_id,
                    '_disablenotif' => true,
                ]);
                $tech_info = $tech_id > 0 ? " | assigned→user#{$tech_id}" : '';
                \GlpiPlugin\Gdmsintegration\Utils::log(sprintf(
                    "GDMS: Ticket #%d created → [GDMS] Device Offline: %s | linked to %s #%d | entity=%d%s",
                    $ticket_id, $name, $itemtype, $glpi_id, $entities_id, $tech_info
                ));
            }
        } finally {
            // Always release the advisory lock, even if an exception was thrown
            \GlpiPlugin\Gdmsintegration\Utils::releaseLock($lock_name, $lock_token);
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
        $existing = new \Item_Ticket();
        $linked   = $existing->find(['itemtype' => $itemtype, 'items_id' => $glpi_id]);
        foreach ($linked as $link_row) {
            $t = new \Ticket();
            if (!$t->getFromDB($link_row['tickets_id'])) continue;
            $open = [\Ticket::INCOMING, \Ticket::ASSIGNED, \Ticket::PLANNED, \Ticket::WAITING];
            if (!in_array((int)$t->fields['status'], $open)) continue;
            if (!str_contains($t->fields['name'], '[GDMS]')) continue;
            if (str_contains($t->fields['name'], '— WAN ')) continue; // skip WAN tickets

            // Add followup noting recovery
            $followup = new \ITILFollowup();
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
                'status' => \Ticket::SOLVED,
            ]);

            \GlpiPlugin\Gdmsintegration\Utils::log(
                "GDMS: Ticket #{$link_row['tickets_id']} auto-resolved — {$name} back online"
            );
        }
    }

    // -----------------------------------------------------------------------
    // Build asset caches from both \NetworkEquipment and Phone
    // Returns [$mac_cache, $serial_cache]
    // Each entry carries '_itemtype' so we know where the asset lives.
    // -----------------------------------------------------------------------
    private static function buildAssetCaches(int $entities_id): array {
        $mac_cache    = [];
        $serial_cache = [];
        $name_cache   = [];

        // Keep matching inside the entity being synchronized. A same-name or
        // same-identifier asset in another entity must never be silently reused.
        foreach (['NetworkEquipment', 'Phone'] as $itemtype) {
            $obj  = new $itemtype();
            $rows = $obj->find(['entities_id' => $entities_id], [], 0);

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
    private static function buildModelCache(string $itemtype): array {
        $cache = [];
        $model = new $itemtype();
        foreach ($model->find() as $m) {
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

        $device  = new \GlpiPlugin\Gdmsintegration\Device();
        $history = new \GlpiPlugin\Gdmsintegration\History();

        $query = [
            'SELECT' => ['id', 'mac', 'cloud_name'],
            'FROM'   => \GlpiPlugin\Gdmsintegration\Device::getTable(),
        ];
        // Filter by entity so that syncing entity A never purges entity B's device state.
        // Rows with entities_id=0 are legacy records (pre-1.2.8);
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
            \GlpiPlugin\Gdmsintegration\Utils::log(
                "  REMOVED {$mac} ({$name}): not returned by API — deleting plugin state (no ticket)"
            );

            $device->deleteByCriteria(['mac' => $mac], 1);   // purge=1
            $history->deleteByCriteria(['mac' => $mac], 1);  // purge=1
            $removed++;
        }
        return $removed;
    }

    public static function cleanupHistory(): void {
        // Use \CommonDBTM::deleteByCriteria() — GLPI ORM, no raw SQL.
        // The second parameter (1) enables purge (permanent delete, not trashbin).
        $history = new \GlpiPlugin\Gdmsintegration\History();
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
        $history = new \GlpiPlugin\Gdmsintegration\History();
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
        $history = new \GlpiPlugin\Gdmsintegration\History();
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


    /** Create deduplicated tickets for actionable GWN cloud alerts. Offline/WAN
     * transitions remain owned by the existing state/WAN ticket engine. */
    private static function syncGwnAlertTickets(array $config, int $entities_id, array $networkIds, array $devices): void {
        if (!class_exists('Ticket')) return;
        $assets=[];
        foreach (['NetworkEquipment','Phone'] as $it) { $o=new $it(); foreach($o->find(['entities_id'=>$entities_id]) as $a){$m=strtolower(str_replace([':', '-'],'',(string)($a['uuid']??''))); if($m)$assets[$m]=[$it,(int)$a['id'],(string)($a['name']??'')];} }
        foreach ($networkIds as $nid) {
            $complete=false; $alerts=\GlpiPlugin\Gdmsintegration\API::gwnGetAlerts($config,$nid,50,$complete);
            foreach($alerts as $a){
                $sev=strtolower((string)($a['severity']??'')); if(!in_array($sev,['critical','warning'],true)) continue;
                $cat=strtolower((string)($a['category']??'')); $desc=(string)($a['description']??'');
                if(str_contains($cat,'offline')||str_contains(strtolower($desc),'offline')||str_contains($cat,'wan')) continue;
                $aid=preg_replace('/[^A-Za-z0-9_.:-]/','',(string)($a['id']??'')); if($aid==='') continue;
                $marker='[GWN-ALERT:'.$aid.']';
                $t=new \Ticket(); $existing=$t->find(['entities_id'=>$entities_id,'name'=>['LIKE','%'.$marker.'%'],'status'=>['<',\Ticket::SOLVED]],[],1); if($existing) continue;
                $macKey=strtolower(str_replace([':', '-'],'',(string)($a['deviceMac']??''))); $asset=$assets[$macKey]??null;
                $name=trim((string)($a['deviceName']??'')) ?: ($asset[2]??'Grandstream device');
                $content=$marker."\n".__('Cloud alert reported by GDMS Networking.','gdmsintegration')."\n".__('Severity','gdmsintegration').': '.$sev."\n".__('Category','gdmsintegration').': '.$cat."\n".__('Detail','gdmsintegration').': '.$desc;
                $alert_category_id = $asset
                    ? self::getConfiguredTicketCategory($config, $entities_id, $asset[0])
                    : self::getConfiguredTicketCategory($config, $entities_id, 'NetworkEquipment');
                $alert_ticket_data = ['name'=>'[GDMS] '.$name.' — '.$desc.' '.$marker,'content'=>$content,'entities_id'=>$entities_id,'status'=>\Ticket::INCOMING,'urgency'=>$sev==='critical'?5:4,'impact'=>$sev==='critical'?4:3,'type'=>\Ticket::INCIDENT_TYPE];
                if ($alert_category_id > 0) {
                    $alert_ticket_data['itilcategories_id'] = $alert_category_id;
                }
                $tid=(int)$t->add($alert_ticket_data);
                if($tid>0 && $asset){(new \Item_Ticket())->add(['tickets_id'=>$tid,'itemtype'=>$asset[0],'items_id'=>$asset[1],'_disablenotif'=>true]);}
            }
        }
    }
}
