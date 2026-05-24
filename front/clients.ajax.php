<?php
/**
 * GDMS Integration — WiFi client list AJAX endpoint.
 * GET ?action=list&network_id=N&mac=XX:XX:XX:XX:XX:XX&entities_id=N
 * Returns array of connected WiFi clients for an AP or entire network.
 */
Session::checkLoginUser();
Session::checkRight('config', READ);
header('Content-Type: application/json');

$entities_id = (int) ($_GET['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
if (!Session::haveAccessToEntity($entities_id)) { http_response_code(403); echo json_encode(['error' => 'Access denied']); return; }
$network_id  = (int) ($_GET['network_id']  ?? 0);
$mac         = trim($_GET['mac'] ?? '');

if (!$network_id) {
    echo json_encode(['error' => 'Missing network_id']);
    return;
}

$config_obj = new PluginGdmsintegrationConfig();
$config     = $config_obj->getConfigByEntity($entities_id);

if (empty($config['gwn_client_id'])) {
    echo json_encode([]);
    return;
}

// Normalize MAC to uppercase no-colon format expected by some GWN endpoints
// but pass empty string to get all clients in the network
$ap_mac = $mac !== '' ? strtoupper(str_replace(':', '', $mac)) : '';

$raw = PluginGdmsintegrationAPI::gwnGetClientList($config, $network_id, $ap_mac);

// Normalize to a consistent field set for the dashboard
$clients = [];
foreach ($raw as $c) {
    $clients[] = [
        'mac'         => strtolower($c['mac']         ?? $c['clientMac']  ?? ''),
        'hostname'    => $c['hostname']    ?? $c['clientName'] ?? '',
        'ip'          => $c['ip']          ?? $c['ipAddress']  ?? '',
        'rssi'        => (int)($c['rssi']  ?? $c['signal']     ?? 0),
        'band'        => $c['band']        ?? $c['frequency']  ?? '',
        'ssid'        => $c['ssid']        ?? $c['wifiName']   ?? '',
        'apMac'       => strtolower($c['apMac'] ?? $c['connectedApMac'] ?? ''),
        'txRate'      => (int)($c['txRate'] ?? $c['uploadRate']   ?? 0),
        'rxRate'      => (int)($c['rxRate'] ?? $c['downloadRate'] ?? 0),
        'connectTime' => (int)($c['connectTime'] ?? $c['onlineTime'] ?? 0),
    ];
}

echo json_encode($clients);
