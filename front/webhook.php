<?php
/**
 * GDMS Integration — Webhook receiver
 * Registered as stateless — called by GDMS Cloud without browser session.
 * Uses correct Symfony exception namespaces for GLPI 11.
 */

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// Allow GET for health check / manual testing
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'ok', 'plugin' => 'gdmsintegration', 'endpoint' => 'webhook']);
    return;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    throw new MethodNotAllowedHttpException(['POST'], 'Only POST requests are accepted');
}

$raw    = file_get_contents('php://input');
$entity = (int) ($_GET['entities_id'] ?? 0);

// Validate HMAC if webhook_secret is configured
$config_obj = new PluginGdmsintegrationConfig();
$config     = $config_obj->getConfigByEntity($entity);
$secret     = $config['webhook_secret'] ?? '';

if (!empty($secret)) {
    $sig_header = $_SERVER['HTTP_X_GDMS_SIGNATURE'] ?? '';
    $expected   = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    if (!hash_equals($expected, $sig_header)) {
        PluginGdmsintegrationUtils::log("Webhook: signature mismatch from " . ($_SERVER['REMOTE_ADDR'] ?? ''));
        throw new AccessDeniedHttpException('Invalid webhook signature');
    }
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    throw new BadRequestHttpException('Invalid JSON payload');
}

PluginGdmsintegrationUtils::log(
    "Webhook received — entity:{$entity} | payload:" . substr(json_encode($payload), 0, 500)
);

// Process device status change from GDMS cloud
$mac    = strtolower(trim($payload['mac'] ?? $payload['device_mac'] ?? $payload['deviceMac'] ?? ''));
$rawStatus = strtolower($payload['status'] ?? $payload['deviceStatus'] ?? $payload['event'] ?? '');
$status = in_array($rawStatus, ['offline', 'disconnect', 'down', '0']) ? 'offline' : 'online';

PluginGdmsintegrationUtils::log("Webhook: {$mac} → {$status} (raw: {$rawStatus})");

if (!empty($mac)) {
    // Update state immediately so dashboard reflects it
    $state = new PluginGdmsintegrationDevice();
    $prevStatus = $state->getState($mac);
    $state->saveStateWithNetwork($mac, $status);

    // Fire ticket logic on status transition
    if ($prevStatus !== $status) {
        // Load asset info for ticket creation
        $assetInfo = null;
        foreach (['NetworkEquipment', 'Phone'] as $itemtype) {
            $obj  = new $itemtype();
            $rows = $obj->find(['uuid' => $mac]);
            if (!empty($rows)) {
                $assetInfo = array_merge(reset($rows), ['_itemtype' => $itemtype]);
                break;
            }
        }

        if ($assetInfo && $status === 'offline') {
            $deviceRow = $state->find(['mac' => $mac]);
            $dr        = !empty($deviceRow) ? reset($deviceRow) : [];
            PluginGdmsintegrationSync::triggerOfflineTicket(
                $assetInfo['name']       ?? $mac,
                $mac,
                $assetInfo['serial']     ?? '',
                $assetInfo['entities_id'] ?? $entity,
                $assetInfo['_itemtype'],
                (int)$assetInfo['id'],
                $dr['ip']           ?? '',
                $dr['network_name'] ?? '',
                $dr['firmware']     ?? '',
                (int)($dr['uptime_sec'] ?? 0)
            );
        } elseif ($assetInfo && $status === 'online') {
            PluginGdmsintegrationSync::triggerResolveTicket(
                $assetInfo['name'] ?? $mac,
                $assetInfo['_itemtype'],
                (int)$assetInfo['id']
            );
        }
    }
}

header('Content-Type: application/json');
echo json_encode(['ok' => true]);
