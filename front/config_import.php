<?php
/**
 * GDMS Integration — Plugin configuration import (JSON)
 * Restores non-sensitive settings. Credentials are never overwritten.
 */

Session::checkRight('config', UPDATE);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Html::back();
}

$upload = $_FILES['config_json'] ?? null;
if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
    Session::addMessageAfterRedirect(
        __('No file received or upload error.', 'gdmsintegration'), false, ERROR
    );
    Html::back();
}

$raw = file_get_contents($upload['tmp_name']);
$data = json_decode($raw, true);

if (!is_array($data) || ($data['_plugin'] ?? '') !== 'gdmsintegration') {
    Session::addMessageAfterRedirect(
        __('Invalid or unrecognised configuration file.', 'gdmsintegration'), false, ERROR
    );
    Html::back();
}

$entities_id = (int)($_POST['entities_id'] ?? $data['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);

// Only import safe, non-credential fields
$allowed = [
    'refresh_interval', 'ip_version', 'debug_logging', 'chart_days',
    'show_topology', 'ticket_requester_id', 'wan_debounce_seconds',
    'wan_tickets_enabled', 'tickets_phone', 'tickets_router',
    'tickets_switch', 'tickets_ap', 'tickets_pbx',
];

$input = ['entities_id' => $entities_id];
foreach ($allowed as $key) {
    if (array_key_exists($key, $data)) {
        $input[$key] = $data[$key];
    }
}
if (!empty($data['username'])) {
    $input['username'] = $data['username'];
}
// Restore credentials only when the export explicitly included them
if (!empty($data['_includes_credentials'])) {
    foreach (['password','client_id','client_secret','gwn_client_id','gwn_client_secret','webhook_secret'] as $k) {
        if (array_key_exists($k, $data)) $input[$k] = $data[$k];
    }
}

$cfg = new PluginGdmsintegrationConfig();
$cfg->saveConfig($input);

Session::addMessageAfterRedirect(
    __('Configuration imported. API credentials were not changed — re-enter them if needed.', 'gdmsintegration'),
    true, INFO
);
Html::back();
