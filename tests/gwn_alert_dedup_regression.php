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

if (!class_exists('Ticket')) {
    class Ticket
    {
        /** @var array<int, array<string, mixed>> */
        public array $rows = [];

        /** @var array<string, mixed>|null */
        public ?array $criteria = null;

        public int $argumentCount = 0;

        public function find(array $criteria, array $order = [], int $limit = 0): array
        {
            $this->criteria = $criteria;
            $this->argumentCount = func_num_args();

            return $this->rows;
        }
    }
}

require_once dirname(__DIR__) . '/src/Sync.php';

use GlpiPlugin\Gdmsintegration\Sync;

$method = new ReflectionMethod(Sync::class, 'hasExactGwnAlertMarker');
$method->setAccessible(true);
$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
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

$lookup = new ReflectionMethod(Sync::class, 'hasExistingGwnAlertTicket');
$lookup->setAccessible(true);
$ticket = new Ticket();
$ticket->rows = [['name' => '[GDMS] existing [GWN-ALERT:siteA1]']];
$assertSame(false, $lookup->invoke(null, $ticket, 1, $alertUnderscore), 'Production lookup must not treat underscore as a wildcard.');
$assertSame(['entities_id' => 1, 'name' => ['LIKE', '%[GWN-ALERT:%]%']], $ticket->criteria, 'Production lookup must use a constant LIKE pattern.');
$assertSame(1, $ticket->argumentCount, 'Production lookup must retrieve all candidate rows without a one-row limit.');

$ticket->rows = [['name' => '[GDMS] existing [GWN-ALERT:site_1]']];
$assertSame(true, $lookup->invoke(null, $ticket, 1, $alertUnderscore), 'Production lookup must recognize an exact existing marker.');
$ticket->rows = [
    ['name' => '[GDMS] siteA1 [GWN-ALERT:siteA1]'],
    ['name' => '[GDMS] site_1 [GWN-ALERT:site_1]'],
];
$assertSame(true, $lookup->invoke(null, $ticket, 1, $alertUnderscore), 'Production lookup must scan all candidate rows for an exact marker.');

fwrite(STDOUT, "PASS: 6 GWN alert deduplication regression assertions.\n");
