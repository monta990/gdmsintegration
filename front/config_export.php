<?php
/**
 * GDMS Integration — Plugin configuration export (JSON)
 * Accepts POST. Optionally includes credentials if include_credentials=1.
 */

Session::checkRight('config', READ);

$entities_id = (int)($_POST['entities_id'] ?? $_GET['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
if (!Session::haveAccessToEntity($entities_id)) {
    throw new \Glpi\Exception\Http\AccessDeniedHttpException();
}

$include_creds = !empty($_POST['include_credentials']);

$cfg_obj = new PluginGdmsintegrationConfig();
$cfg     = $cfg_obj->getConfigByEntity($entities_id); // returns decrypted values

$export = [
    '_plugin'             => 'gdmsintegration',
    '_version'            => PLUGIN_GDMSINTEGRATION_VERSION,
    '_exported_at'        => date('c'),
    '_includes_credentials' => $include_creds,
    'entities_id'         => $entities_id,
    'username'            => $cfg['username']              ?? '',
    'refresh_interval'    => (int)($cfg['refresh_interval']    ?? 300),
    'ip_version'          => $cfg['ip_version']            ?? 'ipv4',
    'debug_logging'       => (int)($cfg['debug_logging']       ?? 0),
    'chart_days'          => (int)($cfg['chart_days']          ?? 60),
    'show_topology'       => (int)($cfg['show_topology']       ?? 1),
    'ticket_requester_id' => (int)($cfg['ticket_requester_id'] ?? 0),
    'wan_debounce_seconds'=> (int)($cfg['wan_debounce_seconds']?? 300),
    'wan_tickets_enabled' => (int)($cfg['wan_tickets_enabled'] ?? 1),
    'tickets_phone'       => (int)($cfg['tickets_phone']       ?? 1),
    'tickets_router'      => (int)($cfg['tickets_router']      ?? 1),
    'tickets_switch'      => (int)($cfg['tickets_switch']      ?? 1),
    'tickets_ap'          => (int)($cfg['tickets_ap']          ?? 1),
    'tickets_pbx'         => (int)($cfg['tickets_pbx']         ?? 1),
];

if ($include_creds) {
    $export['password']          = $cfg['password']          ?? '';
    $export['client_id']         = $cfg['client_id']         ?? '';
    $export['client_secret']     = $cfg['client_secret']     ?? '';
    $export['gwn_client_id']     = $cfg['gwn_client_id']     ?? '';
    $export['gwn_client_secret'] = $cfg['gwn_client_secret'] ?? '';
    $export['webhook_secret']    = $cfg['webhook_secret']    ?? '';
}

$filename = 'gdms_config_entity' . $entities_id . '_' . date('Y-m-d') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
