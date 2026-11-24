<?php

declare(strict_types=1);

if (!function_exists('__')) {
    function __(string $message, ?string $domain = null): string
    {
        return $message;
    }
}

require_once dirname(__DIR__) . '/src/API.php';

use GlpiPlugin\Gdmsintegration\API;

$assertSame = static function (mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . PHP_EOL . 'Expected: ' . var_export($expected, true) . PHP_EOL . 'Actual: ' . var_export($actual, true));
    }
};

$cache = new class {
    public array $items = [];
    public array $ttls = [];

    public function get(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl): bool
    {
        $this->items[$key] = $value;
        $this->ttls[$key] = $ttl;
        return true;
    }
};

$method = new ReflectionMethod(API::class, 'getFirmwareCatalogUsingCache');
$method->setAccessible(true);
$key = 'gdmsintegration.grandstream_firmware_catalog.v1';
$valid = ['ok' => true, 'models' => ['GWN7001' => ['official' => '1.0.11.61', 'beta' => '1.0.11.63', 'modelLabel' => 'GWN7001']], 'error' => null];
$fetches = 0;
$fetcher = static function () use (&$fetches, $valid): array {
    $fetches++;
    return $valid;
};

$first = $method->invoke(null, $fetcher, $cache, 600);
$assertSame($valid, $first, 'Cache miss must return fetched catalogue.');
$assertSame(1, $fetches, 'First request must fetch the source once.');
$assertSame(600, $cache->ttls[$key] ?? null, 'Catalogue must use the configured ten-minute TTL.');

$second = $method->invoke(null, $fetcher, $cache, 600);
$assertSame($valid, $second, 'Cache hit must return the valid shared catalogue.');
$assertSame(1, $fetches, 'Cache hit must not make another vendor request.');

$cache->items[$key] = ['ok' => false, 'models' => []];
$third = $method->invoke(null, $fetcher, $cache, 600);
$assertSame($valid, $third, 'Invalid cached entries must be discarded and refreshed.');
$assertSame(2, $fetches, 'Invalid cache entry must trigger exactly one refresh.');

$cache->items[$key] = null;
$failed = $method->invoke(null, static fn(): array => ['ok' => false, 'models' => [], 'error' => 'offline'], $cache, 600);
$assertSame(false, $failed['ok'], 'Failed upstream responses must be returned as errors.');
$assertSame(null, $cache->items[$key], 'Failed upstream responses must not be cached.');

$withoutCache = $method->invoke(null, $fetcher, null, 600);
$assertSame($valid, $withoutCache, 'Live fetch must still work if GLPI cache is unavailable.');

fwrite(STDOUT, "PASS: 10 firmware catalogue cache regression assertions.\n");
