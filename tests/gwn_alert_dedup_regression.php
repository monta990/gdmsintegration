<?php

declare(strict_types=1);

namespace {
    use GlpiPlugin\Gdmsintegration\API;
    use GlpiPlugin\Gdmsintegration\Sync;

    if (!function_exists('__')) {
        function __(string $message, ?string $domain = null): string
        {
            return $message;
        }
    }

    if (!class_exists('CommonGLPI')) {
        abstract class CommonGLPI {}
    }

    if (!class_exists('Ticket')) {
        class Ticket
        {
            public const INCOMING = 1;
            public const INCIDENT_TYPE = 1;

            /** @var array<int, array<string, mixed>> */
            public static array $existingRows = [];

            /** @var array<int, int> */
            public static array $addResults = [];

            /** @var array<int, array<string, mixed>> */
            public static array $addAttempts = [];

            /** @var array<int, array<string, mixed>> */
            public static array $successfulAdds = [];

            public static int $instancesCreated = 0;

            /** @var array<int, int> */
            public static array $addInstanceIds = [];

            private int $instanceId;

            public function __construct()
            {
                $this->instanceId = ++self::$instancesCreated;
            }

            public static function getTable(): string
            {
                return 'glpi_tickets';
            }

            /** @param array<string, mixed> $input */
            public function add(array $input): int
            {
                self::$addInstanceIds[] = $this->instanceId;
                self::$addAttempts[] = $input;
                $result = (int) (array_shift(self::$addResults) ?? 0);
                if ($result > 0) {
                    self::$successfulAdds[] = $input;
                }

                return $result;
            }

            /** @param array<int, array<string, mixed>> $existingRows
             *  @param array<int, int> $addResults
             */
            public static function reset(array $existingRows = [], array $addResults = []): void
            {
                self::$existingRows = $existingRows;
                self::$addResults = $addResults;
                self::$addAttempts = [];
                self::$successfulAdds = [];
                self::$instancesCreated = 0;
                self::$addInstanceIds = [];
                RegressionDatabase::reset();
            }
        }
    }

    if (!class_exists('RegressionDatabase')) {
        class RegressionDatabase
        {
            /** @var array<int, array<string, mixed>> */
            public static array $requests = [];

            /**
             * @param array<string, mixed> $query
             * @return iterable<int, array<string, mixed>>
             */
            public function request(array $query): iterable
            {
                self::$requests[] = $query;

                return Ticket::$existingRows;
            }

            public static function reset(): void
            {
                self::$requests = [];
            }
        }
    }

    $DB = new RegressionDatabase();

    if (!class_exists('NetworkEquipment')) {
        class NetworkEquipment
        {
            public function find(array $criteria): array
            {
                return [];
            }
        }
    }

    if (!class_exists('Phone')) {
        class Phone
        {
            public function find(array $criteria): array
            {
                return [];
            }
        }
    }
}

namespace GlpiPlugin\Gdmsintegration {
    class API
    {
        /** @var array<int, array<int, array<string, mixed>>> */
        public static array $alertsByNetwork = [];

        public static function gwnGetAlertsBatch(
            array $config,
            array $networkIds,
            int $pageSize = 50,
            ?array &$completeByNetwork = null,
        ): array {
            $completeByNetwork = array_fill_keys(array_map('intval', $networkIds), true);

            return self::$alertsByNetwork;
        }
    }

    class Config
    {
        public static function validateTicketCategoryId(int $categoryId, int $entitiesId): int
        {
            return $categoryId;
        }

        public static function validateRequestTypeId(int $requestTypeId, int $entitiesId = 0): int
        {
            return $requestTypeId;
        }

        public static function ensureGdmsRequestType(): int
        {
            return 1;
        }
    }

    class Utils
    {
        public static function buildTicketHtml(
            string $heading,
            string $summary,
            array $rows,
            string $footer = '',
            string $reference = '',
        ): string {
            return '<p>' . htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        }

        public static function log(string $message): void {}

        public static function debug(string $message): void {}
    }
}

namespace {
    require_once dirname(__DIR__) . '/src/Sync.php';

    $assertions = 0;
    $assertSame = static function (mixed $expected, mixed $actual, string $message) use (&$assertions): void {
        $assertions++;
        if ($expected !== $actual) {
            throw new RuntimeException(
                $message . PHP_EOL
                . 'Expected: ' . var_export($expected, true) . PHP_EOL
                . 'Actual: ' . var_export($actual, true),
            );
        }
    };

    $markerMethod = new ReflectionMethod(\GlpiPlugin\Gdmsintegration\Sync::class, 'hasExactGwnAlertMarker');
    $markerMethod->setAccessible(true);
    $alertUnderscore = '[GWN-ALERT:site_1]';
    $assertSame(true, $markerMethod->invoke(null, ['Device alert ' . $alertUnderscore], $alertUnderscore), 'Exact underscore-containing ID must match.');
    $assertSame(false, $markerMethod->invoke(null, ['Device alert [GWN-ALERT:siteA1]'], $alertUnderscore), 'Underscore must not behave as a wildcard.');
    $assertSame(false, $markerMethod->invoke(null, ['Device alert [GWN-ALERT:site_10]'], $alertUnderscore), 'A longer ID must not match a shorter ID.');
    $assertSame(true, $markerMethod->invoke(null, ['Device [GWN-ALERT:alert%1]'], '[GWN-ALERT:alert%1]'), 'Percent signs in IDs must be compared literally.');
    $assertSame(false, $markerMethod->invoke(null, ['No alert marker'], $alertUnderscore), 'Unrelated ticket names must not match.');

