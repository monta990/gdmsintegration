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
    $model     = $row['model'] ?? '';
    $is_switch = preg_match('/^GWN78|^GSS/i', $model);

    if ($is_switch) {
        // Switch: LAN port info via /switch/portInfo
        $raw_sw = PluginGdmsintegrationAPI::gwnGetSwitchPortInfo($config, $apiMac, $network_id);
        if (empty($raw_sw)) continue;
        $all_ports = [];
        foreach ($raw_sw as $port) {
            $all_ports[] = [
                'id'         => $port['portId']          ?? $port['silkScreenPort'] ?? '',
                'name'       => $port['portName']         ?? '',
                'silk'       => $port['silkScreenPort']   ?? '',
                'role'       => 0,
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
        usort($all_ports, fn($a, $b) => strcmp($a['silk'] ?: $a['id'], $b['silk'] ?: $b['id']));
        $result[$mac] = $all_ports;
        continue;
    }

    // Router: WAN + LAN port info via /device/info
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
            'role'            => $role,
            'link'            => (int)($port['linkStatus']   ?? 0),
            'speed'           => (int)($port['portSpeed']    ?? 0),
            'type'            => ($port['type'] ?? 0) == 1 ? 'SFP' : 'GE',
            'desc'            => $port['portDesc']           ?? '',
            'customName'      => $port['portCustomName']     ?? '',
            'wanName'         => $port['wanName']            ?? '',
            'connectDuration' => (int)($port['connectDuration'] ?? 0),
            'ip'              => $embeddedIpv4['ip4Address']   ?? '',
            'connectStatus'   => isset($embeddedIpv4['connectStatus'])
                                  ? (int)$embeddedIpv4['connectStatus'] : -1,
            'wanType'         => isset($embeddedIpv4['type'])
                                  ? (int)$embeddedIpv4['type'] : -1,
            'gateway'         => $embeddedIpv4['gateway']      ?? '',
            'txBytes'         => (int)($port['aggregate']['txBytes']  ?? 0),
            'rxBytes'         => (int)($port['aggregate']['rxBytes']  ?? 0),
        ];
    }
    // Sort: WAN first, then LAN, by silk screen number
    usort($all_ports, fn($a, $b) =>
        ($b['role'] - $a['role']) ?: strcmp($a['silk'] ?: $a['id'], $b['silk'] ?: $b['id'])
    );
    $result[$mac] = $all_ports;
}

echo json_encode($result);
