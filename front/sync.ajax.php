<?php
/**
 * GDMS Integration — AJAX sync endpoint
 * GLPI 11: LegacyFileLoadController wraps legacy files in ob_start/ob_get_clean.
 * We MUST NOT call fastcgi_finish_request() or ob_* functions.
 *
 * Strategy: run syncEntity() synchronously. The JSON response is returned
 * inside the ob buffer and flushed by GLPI when the request completes.
 * The dashboard JS waits for the response before reloading — this is correct.
 */
Session::checkLoginUser();
Session::checkRight('config', UPDATE);

$entities_id = (int) ($_GET['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
PluginGdmsintegrationUtils::log("AJAX sync called — entity={$entities_id}");

// Release session lock before the long-running sync so other requests are not blocked.
session_write_close();

header('Content-Type: application/json');

try {
    $processed = PluginGdmsintegrationSync::syncEntity($entities_id);
    echo json_encode(['success' => true, 'processed' => $processed, 'mode' => 'sync']);
} catch (\Throwable $e) {
    PluginGdmsintegrationUtils::log("AJAX sync error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
