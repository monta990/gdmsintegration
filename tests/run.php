<?php

declare(strict_types=1);

namespace {
    // Minimal GLPI stubs: the tested methods are deterministic and must not
    // require a database, a session, or a live GLPI installation.
    if (!class_exists(\CommonGLPI::class)) {
        abstract class CommonGLPI {}
    }

    if (!function_exists('__')) {
        function __(string $message, ?string $domain = null): string
        {
            return $message;
        }
    }
}

namespace Glpi\RichText {
    final class RichText
    {
        public static int $calls = 0;

        public static function getSafeHtml(string $html): string
        {
            self::$calls++;
            return $html;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/src/Utils.php';
    require_once dirname(__DIR__) . '/src/Sync.php';
    require_once dirname(__DIR__) . '/src/Service/FirmwareService.php';

    use GlpiPlugin\Gdmsintegration\Sync;
    use GlpiPlugin\Gdmsintegration\Utils;
    use GlpiPlugin\Gdmsintegration\Service\FirmwareService;
    use Glpi\RichText\RichText;

    $assertSame = static function (mixed $expected, mixed $actual, string $message): void {
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . PHP_EOL
                . 'Expected: ' . var_export($expected, true) . PHP_EOL
                . 'Actual:   ' . var_export($actual, true)
            );
        }
    };