    $syncMethod = new ReflectionMethod(\GlpiPlugin\Gdmsintegration\Sync::class, 'syncGwnAlertTickets');
    $syncMethod->setAccessible(true);
    $runBatch = static function (array $alerts) use ($syncMethod): void {
        \GlpiPlugin\Gdmsintegration\API::$alertsByNetwork = [7 => $alerts];
        $syncMethod->invoke(
            null,
            [
                'gwn_alert_tickets_enabled' => 1,
                'ticket_requesttype_id' => 23,
            ],
            42,
            [7],
        );
    };
    $makeAlert = static function (string $id): array {
        return [
            'id' => $id,
            'severity' => 'critical',
            'category' => 'device',
            'description' => 'Test cloud alert',
            'deviceName' => 'Test GWN device',
        ];
    };

    // Entirely filtered batches must not load ticket history.
    Ticket::reset();
    $ignoredSeverity = $makeAlert('ignored-severity');
    $ignoredSeverity['severity'] = 'info';
    $offlineAlert = $makeAlert('offline-alert');
    $offlineAlert['category'] = 'offline';
    $missingId = $makeAlert('');
    $staleAlert = $makeAlert('stale-alert');
    $staleAlert['createTime'] = (time() - (31 * 86400)) * 1000;
    $runBatch([$ignoredSeverity, $offlineAlert, $missingId, $staleAlert]);
    $assertSame(0, count(RegressionDatabase::$requests), 'A batch without actionable alerts must not query ticket history.');
    $assertSame(0, count(Ticket::$addAttempts), 'Filtered alerts must not create tickets.');

    // Successful Ticket::add() results are added to the production candidate
    // cache, preventing a repeated provider ID from creating another ticket.
    Ticket::reset([], [101, 102]);
    $runBatch([$makeAlert('duplicate-success'), $makeAlert('duplicate-success')]);
    $assertSame(1, count(RegressionDatabase::$requests), 'A successful batch must query ticket history exactly once.');
    $assertSame(
        [
            'SELECT' => 'name',
            'FROM' => 'glpi_tickets',
            'WHERE' => ['entities_id' => 42, 'name' => ['LIKE', '%[GWN-ALERT:%]%']],
        ],
        RegressionDatabase::$requests[0],
        'The shared history query must select only names and use a constant marker candidate pattern.',
    );
    $assertSame(1, count(Ticket::$addAttempts), 'A repeated successfully created alert must create only one ticket.');
    $assertSame(1, count(Ticket::$successfulAdds), 'Exactly one ticket should be created for a repeated successful alert ID.');
    $assertSame(1, Ticket::$instancesCreated, 'History lookup must not instantiate a full Ticket model.');
    $assertSame(1, count(array_unique(Ticket::$addInstanceIds)), 'The successful ticket add must use its own Ticket instance.');
    $assertSame(
        true,
        str_contains((string) Ticket::$successfulAdds[0]['name'], '[GWN-ALERT:duplicate-success]'),
        'The successful ticket must retain its exact alert marker.',
    );

    // Failed Ticket::add() results must not enter the cache: the same provider ID
    // stays eligible and can succeed when it is encountered again in this batch.
    Ticket::reset([], [0, 202]);
    $runBatch([$makeAlert('retry-after-failure'), $makeAlert('retry-after-failure')]);
    $assertSame(1, count(RegressionDatabase::$requests), 'The retry batch must still perform only one ticket history lookup.');
    $assertSame(2, count(Ticket::$addAttempts), 'A failed alert creation must be retried when the ID repeats.');
    $assertSame(1, count(Ticket::$successfulAdds), 'The retry should result in one successfully created ticket.');
    $assertSame(2, Ticket::$instancesCreated, 'Failed and retried creations each receive a fresh Ticket object; history lookup does not instantiate Ticket.');
    $assertSame(2, count(array_unique(Ticket::$addInstanceIds)), 'Each failed/successful add attempt must use a distinct Ticket instance.');

    // A SQL LIKE wildcard lookalike must not suppress a different alert ID.
    Ticket::reset([['name' => '[GDMS] previous [GWN-ALERT:siteA1]']], [303]);
    $runBatch([$makeAlert('site_1')]);
    $assertSame(1, count(Ticket::$addAttempts), 'An underscore ID must not match another marker through SQL LIKE semantics.');
    $assertSame(1, count(RegressionDatabase::$requests), 'Existing-ticket detection must use the shared batch lookup.')

    // An exact existing marker, regardless of ticket status, must suppress creation.
    Ticket::reset([['name' => '[GDMS] previous [GWN-ALERT:site_1]']], [404]);
    $runBatch([$makeAlert('site_1')]);
    $assertSame(0, count(Ticket::$addAttempts), 'An exact existing alert marker must suppress duplicate ticket creation.');
    $assertSame(1, count(RegressionDatabase::$requests), 'Exact existing-marker detection must reuse the same single history lookup.')

    fwrite(STDOUT, sprintf("PASS: %d GWN alert deduplication regression assertions.\n", $assertions));
}
