<?php
define('GLPI_ROOT', dirname(dirname(dirname(dirname(__DIR__)))));
header('Content-Type: application/javascript; charset=UTF-8');
header('Cache-Control: public, max-age=31536000, immutable');
readfile(dirname(__DIR__) . '/js/flatpickr.min.js');
exit;
