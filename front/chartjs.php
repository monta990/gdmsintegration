<?php
/**
 * GDMS Integration — serves Chart.js as a static asset
 * Registered as stateless in setup.php so no GLPI session is required.
 */
$file = dirname(__DIR__) . '/js/chart.umd.min.js';
if (!file_exists($file)) { http_response_code(404); exit; }
header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: public, max-age=604800, immutable');
header('X-Content-Type-Options: nosniff');
readfile($file);
exit;
