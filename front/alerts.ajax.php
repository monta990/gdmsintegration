<?php
/**
 * GDMS Integration — GWN Cloud alert list AJAX endpoint.
 * GET ?entities_id=N[&network_id=N]
 * Returns recent alerts across all GWN networks (or one specific network).
 */
Session::checkLoginUser();
Session::checkRight('config', READ);
header('Content-Type: application/json');

$entities_id      = (int) ($_GET['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
if (!Session::haveAccessToEntity($entities_id)) { http_response_code(403); echo json_encode(['error' => 'Access denied']); return; }
$filter_network   = (int) ($_GET['network_id']  ?? 0);

$config_obj = new PluginGdmsintegrationConfig();
$config     = $config_obj->getConfigByEntity($entities_id);

if (empty($config['gwn_client_id'])) {
    echo json_encode([]);
    return;
}

// Discover network IDs from the device state table (avoids an extra /network/list call)
$state_obj  = new PluginGdmsintegrationDevice();
$all_states = $state_obj->find(['network_id' => ['>', 0]]);
$net_ids    = array_unique(array_column($all_states, 'network_id'));

if ($filter_network) {
    $net_ids = array_filter($net_ids, fn($id) => (int)$id === $filter_network);
}

$all_alerts = [];
foreach ($net_ids as $nid) {
    $alerts = PluginGdmsintegrationAPI::gwnGetAlerts($config, (int)$nid, 30);
    foreach ($alerts as $a) {
        $a['_network_id'] = (int)$nid;
        $all_alerts[]     = $a;
    }
}

// Sort by createTime descending (newest first) and cap at 100
usort($all_alerts, fn($a, $b) =>
    ((int)($b['createTime'] ?? 0)) <=> ((int)($a['createTime'] ?? 0))
);

echo json_encode(array_slice($all_alerts, 0, 100));