    $assertTrue = static function (bool $condition, string $message): void {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    $assertFalse = static function (bool $condition, string $message): void {
        if ($condition) {
            throw new RuntimeException($message);
        }
    };

    $invokePrivate = static function (string $method, array $arguments = []): mixed {
        $reflection = new ReflectionMethod(FirmwareService::class, $method);
        return $reflection->invokeArgs(null, $arguments);
    };

    $tests = [
        'SLA thresholds and boundary values' => static function () use ($assertSame): void {
            $cases = [
                [100.0, 'Gold'],
                [99.9, 'Gold'],
                [99.89, 'Silver'],
                [99.0, 'Silver'],
                [98.99, 'Bronze'],
                [95.0, 'Bronze'],
                [94.99, 'Critical'],
                [0.0, 'Critical'],
            ];

            foreach ($cases as [$uptime, $expected]) {
                $assertSame(
                    $expected,
                    Sync::slaLabel($uptime),
                    sprintf('Unexpected SLA label for %.2f%% availability.', $uptime)
                );
            }
        },

        'Ticket HTML escapes untrusted dynamic values' => static function () use ($assertTrue, $assertFalse, $assertSame): void {
            RichText::$calls = 0;

            $html = Utils::buildTicketHtml(
                '<img src=x onerror="alert(1)">',
                "First line <script>alert('x')</script>\nSecond line & details",
                [
                    ['label' => 'MAC <b>address</b>', 'value' => 'AA:BB:CC "quoted" and it\'s <svg onload=x>'],
                    ['invalid-row'],
                    ['label' => ['not scalar'], 'value' => (object) ['unsafe' => true]],
                ],
                'Footer <iframe src=x>',
                'Reference "GDMS" <script>x</script>'
            );

            $assertTrue(str_contains($html, '&lt;img'), 'The heading must be HTML-escaped.');
            $assertTrue(str_contains($html, '&lt;script&gt;'), 'The summary must escape script tags.');
            $assertTrue(str_contains($html, '&lt;svg'), 'Row values must be HTML-escaped.');
            $assertTrue(str_contains($html, '&lt;iframe'), 'The footer must be HTML-escaped.');
            $assertTrue(str_contains($html, '&quot;'), 'Double quotes must be escaped.');
            $assertTrue(str_contains($html, '&#039;'), 'Single quotes must be escaped.');
            $assertTrue(str_contains($html, '<br>'), 'Multiline values must preserve line breaks.');
            $assertFalse(str_contains($html, '<script>'), 'No untrusted script element may remain executable.');
            $assertFalse(str_contains($html, '<img'), 'No untrusted image element may remain executable.');
            $assertFalse(str_contains($html, 'onerror='), 'Event-handler attributes must be escaped.');
            $assertFalse(str_contains($html, 'not scalar'), 'Non-scalar values must not be stringified.');
            $assertSame(1, RichText::$calls, 'The completed ticket HTML must pass through GLPI rich-text sanitization.');
        },

        'Ticket follow-up note escapes markup and preserves newlines' => static function () use ($assertTrue, $assertFalse, $assertSame): void {
            RichText::$calls = 0;
            $html = Utils::ticketHtmlNote("Recovered <script>alert(1)</script>\nWAN: <b>ISP</b>");

            $assertTrue(str_contains($html, 'Recovered &lt;script&gt;'), 'The note must escape script markup.');
            $assertTrue(str_contains($html, '&lt;b&gt;ISP&lt;/b&gt;'), 'The note must escape formatting supplied as data.');
            $assertTrue(str_contains($html, '<br>'), 'The note must preserve line breaks.');
            $assertFalse(str_contains($html, '<script>'), 'The note must not contain an executable script element.');
            $assertSame(1, RichText::$calls, 'The follow-up note must use GLPI rich-text sanitization.');
        },

        'Firmware MAC normalization' => static function () use ($assertSame): void {
            $assertSame(
                'AA:BB:CC:DD:EE:FF',
                $invokePrivate('normalizeMacAddress', ['aa-bb-cc-dd-ee-ff']),
                'Hyphenated lowercase MAC addresses must normalize to uppercase colon notation.'
            );
            $assertSame(
                '00:11:22:33:44:55',
                $invokePrivate('normalizeMacAddress', ['00:11:22:33:44:55']),
                'Colonized MAC addresses must remain canonical.'
            );
            $assertSame('', $invokePrivate('normalizeMacAddress', ['not-a-mac']), 'Invalid MAC lengths must be rejected.');
        },

        'Firmware version format validation' => static function () use ($assertTrue, $assertFalse): void {
            $assertTrue($invokePrivate('isFirmwareVersion', ['1.0.7']), 'Three-part firmware versions must be accepted.');
            $assertTrue($invokePrivate('isFirmwareVersion', ['1.0.7.71']), 'Four-part firmware versions must be accepted.');
            $assertFalse($invokePrivate('isFirmwareVersion', ['1.0']), 'Incomplete versions must be rejected.');
            $assertFalse($invokePrivate('isFirmwareVersion', ['1.0.7-beta']), 'Pre-release suffixes must not be treated as stable numeric versions.');
            $assertFalse($invokePrivate('isFirmwareVersion', ['']), 'Empty firmware versions must be rejected.');
        },

        'Trusted firmware download hosts' => static function () use ($assertTrue, $assertFalse): void {
            $assertTrue(
                $invokePrivate('isTrustedFirmwareDownloadUrl', ['https://firmware.grandstream.com/grp2600fw.bin']),
                'The official Grandstream firmware host must be accepted.'
            );
            $assertTrue(
                $invokePrivate('isTrustedFirmwareDownloadUrl', ['https://fw.gdms.cloud/path/firmware.bin']),
                'The GDMS firmware host must be accepted.'
            );
            $assertFalse(
                $invokePrivate('isTrustedFirmwareDownloadUrl', ['https://firmware.grandstream.com.attacker.example/file.bin']),
                'A lookalike host must be rejected.'
            );
            $assertFalse(
                $invokePrivate('isTrustedFirmwareDownloadUrl', ['https://attacker.example/firmware.bin']),
                'An arbitrary host must be rejected.'
            );
            $assertFalse(
                $invokePrivate('isTrustedFirmwareDownloadUrl', ['file:///etc/passwd']),
                'Non-HTTP URL schemes must be rejected.'
            );
        },

        'Firmware scheduling requires the exact newer official stable version' => static function () use ($assertTrue, $assertFalse): void {
            $assertTrue(
                $invokePrivate('canScheduleStableFirmware', ['1.0.7.66', '1.0.7.71', '1.0.7.71']),
                'A verified official version newer than the installed version must be schedulable.'
            );
            $assertFalse(
                $invokePrivate('canScheduleStableFirmware', ['1.0.7.66', '1.0.7.70', '1.0.7.71']),
                'A requested version different from the verified official version must be rejected.'
            );
            $assertFalse(
                $invokePrivate('canScheduleStableFirmware', ['1.0.7.71', '1.0.7.66', '1.0.7.71']),
                'A firmware downgrade must be rejected.'
            );
            $assertFalse(
                $invokePrivate('canScheduleStableFirmware', ['1.0.7.71', '1.0.7.71', '1.0.7.71']),
                'Reinstalling the currently installed version must be rejected.'
            );
            $assertFalse(
                $invokePrivate('canScheduleStableFirmware', ['1.0.7-beta', '1.0.7.71', '1.0.7.71']),
                'A non-numeric installed version must not be eligible for automatic scheduling.'
            );
            $assertFalse(
                $invokePrivate('canScheduleStableFirmware', ['1.0.7.66', '1.0.7.71-beta', '1.0.7.71']),
                'A beta target must not be treated as a stable firmware version.'
            );
        },

        'Firmware CDN mapping is explicit and refuses unknown families' => static function () use ($assertSame): void {
            $assertSame(
                'https://firmware.grandstream.com/grp2600fw.bin',
                $invokePrivate('gdmsCdnUrl', ['GRP-2604']),
                'GRP260x phones must use the known official firmware family path.'
            );
            $assertSame(
                'https://firmware.grandstream.com/ht801fw.bin',
                $invokePrivate('gdmsCdnUrl', ['HT801']),
                'HT801 must use its explicit official firmware mapping.'
            );
            $assertSame('', $invokePrivate('gdmsCdnUrl', ['GWN7660']), 'GWN devices must not use the UC CDN mapping.');
            $assertSame('', $invokePrivate('gdmsCdnUrl', ['UNKNOWN-DEVICE']), 'Unknown models must not receive an invented firmware URL.');
        },
    ];

    $failed = 0;
    foreach ($tests as $name => $test) {
        try {
            $test();
            fwrite(STDOUT, "[PASS] {$name}\n");
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDERR, "[FAIL] {$name}: {$error->getMessage()}\n");
        }
    }

    $total = count($tests);
    $passed = $total - $failed;
    fwrite($failed === 0 ? STDOUT : STDERR, sprintf("\n%d/%d tests passed.\n", $passed, $total));

    exit($failed === 0 ? 0 : 1);
}
