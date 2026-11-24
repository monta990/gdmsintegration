<?php

declare(strict_types=1);

if (!class_exists('CommonGLPI')) {
    abstract class CommonGLPI {}
}

if (!function_exists('__')) {
    function __(string $message, ?string $domain = null): string
    {
        return $message;
    }
}

require_once dirname(__DIR__) . '/src/Sync.php';

use GlpiPlugin\Gdmsintegration\Sync;

$method = new ReflectionMethod(Sync::class, 'hasExactGwnAlertMarker');
$method->setAccessible(true);
$assertSame = static function (bool $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true));
    }
};

$alertUnderscore = '[GWN-ALERT:site_1]';
$assertSame(true, $method->invoke(null, [['name' => 'Device alert ' . $alertUnderscore]], $alertUnderscore), 'Exact underscore-containing ID must match.');
$assertSame(false, $method->invoke(null, [['name' => 'Device alert [GWN-ALERT:siteA1]']], $alertUnderscore), 'Underscore must not behave as a wildcard.');
$assertSame(false, $method->invoke(null, [['name' => 'Device alert [GWN-ALERT:site_10]']], $alertUnderscore), 'A longer ID must not match a shorter ID.');
$assertSame(true, $method->invoke(null, [['name' => 'Device [GWN-ALERT:alert%1]']], '[GWN-ALERT:alert%1]'), 'Percent signs in IDs must be compared literally.');
$assertSame(false, $method->invoke(null, [['name' => 'No alert marker']], $alertUnderscore), 'Unrelated ticket names must not match.');
$assertSame(false, $method->invoke(null, [['name' => ' [GWN-ALERT:siteA1]'], ['name' => ' [GWN-ALERT:siteB1]']], $alertUnderscore), 'A candidate list with similar IDs must not collide.');

fwrite(STDOUT, "PASS: 6 GWN alert deduplication regression assertions.\n");
