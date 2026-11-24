<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    if (!is_file($path)) {
        throw new RuntimeException(sprintf('Required file not found: %s', $relative));
    }

    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException(sprintf('Unable to read file: %s', $relative));
    }

    return $content;
};

$expect = static function (bool $condition, string $message) use (&$errors): void {
    if (!$condition) {
        $errors[] = $message;
    }
};

// Load the plugin's own metadata instead of expecting a particular release
// number or a hard-coded PHP/GLPI compatibility range in this test.
require_once $root . DIRECTORY_SEPARATOR . 'setup.php';
$metadata = plugin_version_gdmsintegration();
$version = trim((string) ($metadata['version'] ?? ''));
$name = trim((string) ($metadata['name'] ?? ''));
$requirements = is_array($metadata['requirements'] ?? null) ? $metadata['requirements'] : [];
$phpMinimum = trim((string) ($requirements['php']['min'] ?? ''));
$glpiMinimum = trim((string) ($requirements['glpi']['min'] ?? ''));
$glpiMaximum = trim((string) ($requirements['glpi']['max'] ?? ''));

$expect(
    preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', $version) === 1,
    'Plugin metadata must expose a valid semantic version.'
);
$expect($name !== '', 'Plugin metadata must expose a non-empty name.');
$expect(
    preg_match('/^\d+\.\d+(?:\.\d+)?$/', $phpMinimum) === 1,
    'Plugin metadata must expose a valid minimum PHP version.'
);
$expect(
    preg_match('/^\d+\.\d+(?:\.\d+)?$/', $glpiMinimum) === 1
    && preg_match('/^\d+\.\d+(?:\.\d+)?$/', $glpiMaximum) === 1
    && version_compare($glpiMinimum, $glpiMaximum, '<='),
    'Plugin metadata must expose a valid, ordered GLPI compatibility range.'
);

$xml = $read('plugin.xml');
$changelog = $read('CHANGELOG.md');

$expect(
    preg_match('/<name>\s*([^<]+)\s*<\/name>/i', $xml, $xmlName) === 1
    && trim($xmlName[1]) === $name,
    'plugin.xml name must match the name returned by plugin_version_gdmsintegration().'
);

$pluginKey = basename($root);
$expect(
    preg_match('/<key>\s*([^<]+)\s*<\/key>/i', $xml, $keyMatch) === 1
    && trim($keyMatch[1]) === $pluginKey,
    'plugin.xml key must match the plugin directory name.'
);

preg_match_all('/<num>\s*([^<]+)\s*<\/num>/i', $xml, $versionEntries);
$expect(
    in_array($version, array_map('trim', $versionEntries[1] ?? []), true),
    'plugin.xml version entries must include the version declared by plugin metadata.'
);
$expect(
    str_contains($xml, $pluginKey . '-' . $version . '.zip'),
    'plugin.xml version entry must reference the package for the current metadata version.'
);
$expect(
    preg_match_all('/<compatibility>\s*([^<]+)\s*<\/compatibility>/i', $xml, $compatibilityEntries) > 0
    && count(array_filter(array_map('trim', $compatibilityEntries[1] ?? []))) > 0,
    'plugin.xml must declare at least one non-empty GLPI compatibility entry.'
);
$expect(
    preg_match('/^\s*##\s+\[' . preg_quote($version, '/') . '\]/m', $changelog) === 1,
    'CHANGELOG.md must contain an entry for the current plugin metadata version.'
);

// Validate the plugin's stable public layout, not a list of every implementation
// file. Individual features are covered by behavioral tests and the packager.
$requiredFiles = [
    'CHANGELOG.md',
    'LICENSE',
    'README.md',
    'THIRD-PARTY-NOTICES.md',
    'package.json',
    'scripts/update-vis-network.sh',
    'hook.php',
    'plugin.xml',
    'setup.php',
    'src/API.php',
    'src/Config.php',
    'src/Sync.php',
    'src/Service/FirmwareService.php',
    'templates/config_form.html.twig',
    'templates/dashboard.html.twig',
    'public/js/vis-network.manifest.json',
];

foreach ($requiredFiles as $relative) {
    $expect(
        is_file($root . DIRECTORY_SEPARATOR . $relative),
        sprintf('Required plugin component is missing: %s', $relative)
    );
}

foreach (['locales', 'public/js', 'src/Controller', 'src/Service', 'templates'] as $relative) {
    $expect(
        is_dir($root . DIRECTORY_SEPARATOR . $relative),
        sprintf('Required plugin directory is missing: %s', $relative)
    );
}

$translationSources = glob($root . DIRECTORY_SEPARATOR . 'locales' . DIRECTORY_SEPARATOR . '*.po') ?: [];
$expect($translationSources !== [], 'At least one gettext translation source (.po) must be present.');
foreach ($translationSources as $translationSource) {
    $compiledCatalog = substr($translationSource, 0, -3) . '.mo';
    $expect(
        is_file($compiledCatalog),
        sprintf('Compiled translation catalog is missing for %s.', basename($translationSource))
    );
}

foreach (['composer.json', 'composer.lock', 'vendor'] as $relative) {
    $expect(
        !file_exists($root . DIRECTORY_SEPARATOR . $relative),
        sprintf('Unexpected Composer runtime dependency found at plugin root: %s', $relative)
    );
}

if ($errors !== []) {
    fwrite(STDERR, "Release validation failed:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Plugin metadata and source layout are valid (%s).\n",
    $version
));
