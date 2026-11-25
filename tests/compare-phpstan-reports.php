<?php

declare(strict_types=1);

/**
 * Compare PHPStan JSON reports by file, identifier and exact message.
 *
 * Source line numbers are intentionally omitted from the fingerprint so moving
 * an existing diagnostic does not make it appear new. Occurrence counts are
 * retained to catch duplicated findings introduced by a change.
 */

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php compare-phpstan-reports.php <target-report.json> <pull-request-report.json>\n");
    exit(2);
}

$loadReport = static function (string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException('PHPStan report does not exist: ' . $path);
    }

    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (
        !is_array($decoded)
        || !isset($decoded['files'], $decoded['errors'])
        || !is_array($decoded['files'])
        || !is_array($decoded['errors'])
    ) {
        throw new RuntimeException('PHPStan report has an unexpected JSON shape: ' . $path);
    }

    return $decoded;
};

$collectDiagnostics = static function (array $report): array {
    $diagnostics = [];

    foreach ($report['files'] as $file => $details) {
        if (!is_array($details)) {
            continue;
        }

        foreach (($details['messages'] ?? []) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $text = (string) ($message['message'] ?? '');
            $identifier = (string) ($message['identifier'] ?? '');
            $fingerprint = json_encode(
                [$file, $identifier, $text],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );

            $diagnostics[$fingerprint]['count'] = ($diagnostics[$fingerprint]['count'] ?? 0) + 1;
            $diagnostics[$fingerprint]['file'] = (string) $file;
            $diagnostics[$fingerprint]['identifier'] = $identifier;
            $diagnostics[$fingerprint]['message'] = $text;
            $diagnostics[$fingerprint]['lines'][] = (int) ($message['line'] ?? 0);
        }
    }

    foreach ($report['errors'] as $error) {
        $message = is_string($error) ? $error : json_encode($error, JSON_THROW_ON_ERROR);
        $fingerprint = json_encode(['@global', '', $message], JSON_THROW_ON_ERROR);

        $diagnostics[$fingerprint]['count'] = ($diagnostics[$fingerprint]['count'] ?? 0) + 1;
        $diagnostics[$fingerprint]['file'] = '@global';
        $diagnostics[$fingerprint]['identifier'] = '';
        $diagnostics[$fingerprint]['message'] = $message;
        $diagnostics[$fingerprint]['lines'][] = 0;
    }

    return $diagnostics;
};

try {
    $target = $collectDiagnostics($loadReport($argv[1]));
    $pullRequest = $collectDiagnostics($loadReport($argv[2]));

    $introduced = [];
    foreach ($pullRequest as $fingerprint => $diagnostic) {
        $targetCount = (int) ($target[$fingerprint]['count'] ?? 0);
        $introducedCount = (int) $diagnostic['count'] - $targetCount;
        for ($i = 0; $i < $introducedCount; $i++) {
            $introduced[] = $diagnostic;
        }
    }

    if ($introduced !== []) {
        fwrite(STDERR, sprintf(
            "PHPStan found %d new diagnostic(s) compared with the target branch:\n",
            count($introduced),
        ));
        foreach ($introduced as $diagnostic) {
            $line = $diagnostic['lines'][0] ?? 0;
            $identifier = $diagnostic['identifier'] !== ''
                ? ' [' . $diagnostic['identifier'] . ']'
                : '';
            fwrite(
                STDERR,
                sprintf(
                    " - %s:%d%s %s\n",
                    $diagnostic['file'],
                    $line,
                    $identifier,
                    $diagnostic['message'],
                ),
            );
        }
        exit(1);
    }

    $targetCount = array_sum(array_column($target, 'count'));
    $currentCount = array_sum(array_column($pullRequest, 'count'));
    printf(
        "PASS: PHPStan introduced no new diagnostics (%d target-branch, %d pull-request).\n",
        $targetCount,
        $currentCount,
    );
} catch (Throwable $e) {
    fwrite(STDERR, 'PHPStan comparison failed: ' . $e->getMessage() . "\n");
    exit(2);
}
