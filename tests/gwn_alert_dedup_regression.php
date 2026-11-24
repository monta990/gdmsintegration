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

            /** @var array<string, mixed>|null */
            public static ?array $findCriteria = null;

            public static int $findCalls = 0;
            public static int $findArgumentCount = 0;
            public static int $instancesCreated = 0;
            public static ?int $findInstanceId = null;

            /** @var array<int, int> */
            public static array $addInstanceIds = [];

            private int $instanceId;

            public function __construct()
            {
                $this->instanceId = ++self::$instancesCreated;
            }

            public function find(array $criteria, array $order = [], int $limit = 0): array
            {
                self::$findInstanceId = $this->instanceId;
                self::$findCalls++;
                self::$findCriteria = $criteria;
                self::$findArgumentCount = func_num_args();

                return self::$existingRows;
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
                self::$findCriteria = null;
                self::$findCalls = 0;
                self::$findArgumentCount = 0;
                self::$instancesCreated = 0;
                self::$findInstanceId = null;
                self::$addInstanceIds = [];
            }
        }
    }

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
            [],
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

    // Successful Ticket::add() results are added to the production candidate
    // cache, preventing a repeated provider ID from creating another ticket.
    Ticket::reset([], [101, 102]);
    $runBatch([$makeAlert('duplicate-success'), $makeAlert('duplicate-success')]);
    $assertSame(1, Ticket::$findCalls, 'A successful batch must query ticket history exactly once.');
    $assertSame(1, Ticket::$findArgumentCount, 'Ticket history must be fetched without a row limit.');
    $assertSame(
        ['entities_id' => 42, 'name' => ['LIKE', '%[GWN-ALERT:%]%']],
        Ticket::$findCriteria,
        'Production batch lookup must use a constant marker candidate pattern.',
    );
    $assertSame(1, count(Ticket::$addAttempts), 'A repeated successfully created alert must create only one ticket.');
    $assertSame(1, count(Ticket::$successfulAdds), 'Exactly one ticket should be created for a repeated successful alert ID.');
    $assertSame(2, Ticket::$instancesCreated, 'The history lookup and successful ticket creation must use separate Ticket objects.');
    $assertSame(1, count(array_unique(Ticket::$addInstanceIds)), 'The successful ticket add must use its own Ticket instance.');
    $assertSame(false, in_array(Ticket::$findInstanceId, Ticket::$addInstanceIds, true), 'The history lookup Ticket instance must not be reused for ticket creation.');
    $assertSame(
        true,
        str_contains((string) Ticket::$successfulAdds[0]['name'], '[GWN-ALERT:duplicate-success]'),
        'The successful ticket must retain its exact alert marker.',
    );

    // Failed Ticket::add() results must not enter the cache: the same provider ID
    // stays eligible and can succeed when it is encountered again in this batch.
    Ticket::reset([], [0, 202]);
    $runBatch([$makeAlert('retry-after-failure'), $makeAlert('retry-after-failure')]);
    $assertSame(1, Ticket::$findCalls, 'The retry batch must still perform only one ticket history lookup.');
    $assertSame(2, count(Ticket::$addAttempts), 'A failed alert creation must be retried when the ID repeats.');
    $assertSame(1, count(Ticket::$successfulAdds), 'The retry should result in one successfully created ticket.');
    $assertSame(3, Ticket::$instancesCreated, 'The failed and retried creations must each receive a fresh Ticket object in addition to the history lookup object.');
    $assertSame(2, count(array_unique(Ticket::$addInstanceIds)), 'Each failed/successful add attempt must use a distinct Ticket instance.');
    $assertSame(false, in_array(Ticket::$findInstanceId, Ticket::$addInstanceIds, true), 'The history lookup Ticket instance must remain separate from all add attempts.');

    // A SQL LIKE wildcard lookalike must not suppress a different alert ID.
    Ticket::reset([['name' => '[GDMS] previous [GWN-ALERT:siteA1]']], [303]);
    $runBatch([$makeAlert('site_1')]);
    $assertSame(1, count(Ticket::$addAttempts), 'An underscore ID must not match another marker through SQL LIKE semantics.');
    $assertSame(1, Ticket::$findCalls, 'Existing-ticket detection must use the shared batch lookup.');

    // An exact existing marker, regardless of ticket status, must suppress creation.
    Ticket::reset([['name' => '[GDMS] previous [GWN-ALERT:site_1]']], [404]);
    $runBatch([$makeAlert('site_1')]);
    $assertSame(0, count(Ticket::$addAttempts), 'An exact existing alert marker must suppress duplicate ticket creation.');
    $assertSame(1, Ticket::$findCalls, 'Exact existing-marker detection must reuse the same single history lookup.');

    fwrite(STDOUT, sprintf("PASS: %d GWN alert deduplication regression assertions.\n", $assertions));
}
