<?php
/**
 * GDMS Integration — dismiss GWN Cloud alerts.
 * POST  ids[]=id1&ids[]=id2  (or JSON body {"ids":["id1"]})
 */
Session::checkLoginUser();
Session::checkRight('config', READ);
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'POST required']);
    return;
}

$entities_id = (int)($_POST['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);

// Accept ids from POST form or JSON body
$ids = $_POST['ids'] ?? [];
if (empty($ids)) {
    $json = json_decode(file_get_contents('php://input'), true);
    $ids  = $json['ids'] ?? [];
}
$ids = array_values(array_filter(array_map('strval', (array)$ids), fn($id) => preg_match('/^[a-zA-Z0-9_\-]{1,64}$/', $id)));

if (empty($ids)) {
    echo json_encode(['ok' => false, 'msg' => 'No ids provided']);
    return;
}

$config_obj = new PluginGdmsintegrationConfig();
$config     = $config_obj->getConfigByEntity($entities_id);

if (empty($config['gwn_client_id'])) {
    echo json_encode(['ok' => false, 'msg' => 'GWN not configured']);
    return;
}

$ok = PluginGdmsintegrationAPI::gwnDismissAlerts($config, $ids);
echo json_encode(['ok' => $ok]);
