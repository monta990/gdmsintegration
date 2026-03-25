<?php
/**
 * GDMS Integration — Webhook endpoint
 *
 * URL: /plugins/gdmsintegration/front/webhook.php?entities_id=<N>
 *
 * Security:
 *   - Only accepts POST requests.
 *   - Validates HMAC-SHA256 signature in X-GDMS-Signature header
 *     when a webhook_secret is configured for the entity.
 *     GDMS must send: X-GDMS-Signature: sha256=<hex>
 *
 * This file does NOT start a GLPI user session — it is called by GDMS
 * cloud servers, not by a browser.
 */

// Load GLPI without forcing an authenticated session
define('GLPI_ROOT', dirname(dirname(dirname(dirname(__FILE__)))));
include_once(GLPI_ROOT . '/inc/includes.php');

// ------------------------------------------------------------------
// Only POST is accepted
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method Not Allowed');
}

// ------------------------------------------------------------------
// Read entity from query string
// ------------------------------------------------------------------
$entities_id = (int) ($_GET['entities_id'] ?? 0);

// ------------------------------------------------------------------
// Read raw body (must happen before any output)
// ------------------------------------------------------------------
$raw = (string) file_get_contents('php://input');

// ------------------------------------------------------------------
// HMAC-SHA256 signature validation
// ------------------------------------------------------------------
$configObj = new PluginGdmsintegrationConfig();
$config    = $configObj->getConfigByEntity($entities_id);

if (!empty($config['webhook_secret'])) {
    $incoming = (string) ($_SERVER['HTTP_X_GDMS_SIGNATURE'] ?? '');
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $config['webhook_secret']);

    // Constant-time comparison to prevent timing attacks
    if (!hash_equals($expected, $incoming)) {
        http_response_code(401);
        header('Content-Type: application/json');
        exit(json_encode(['error' => 'Invalid webhook signature']));
    }
}

// ------------------------------------------------------------------
// Validate JSON payload (optional — we trigger a full sync anyway)
// ------------------------------------------------------------------
$payload = json_decode($raw, true);
if ($raw !== '' && !is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json');
    exit(json_encode(['error' => 'Invalid JSON payload']));
}

// ------------------------------------------------------------------
// Trigger entity sync
// ------------------------------------------------------------------
$processed = PluginGdmsintegrationSync::syncEntity($entities_id);

header('Content-Type: application/json');
http_response_code(200);
echo json_encode([
    'status'     => 'ok',
    'processed'  => $processed,
]);
