<?php
/**
 * GDMS Integration — WAN port status AJAX endpoint.
 * GET ?action=status — returns port info for all tracked GWN routers.
 */
Session::checkLoginUser();
header('Content-Type: application/json');

$entities_id = (int) ($_GET['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
$action      = $_GET['action'] ?? 'status';

if ($action !== 'status') {
    echo json_encode(['error' => 'Unknown action']);
    return;
}

$config_obj = new PluginGdmsintegrationConfig();
$config     = $config_obj->getConfigByEntity($entities_id);

if (empty($config['gwn_client_id'])) {
    echo json_encode([]);
    return;
}

$state_obj = new PluginGdmsintegrationDevice();
$all       = $state_obj->find();

$result = [];
foreach ($all as $row) {
    $mac        = strtolower(trim($row['mac'] ?? ''));
    $network_id = (int)($row['network_id'] ?? 0);
    // Only query routers (network_id set = GWN device) that are online
    if (!$mac || !$network_id || $row['status'] !== 'online') continue;

    // Use stored wan_ports_json if available (populated by sync)
    $stored = $row['wan_ports_json'] ?? '';
    if (!empty($stored)) {
        $result[$mac] = json_decode($stored, true) ?? [];
        continue;
    }

    // Fallback: fetch live from API (first load before sync ran)
    $apiMac    = strtoupper(str_replace(':', '', $mac));
    $port_data = PluginGdmsintegrationAPI::gwnGetRouterPortInfo($config, $apiMac, $network_id);
    if (empty($port_data['portInfo'])) continue;

    $all_ports = [];
    foreach ($port_data['portInfo'] as $port) {
        $role = (int)($port['role'] ?? 0); // 0=LAN, 1=WAN
        $embeddedIpv4 = $port['ipv4Info'] ?? [];
        $all_ports[] = [
            'id'              => $port['portId']             ?? $port['silkScreenPort'] ?? '',
            'name'            => $port['portName']           ?? '',
            'silk'            => $port['silkScreenPort']     ?? '',
            'silkNum'         => $port['silkNum']            ?? '',
            'role'            => $role, // 0=LAN, 1=WAN
            'link'            => (int)($port['linkStatus']   ?? 0),
            'speed'           => (int)($port['portSpeed']    ?? 0),
            'type'            => ($port['type'] ?? 0) == 1 ? 'SFP' : 'GE',
            'desc'            => $port['portDesc']           ?? '',
            'wanName'         => $port['wanName']            ?? '',
            'connectDuration' => (int)($port['connectDuration'] ?? 0),
            // ipv4Info embedded directly in each port object
            'ip'              => $embeddedIpv4['ip4Address']   ?? '',
            'connectStatus'   => isset($embeddedIpv4['connectStatus'])
                                  ? (int)$embeddedIpv4['connectStatus'] : -1,
            'wanType'         => isset($embeddedIpv4['type'])
                                  ? (int)$embeddedIpv4['type'] : -1,
            'gateway'         => $embeddedIpv4['gateway']      ?? '',
        ];
    }
    // ipv4Info is EMBEDDED inside each port object — read directly, NOT from separate array
    // (port_data['ipv4Info'] is always [] — the real data is at port['ipv4Info'])
    // Sort: WAN first, then LAN, by silk screen number
    usort($all_ports, fn($a, $b) =>
        ($b['role'] - $a['role']) ?: strcmp($a['silk'] ?: $a['id'], $b['silk'] ?: $b['id'])
    );
    $result[$mac] = $all_ports;
}

echo json_encode($result);
