<?php
/**
 * GDMS Integration — Firmware check & schedule upgrade AJAX endpoint.
 *
 * GET  ?action=check
 *      Compares each device's firmware against the highest version seen
 *      for the same model type in the local device state table.
 *      Also calls GWN upgrade/version for official recommended version when available.
 *      Returns JSON array of {mac, type, currentVersion, latestVersion, hasUpdate}
 *
 * POST ?action=upgrade
 *      Body JSON: {macs: [...]}
 *      Schedules firmware upgrade for the given MACs via GWN API.
 */

// check (GET): any logged-in user with config READ
// upgrade (POST): requires config UPDATE (admin-level)
$_action_early = $_GET['action'] ?? 'check';
if ($_action_early === 'upgrade') {
    Session::checkRight('config', UPDATE);
} else {
    Session::checkLoginUser();
}
header('Content-Type: application/json');

$entities_id = (int) ($_GET['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
$action      = $_GET['action'] ?? 'check';

$config_obj = new PluginGdmsintegrationConfig();
$config     = $config_obj->getConfigByEntity($entities_id);

// ── CHECK ────────────────────────────────────────────────────────────────────
if ($action === 'check') {
    $state_obj = new PluginGdmsintegrationDevice();
    $all       = $state_obj->find();

    if (empty($all)) {
        echo json_encode([]);
        return;
    }

    // Build: apType/model → max firmware seen across all devices of that type
    // Use the device type from GLPI asset table for grouping
    $type_max_fw = []; // apType_string → highest versionFirmware
    $mac_data    = []; // mac → {firmware, network_id, sn_cloud}

    foreach ($all as $row) {
        $mac = strtolower(trim($row['mac'] ?? ''));
        $fw  = trim($row['firmware'] ?? '');
        if (!$mac || !$fw) continue;
        $mac_data[$mac] = $row;
    }

    // Also build a map from GLPI asset model type
    $mac_to_model = [];
    foreach (['NetworkEquipment', 'Phone'] as $itemtype) {
        $obj  = new $itemtype();
        $rows = $obj->find();
        foreach ($rows as $r) {
            $m = strtolower(trim($r['uuid'] ?? ''));
            if ($m) {
                // Use GLPI model name or just itemtype+model_id as grouping key
                $mac_to_model[$m] = $r['networkequipmentmodels_id'] ?? $r['phonemodels_id'] ?? 0;
            }
        }
    }

    // Compute max firmware per model group from stored data
    // Group by stored model_id OR fall back to grouping all NetworkEquipment together
    foreach ($mac_data as $mac => $row) {
        $fw      = $row['firmware'];
        $modelId = $mac_to_model[$mac] ?? 0;
        $key     = 'model_' . $modelId;
        if (!isset($type_max_fw[$key]) || version_compare($fw, $type_max_fw[$key], '>')) {
            $type_max_fw[$key] = $fw;
        }
    }

    // Try GWN upgrade/version for each network to get official recommended version
    $official_latest = []; // mac → official lastVersion from GWN
    if (!empty($config['gwn_client_id'])) {
        $by_network = [];
        foreach ($mac_data as $mac => $row) {
            $nid = (int)($row['network_id'] ?? 0);
            if ($nid) $by_network[$nid][] = $mac;
        }
        foreach ($by_network as $network_id => $macs) {
            $versions = PluginGdmsintegrationAPI::gwnGetFirmwareVersions($config, $network_id);
            PluginGdmsintegrationUtils::log("Firmware check network {$network_id}: " . count($versions) . " record(s) from upgrade/version API");
            foreach ($versions as $v) {
                $apiMac = strtolower(str_replace(['-',' '], ':', trim($v['mac'] ?? '')));
                $latest = trim($v['lastVersion'] ?? '');
                if ($apiMac && !empty($latest) && !preg_match('/beta|rc|dev|alpha/i', $latest)) {
                    $official_latest[$apiMac] = $latest;
                    PluginGdmsintegrationUtils::debug("  Official latest for {$apiMac}: {$latest}");
                }
            }
        }
    }

    // Build result: flag devices where firmware < max peer OR < official
    $result = [];
    foreach ($mac_data as $mac => $row) {
        $current = $row['firmware'] ?? '';
        if (empty($current)) continue;

        $modelId    = $mac_to_model[$mac] ?? 0;
        $peerMax    = $type_max_fw['model_' . $modelId] ?? '';
        $official   = $official_latest[$mac] ?? '';

        // Determine latest: prefer official from GWN API, fall back to peer max
        $latest = '';
        if (!empty($official) && version_compare($official, $current, '>')) {
            $latest = $official;
        } elseif (!empty($peerMax) && version_compare($peerMax, $current, '>')) {
            $latest = $peerMax;
        }

        $hasUpdate = !empty($latest);
        PluginGdmsintegrationUtils::debug("FW {$mac}: current={$current} peerMax={$peerMax} official={$official} hasUpdate=" . ($hasUpdate?'YES':'no'));

        $result[] = [
            'mac'            => $mac,
            'currentVersion' => $current,
            'latestVersion'  => $latest ?: $peerMax ?: $official,
            'hasUpdate'      => $hasUpdate,
            'network_id'     => (int)($row['network_id'] ?? 0),
        ];
    }

    $updates = count(array_filter($result, fn($r) => $r['hasUpdate']));
    PluginGdmsintegrationUtils::log("Firmware check complete: " . count($result) . " device(s) checked, {$updates} update(s) available");
    echo json_encode($result);
    return;
}

// ── UPGRADE ──────────────────────────────────────────────────────────────────
if ($action === 'upgrade') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['error' => 'POST required']);
        return;
    }
    if (empty($config['gwn_client_id'])) {
        echo json_encode(['error' => 'GWN not configured']);
        return;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $macs = array_filter(array_map('strtoupper', (array)($body['macs'] ?? [])));

    if (empty($macs)) {
        echo json_encode(['error' => 'No MACs provided']);
        return;
    }

    $result = PluginGdmsintegrationAPI::gwnScheduleUpgrade($config, array_values($macs));
    PluginGdmsintegrationUtils::log(
        "GDMS: Firmware upgrade scheduled — MACs: " . implode(', ', $macs)
        . " | result: " . json_encode($result)
    );
    echo json_encode($result);
    return;
}

echo json_encode(['error' => 'Unknown action']);
