<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root . '/public/js/vis-network.manifest.json';
$bundlePath = $root . '/public/js/vis-network.min.js';
$manifest = is_file($manifestPath) ? json_decode((string) file_get_contents($manifestPath), true) : null;
if (!is_array($manifest)) {
    throw new RuntimeException('vis-network manifest is missing or invalid.');
}
if (($manifest['name'] ?? null) !== 'vis-network' || ($manifest['version'] ?? null) !== '10.1.5') {
    throw new RuntimeException('vis-network version must match the pinned dependency 10.1.5.');
}
if (($manifest['license'] ?? null) !== '(Apache-2.0 OR MIT)') {
    throw new RuntimeException('vis-network dual license is not documented.');
}
$sha = (string) ($manifest['sha256'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
    throw new RuntimeException('vis-network manifest does not include a generated SHA-256 digest.');
}
if (!is_file($bundlePath) || !hash_equals($sha, hash_file('sha256', $bundlePath) ?: '')) {
    throw new RuntimeException('vis-network bundle does not match its manifest SHA-256 digest.');
}
$noticesPath = $root . '/THIRD-PARTY-NOTICES.md';
$notices = is_file($noticesPath) ? (string) file_get_contents($noticesPath) : '';
foreach ([
    'Apache License',
    'Version 2.0, January 2004',
    'END OF TERMS AND CONDITIONS',
    'The MIT License (MIT)',
    'Copyright (c) 2014-2017 Almende B.V.',
    'all copies or substantial portions of the Software',
] as $requiredNoticeText) {
    if (!str_contains($notices, $requiredNoticeText)) {
        throw new RuntimeException('Third-party notices are missing license text: ' . $requiredNoticeText);
    }
}
foreach ([
    $root . '/public/js/vis-network.LICENSE-APACHE-2.0',
    $root . '/public/js/vis-network.LICENSE-MIT',
] as $legacyLicenseCopy) {
    if (is_file($legacyLicenseCopy)) {
        throw new RuntimeException('Legacy generated license copy must not remain under public/js.');
    }
}

fwrite(STDOUT, "PASS: vis-network 10.1.5 manifest, checksum and consolidated license notices verified.\\n");