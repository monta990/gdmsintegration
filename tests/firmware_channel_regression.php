<?php
/**
 * Standalone regression tests for GWN firmware channel classification.
 * Run with: php tests/firmware_channel_regression.php
 */

if (!function_exists('__')) {
    function __(string $message, ?string $domain = null): string {
        return $message;
    }
}

require_once dirname(__DIR__) . '/src/API.php';
require_once dirname(__DIR__) . '/src/Service/FirmwareService.php';

use GlpiPlugin\Gdmsintegration\API;
use GlpiPlugin\Gdmsintegration\Service\FirmwareService;

$assertions = 0;
$assertSame = static function ($expected, $actual, string $message) use (&$assertions): void {
    $assertions++;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
};

// Official Grandstream table shape. This row is also published for the
// compact GWN7001/7002/7003 family listing.
$html = <<<'HTML'
<table>
  <thead>
    <tr><th>Model</th><th>General Firmware</th><th>Beta Firmware</th></tr>
  </thead>
  <tbody>
    <tr><td>GWN7001/7002/7003</td><td>1.0.11.61</td><td>1.0.11.63</td></tr>
  </tbody>
</table>
HTML;

$catalog = API::parseFirmwareCatalogHtml($html);
$assertSame(true, $catalog['ok'] ?? false, 'catalog fixture parses');
$assertSame('1.0.11.61', $catalog['models']['GWN7001']['official'] ?? null, 'GWN7001 General Firmware parsed');
$assertSame('1.0.11.63', $catalog['models']['GWN7001']['beta'] ?? null, 'GWN7001 Beta Firmware parsed');
$assertSame('1.0.11.61', $catalog['models']['GWN7002']['official'] ?? null, 'slash sibling GWN7002 alias parsed');
$assertSame('1.0.11.61', $catalog['models']['GWN7003']['official'] ?? null, 'slash sibling GWN7003 alias parsed');

$beta = API::resolveGwnFirmwareChannel('GWN7001', '1.0.11.63', $catalog);
$assertSame('beta', $beta['channel'], 'cloud-reported beta is classified as beta');
$assertSame('1.0.11.61', $beta['official'], 'beta classification keeps General Firmware as official baseline');
$assertSame('1.0.11.63', $beta['beta'], 'beta version remains separately available');

$stable = API::resolveGwnFirmwareChannel('GWN7001', '1.0.11.61', $catalog);
$assertSame('stable', $stable['channel'], 'cloud candidate matching General Firmware is stable');

$unknown = API::resolveGwnFirmwareChannel('GWN7001', '1.0.11.64', $catalog);
$assertSame('unverified', $unknown['channel'], 'candidate not present in catalog is unverified');
$assertSame('1.0.11.61', $unknown['official'], 'unverified candidate does not replace the official version');

$catalogOnly = API::resolveGwnFirmwareChannel('GWN7001', '', $catalog);
$assertSame('catalog_only', $catalogOnly['channel'], 'catalog-only value is not treated as cloud-verified stable');

// The private row annotator must not promote an unlabeled numeric version.
$annotate = new ReflectionMethod(API::class, 'gwnAnnotateFirmwareRows');
$annotate->setAccessible(true);
$annotated = $annotate->invoke(null, [
    ['mac' => 'C0:74:AD:EC:00:9E', 'version' => '1.0.11.63'],
    ['mac' => 'C0:74:AD:EC:00:9F', 'version' => '1.0.11.61', 'channel' => 'stable'],
    ['mac' => 'C0:74:AD:EC:00:AA', 'version' => '1.0.11.63', 'channel' => 'beta'],
]);
$assertSame('1.0.11.63', $annotated[0]['reportedVersion'], 'unlabeled numeric candidate remains recorded');
$assertSame('', $annotated[0]['stableVersion'], 'unlabeled numeric candidate is not promoted to stable');
$assertSame('1.0.11.61', $annotated[1]['stableVersion'], 'explicit stable marker remains supported');
$assertSame('1.0.11.63', $annotated[2]['betaVersion'], 'explicit beta marker remains supported');

// Beta and unverified/ahead-of-stable states deliberately share one concise
// diagnostic so the dashboard does not present competing explanations.
$statusLabel = new ReflectionMethod(FirmwareService::class, 'firmwareStatusLabel');
$statusLabel->setAccessible(true);
$betaLabel = $statusLabel->invoke(null, 'beta_installed');
$aheadLabel = $statusLabel->invoke(null, 'ahead_of_stable');
$assertSame($betaLabel, $aheadLabel, 'beta and ahead-of-stable use one shared compact diagnostic');
$assertSame(
    'Installed firmware does not match the verified stable release; automatic downgrade is blocked',
    $betaLabel,
    'shared firmware diagnostic is concise and does not claim an unverified beta channel'
);

fwrite(STDOUT, "PASS: {$assertions} GWN firmware channel regression assertions.\n");
