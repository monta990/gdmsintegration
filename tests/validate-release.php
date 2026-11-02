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

$setup = $read('setup.php');
$xml = $read('plugin.xml');

$expect(
    preg_match("/define\\('PLUGIN_GDMSINTEGRATION_VERSION',\\s*'([^']+)'\\);/", $setup, $versionMatch) === 1,
    'setup.php must declare PLUGIN_GDMSINTEGRATION_VERSION.'
);

$version = $versionMatch[1] ?? '';

$expect($version !== '', 'The plugin version must not be empty.');
$expect(
    preg_match("/'php'\\s*=>\\s*\\['min'\\s*=>\\s*'8\\.2'\\]/", $setup) === 1,
    'setup.php must keep PHP 8.2 as the declared minimum.'
);
$expect(
    str_contains($setup, "require_once __DIR__ . '/hook.php';"),
    'setup.php must continue to load hook.php through the existing require_once bootstrap.'
);
$expect(
    preg_match('/<key>gdmsintegration<\\/key>/', $xml) === 1
    && preg_match('/<name>GDMS Integration<\\/name>/', $xml) === 1,
    'plugin.xml must identify the gdmsintegration plugin.'
);

$versionEntries = [];
preg_match_all('/<num>\\s*([^<]+)\\s*<\\/num>/', $xml, $versionEntries);
$expect(
    in_array($version, $versionEntries[1] ?? [], true),
    'plugin.xml version entries must include the version declared by setup.php.'
);
$expect(
    str_contains($xml, '<compatibility>~11.0</compatibility>')
    && str_contains($xml, '<compatibility>~12.0</compatibility>'),
    'plugin.xml must continue to advertise both GLPI 11.0 and GLPI 12.0 compatibility.'
);
$expect(
    str_contains($xml, 'gdmsintegration-' . $version . '.zip'),
    'plugin.xml download URL must reference the current plugin version.'
);

$required = [
    'CHANGELOG.md',
    'LICENSE',
    'README.md',
    'config.png',
    'hook.php',
    'logo.png',
    'plugin.xml',
    'setup.php',
    'user.png',
    'locales/es_MX.po',
    'locales/es_MX.mo',
    'locales/gdmsintegration.pot',
    'public/js/vis-network.min.js',
    'src/API.php',
    'src/BaseGLPI.php',
    'src/BaseTM.php',
    'src/Config.php',
    'src/Controller/GdmsController.php',
    'src/Device.php',
    'src/History.php',
    'src/Link.php',
    'src/Menu.php',
    'src/Service/AccessService.php',
    'src/Service/AlertService.php',
    'src/Service/BulkService.php',
    'src/Service/ClientService.php',
    'src/Service/ConfigService.php',
    'src/Service/DashboardService.php',
    'src/Service/FirmwareService.php',
    'src/Service/HistoryExportService.php',
    'src/Service/NetworkLocationService.php',
    'src/Service/PortService.php',
    'src/Service/SyncService.php',
    'src/Sync.php',
    'src/Twig.php',
    'src/Utils.php',
    'templates/config_form.html.twig',
    'templates/dashboard.html.twig',
];

foreach ($required as $relative) {
    $expect(
        is_file($root . DIRECTORY_SEPARATOR . $relative),
        sprintf('Required plugin file is missing: %s', $relative)
    );
}

foreach (['locales', 'public/js', 'src/Controller', 'src/Service', 'templates'] as $relative) {
    $expect(
        is_dir($root . DIRECTORY_SEPARATOR . $relative),
        sprintf('Required plugin directory is missing: %s', $relative)
    );
}

foreach (['composer.json', 'composer.lock', 'vendor'] as $relative) {
    $path = $root . DIRECTORY_SEPARATOR . $relative;
    $expect(
        !file_exists($path),
        sprintf('Unexpected Composer runtime dependency found at plugin root: %s', $relative)
    );
}

if ($errors !== []) {
    fwrite(STDERR, "Release validation failed:\n - " . implode("\n - ", $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "GDMS Integration %s metadata and source layout are valid.\n",
    $version
));
