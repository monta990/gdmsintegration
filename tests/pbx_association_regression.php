<?php
declare(strict_types=1);

namespace {
    if (!class_exists('CommonGLPI')) {
        class CommonGLPI {}
    }
}

namespace GlpiPlugin\Gdmsintegration {
    if (!class_exists(BaseTM::class)) {
        class BaseTM {}
    }
}

namespace {
    use GlpiPlugin\Gdmsintegration\API;
    use GlpiPlugin\Gdmsintegration\Device;
    use GlpiPlugin\Gdmsintegration\Service\PbxAssociationResolver;
    use GlpiPlugin\Gdmsintegration\Sync;
    use GlpiPlugin\Gdmsintegration\Service\DashboardService;

    require_once __DIR__ . '/../src/Service/PbxAssociationResolver.php';
    require_once __DIR__ . '/../src/API.php';
    require_once __DIR__ . '/../src/Device.php';
    require_once __DIR__ . '/../src/Sync.php';
    require_once __DIR__ . '/../src/Service/DashboardService.php';

    $checks = 0;
    $expect = static function (bool $condition, string $message) use (&$checks): void {
        $checks++;
        if (!$condition) {
            throw new RuntimeException($message);
        }
    };

    // Initial dashboard synchronization policy: run only once for a new entity
    // with complete provider credentials and a user who has update permission.
    $fixedNow = strtotime('2026-11-27 22:00:00 UTC');
    $expect(DashboardService::shouldRunDashboardSync(null, 300, true, true, $fixedNow), 'Never-synchronized entity should request an initial full synchronization.');
    $expect(DashboardService::shouldRunDashboardSync('', 300, true, true, $fixedNow), 'Empty last-sync timestamp should request initial synchronization.');
    $expect(!DashboardService::shouldRunDashboardSync('2026-11-27 21:58:00', 300, true, true, $fixedNow), 'A recent sync should not be repeated on every dashboard visit.');
    $expect(DashboardService::shouldRunDashboardSync('2026-11-27 21:50:00', 300, true, true, $fixedNow), 'A stale sync older than the refresh interval should run when the dashboard opens.');
    $expect(DashboardService::shouldRunDashboardSync('invalid timestamp', 300, true, true, $fixedNow), 'An invalid last-sync timestamp should recover by requesting synchronization.');
    $expect(!DashboardService::shouldRunDashboardSync(null, 300, false, true, $fixedNow), 'Read-only dashboard users must not trigger synchronization.');
    $expect(!DashboardService::shouldRunDashboardSync(null, 300, true, false, $fixedNow), 'Incomplete provider credentials must not trigger an automatic synchronization.');

    // Modal SIP account rows resolve the PBX separately for every extension.
    // This supports ATA devices with two accounts, and mixed-PBX SIP devices.
    $sipDisplayPbxs = [
        ['raw_model' => 'UCM6300A', 'name' => 'Primary PBX', 'mac' => '02:00:00:00:01:01', 'private_ip' => '192.0.2.10', 'asset_url' => '/front/networkequipment.form.php?id=801'],
        ['raw_model' => 'UCM6300A', 'name' => 'Secondary PBX', 'mac' => '02:00:00:00:01:02', 'private_ip' => '198.51.100.10', 'asset_url' => '/front/networkequipment.form.php?id=802'],
    ];
    $sipDisplayAccounts = DashboardService::enrichSipAccountsForDisplay([
        ['extension' => '7001', 'server' => '198.51.100.10:5060', 'status' => 'Up'],
        ['extension' => '7002', 'server' => '198.51.100.10', 'status' => 'Up'],
        ['extension' => '8001', 'server' => '192.0.2.10:5060', 'status' => 'Up'],
        ['extension' => '8002', 'server' => '020000000102.a.gdms.cloud:5061', 'status' => 'Up'],
        ['extension' => '9001', 'server' => 'sip.provider.example:5060', 'status' => 'Up'],
    ], $sipDisplayPbxs);
    $expect(($sipDisplayAccounts[0]['pbx_name'] ?? '') === 'Secondary PBX', 'ATA extension 7001 must resolve and display Secondary PBX by its own SIP server IP.');
    $expect(($sipDisplayAccounts[1]['pbx_name'] ?? '') === 'Secondary PBX', 'ATA extension 7002 must resolve and display Secondary PBX by its own SIP server IP.');
    $expect(($sipDisplayAccounts[0]['pbx_url'] ?? '') === '/front/networkequipment.form.php?id=802', 'Secondary PBX label must link to its GLPI PBX asset.');
    $expect(($sipDisplayAccounts[0]['pbx_ip_url'] ?? '') === 'http://198.51.100.10', 'Secondary PBX address must link to its validated management URL.');
    $expect(($sipDisplayAccounts[2]['pbx_name'] ?? '') === 'Primary PBX', 'An account registered to a second UCM must display that UCM instead of the device primary PBX.');
    $expect(($sipDisplayAccounts[3]['pbx_name'] ?? '') === 'Secondary PBX', 'A GDMS RemoteConnect hostname with the managed UCM MAC must link to that PBX.');
    $expect(($sipDisplayAccounts[4]['pbx_name'] ?? '') === '', 'An unrelated external SIP hostname must not be falsely labeled as a managed PBX.');
    $ambiguousSipDisplayPbxs = $sipDisplayPbxs;
    $ambiguousSipDisplayPbxs[] = ['raw_model' => 'UCM6300A', 'name' => 'Secondary PBX-Replica', 'mac' => '02:00:00:00:01:03', 'private_ip' => '198.51.100.10', 'asset_url' => '/front/networkequipment.form.php?id=803'];
    $ambiguousSipDisplay = DashboardService::enrichSipAccountsForDisplay([
        ['extension' => '7001', 'server' => '198.51.100.10:5060'],
    ], $ambiguousSipDisplayPbxs);
    $expect(($ambiguousSipDisplay[0]['pbx_name'] ?? '') === '', 'Duplicate managed PBX private IPs must not produce an arbitrary link.');
    $expect(PbxAssociationResolver::sipServerIp('sip:198.51.100.10:5060;transport=udp') === '198.51.100.10', 'SIP display resolver must normalize IP/port/transport before matching the PBX.');
    $expect(PbxAssociationResolver::sipServerIp('sip:pbx.example.com:5060') === '', 'SIP display resolver must not treat hostnames as managed PBX IPs.');
    $expect(PbxAssociationResolver::sipServerPbxMac('sip:020000000102.a.gdms.cloud:5061') === '02:00:00:00:01:02', 'SIP display resolver must extract the managed UCM MAC from a RemoteConnect hostname.');

    // Representative multi-site topology from the a representative GDMS sync fixture.
    $rows = [
        [
            'mac' => '02:00:00:00:01:01', 'raw_model' => 'UCM6300A', 'name' => 'Primary PBX',
            'private_ip' => '192.0.2.10', 'ip' => '203.0.113.100', 'network_name' => 'Example Organization',
        ],
        [
            'mac' => '02:00:00:00:01:02', 'raw_model' => 'UCM6300A', 'name' => 'Secondary PBX',
            'private_ip' => '198.51.100.10', 'ip' => '203.0.113.101', 'network_name' => 'Example Organization',
        ],
        [
            'mac' => '02:00:00:00:02:11', 'raw_model' => 'GRP2602', 'name' => 'Office Phone A',
            'private_ip' => '192.0.2.87', 'ip' => '203.0.113.100', 'network_name' => 'Example Organization',
        ],
        [
            'mac' => '02:00:00:00:02:22', 'raw_model' => 'GRP2602', 'name' => 'Office Phone B',
            'private_ip' => '198.51.100.210', 'ip' => '203.0.113.101', 'network_name' => 'Example Organization',
        ],
        [
            'mac' => '02:00:00:00:02:33', 'raw_model' => 'HT812', 'name' => 'Sample ATA',
            'private_ip' => '', 'ip' => '203.0.113.101', 'network_name' => 'Example Organization',
        ],
        [
            'mac' => '02:00:00:00:02:44', 'raw_model' => 'GRP2602', 'name' => 'Remote Office Phone',
            'private_ip' => '203.0.113.79', 'ip' => '203.0.113.102', 'network_name' => 'Example Organization',
        ],
    ];

    $resolved = PbxAssociationResolver::resolve($rows);
    $expect(($resolved['02:00:00:00:02:11']['name'] ?? '') === 'Primary PBX', '192.0.2.x phone must link to Primary PBX by private subnet.');
    $expect(($resolved['02:00:00:00:02:11']['match_method'] ?? '') === 'private-subnet', 'Primary PBX phone should report private-subnet method.');
    $expect(($resolved['02:00:00:00:02:22']['name'] ?? '') === 'Secondary PBX', '198.51.100.x phone must link to Secondary PBX by private subnet.');
    $expect(($resolved['02:00:00:00:02:22']['match_method'] ?? '') === 'private-subnet', 'Secondary PBX phone should report private-subnet method.');

    // Public-IP equality is retained as a candidate signal, not as proof of SIP registration.
    $expect(($resolved['02:00:00:00:02:33'] ?? null) === null, 'ATA without private IP must not be assigned by public IP equality alone.');
    $detailed = PbxAssociationResolver::resolveDetailed($rows);
    $expect(($detailed['diagnostics']['02:00:00:00:02:33']['reason'] ?? '') === 'public_ip_matches_managed_pbx_but_is_not_authoritative_and_private_ip_is_missing', 'ATA reason must explain why exact public-IP evidence is not authoritative.');
    $expect(count($detailed['diagnostics']['02:00:00:00:02:33']['public_ip_candidates'] ?? []) === 1, 'Diagnostic must retain the exact public-IP candidate without assigning it.');

    // Remote Site shares the GDMS site label with the both managed UCMs but has a different
    // private subnet and WAN address. It must remain unassigned until its UCM/SIP
    // relationship is present in the API data or explicitly represented.
    $expect(($resolved['02:00:00:00:02:44'] ?? null) === null, 'Remote Site device must not be assigned to either managed PBX.');
    $remoteSiteDiagnostic = $detailed['diagnostics']['02:00:00:00:02:44'] ?? [];
    $expect(($remoteSiteDiagnostic['reason'] ?? '') === 'gdms_network_name_is_shared_by_multiple_pbxs', 'Remote Site diagnostic must identify missing PBX on its private subnet.');
    $expect(count($remoteSiteDiagnostic['network_name_candidates'] ?? []) === 2, 'Remote Site diagnostic must show both managed PBXs sharing the GDMS site label.');
    $expect(count($detailed['pbx_inventory']) === 2, 'Inventory must show that only two PBXs were returned by GDMS.');

    // Direct server IP from device/account/info is authoritative across routed subnets.
    $sipIpLinked = $rows;
    $sipIpLinked[5]['sip_server'] = 'sip:192.0.2.10:5060;transport=udp';
    $sipIpResolved = PbxAssociationResolver::resolve($sipIpLinked);
    $expect(($sipIpResolved['02:00:00:00:02:44']['name'] ?? '') === 'Primary PBX', 'Remote Site device must link to Primary PBX from its explicit SIP server IP even though it is on another private subnet.');
    $expect(($sipIpResolved['02:00:00:00:02:44']['match_method'] ?? '') === 'sip-server-ip', 'Direct SIP server IP association must be observable.');

    // A SIP server IP absent from inventory is authoritative but unresolved; do not
    // silently substitute a local-subnet PBX that differs from the configured server.
    $unknownSipIp = $rows;
    $unknownSipIp[2]['sip_server'] = '10.99.99.10:5060';
    $unknownSipResolved = PbxAssociationResolver::resolve($unknownSipIp);
    $expect(($unknownSipResolved['02:00:00:00:02:11'] ?? null) === null, 'Unknown SIP server IP must not fall back to a same-subnet UCM guess.');

    // Explicit SIP hostname/UCM MAC overrides subnet matching.
    $sipLinked = $rows;
    $sipLinked[2]['sip_server'] = '020000000102.a.gdms.cloud:5061';
    $sipResolved = PbxAssociationResolver::resolve($sipLinked);
    $expect(($sipResolved['02:00:00:00:02:11']['name'] ?? '') === 'Secondary PBX', 'Explicit RemoteConnect SIP hostname must override subnet matching.');
    $expect(($sipResolved['02:00:00:00:02:11']['match_method'] ?? '') === 'explicit-pbx-mac', 'SIP hostname match must be observable.');

    $explicitField = $rows;
    $explicitField[2]['pbx_mac'] = '02:00:00:00:01:02';
    $explicitResolved = PbxAssociationResolver::resolve($explicitField);
    $expect(($explicitResolved['02:00:00:00:02:11']['name'] ?? '') === 'Secondary PBX', 'Explicit pbx_mac field must override subnet matching.');

    // An explicit SIP reference to an UCM missing from the current inventory
    // must not be silently replaced by a different local-subnet PBX.
    $missingExplicit = $rows;
    $missingExplicit[2]['sip_server'] = 'deadbeef1234.a.gdms.cloud:5061';
    $missingResolved = PbxAssociationResolver::resolve($missingExplicit);
    $expect(($missingResolved['02:00:00:00:02:11'] ?? null) === null, 'Unknown explicit PBX reference must suppress a conflicting subnet fallback.');
    $missingDetailed = PbxAssociationResolver::resolveDetailed($missingExplicit);
    $expect(($missingDetailed['diagnostics']['02:00:00:00:02:11']['reason'] ?? '') === 'explicit_sip_pbx_reference_not_in_synced_inventory', 'Unknown explicit PBX reference must have a dedicated diagnostic reason.');

    // Ambiguous public-IP sharing and site-name overlap must never choose a PBX.
    $ambiguousPublic = $rows;
    $ambiguousPublic[] = [
        'mac' => '02:00:00:00:01:03', 'raw_model' => 'UCM6300A', 'name' => 'Third PBX',
        'private_ip' => '198.51.100.88', 'ip' => '203.0.113.101', 'network_name' => 'Example Organization',
    ];
    $ambiguousResolved = PbxAssociationResolver::resolve($ambiguousPublic);
    $expect(($ambiguousResolved['02:00:00:00:02:22'] ?? null) === null, 'Private-IP subnet with multiple PBXs must remain unassigned when not unique.');
    $expect(($ambiguousResolved['02:00:00:00:02:33'] ?? null) === null, 'Shared public IP must remain diagnostic-only even with several PBXs.');

    // A sole PBX sharing a site name is still not proof of a SIP registration.
    $singlePbx = [
        ['mac' => 'aa:00:00:00:00:01', 'raw_model' => 'UCM6300A', 'name' => 'Only PBX', 'private_ip' => '10.0.0.1', 'ip' => '203.0.113.1', 'network_name' => 'Branch'],
        ['mac' => 'aa:00:00:00:00:02', 'raw_model' => 'GRP2602', 'name' => 'Phone', 'private_ip' => '10.20.0.5', 'ip' => '203.0.113.2', 'network_name' => 'Branch'],
    ];
    $singleResolved = PbxAssociationResolver::resolve($singlePbx);
    $expect(($singleResolved['aa:00:00:00:00:02'] ?? null) === null, 'Unique network-name fallback must not create an unverified PBX association.');

    // SIP RemoteConnect hostnames use the UCM MAC without separators.
    $extractPbxMac = new ReflectionMethod(Sync::class, 'extractPbxMacReference');
    $extractPbxMac->setAccessible(true);
    $expect($extractPbxMac->invoke(null, ['sipServer' => '020000000102.a.gdms.cloud:5061']) === '02:00:00:00:01:02', 'SIP RemoteConnect hostname must resolve embedded UCM MAC.');
    $expect($extractPbxMac->invoke(null, ['accounts' => [['sipServerAddress' => 'sip:020000000101.a.gdms.cloud:5061']]]) === '02:00:00:00:01:01', 'Nested SIP account server address must resolve embedded UCM MAC.');
    $expect($extractPbxMac->invoke(null, ['ucmMacAddress' => '02:00:00:00:01:02']) === '02:00:00:00:01:02', 'Explicit UCM MAC aliases must continue to work.');
    $expect($extractPbxMac->invoke(null, ['sipServer' => 'sip.provider.example:5061']) === '', 'Generic SIP server must not be guessed as a managed UCM.');

    $extractSipAssignment = new ReflectionMethod(Sync::class, 'extractSipAssignment');
    $extractSipAssignment->setAccessible(true);
    $sipAssignment = $extractSipAssignment->invoke(null, [
        'accounts' => [[
            'sipServerAddress' => 'sip:020000000102.a.gdms.cloud:5061',
            'extension' => '2204',
            'password' => 'DO-NOT-LOG-THIS',
        ]],
    ]);
    $expect(($sipAssignment['server'] ?? '') === '020000000102.a.gdms.cloud:5061', 'SIP parser must normalize a SIP URI and capture the RemoteConnect host.');
    $expect(($sipAssignment['extension'] ?? '') === '2204', 'SIP parser must capture a safe extension field.');
    $snakeCaseAssignment = $extractSipAssignment->invoke(null, ['sip_server_address' => 'sips:pbx.example.com:5061', 'sip_extension' => '2301']);
    $expect(($snakeCaseAssignment['server'] ?? '') === 'pbx.example.com:5061', 'SIP parser must support snake_case server aliases.');
    $expect(($snakeCaseAssignment['extension'] ?? '') === '2301', 'SIP parser must support snake_case extension aliases.');

    $summarizeSipPayload = new ReflectionMethod(Sync::class, 'summarizeSipPayload');
    $summarizeSipPayload->setAccessible(true);
    $summary = $summarizeSipPayload->invoke(null, [
        'accountStatus' => 1,
        'accounts' => [[
            'sipServerAddress' => 'sip:user:pass@020000000102.a.gdms.cloud:5061',
            'extension' => '2204',
            'password' => 'DO-NOT-LOG-THIS',
            'authUser' => 'private-user',
        ]],
    ]);
    $expect(in_array('accountStatus', $summary['paths'], true), 'SIP payload summary must include accountStatus field path.');
    $expect(($summary['safe_values']['accountStatus'] ?? '') === '1', 'SIP payload summary may log accountStatus.');
    $expect(($summary['safe_values']['accounts.0.sipServerAddress'] ?? '') === '020000000102.a.gdms.cloud:5061', 'SIP payload summary must log server host without URI credentials.');
    $expect(($summary['safe_values']['accounts.0.password'] ?? '') === '[REDACTED]', 'SIP payload summary must redact passwords.');
    $expect(($summary['safe_values']['accounts.0.authUser'] ?? '') === '[REDACTED]', 'SIP payload summary must redact authentication usernames.');
    $expect(!str_contains(json_encode($summary), 'DO-NOT-LOG-THIS'), 'SIP payload summary must never include raw password text.');
    $expect(!str_contains(json_encode($summary), 'private-user'), 'SIP payload summary must never include authentication username.');

    $signatureMethod = new ReflectionMethod(API::class, 'gdmsBuildSignature');
    $signatureMethod->setAccessible(true);
    $signatureConfig = ['access_token' => 'test-access', 'client_id' => '12345', 'client_secret' => 'test-secret'];
    $signatureBody = json_encode(['mac' => '00:11:22:33:44:55']);
    $signatureTimestamp = 123456789;
    $bodyHash = hash('sha256', $signatureBody);
    $expectedSignature = hash('sha256', '&access_token=test-access&client_id=12345&client_secret=test-secret&timestamp=123456789&' . $bodyHash . '&');
    $actualSignature = $signatureMethod->invoke(null, ['access_token' => 'test-access'], $signatureConfig, $signatureTimestamp, $signatureBody);
    $expect($actualSignature === $expectedSignature, 'SIP account POST signature must reuse GDMS sorted-parameter/body-hash signing.');

    $safePayload = API::gdmsSanitizeSipPayload([
        'data' => [
            'accountList' => [[
                'accountIndex' => 1,
                'userId' => '8003',
                'serverAddress' => 'sip:secret-user:secret-pass@192.0.2.10:5060;transport=udp',
                'registrationStatus' => 'Registered',
                'password' => 'DO-NOT-STORE',
                'authName' => 'DO-NOT-STORE-AUTH',
                'access_token' => 'DO-NOT-STORE-TOKEN',
            ]],
        ],
    ]);
    $expect(!str_contains(json_encode($safePayload), 'DO-NOT-STORE'), 'Persisted SIP response must remove passwords, authentication names, and tokens.');
    $snapshotSummary = API::gdmsSummarizeSipSnapshot([
        'info' => $safePayload,
        'status' => ['data' => ['accountList' => [['accountIndex' => 1, 'registrationStatus' => 'Registered']]]],
    ]);
    $expect(($snapshotSummary['primary_server'] ?? '') === '192.0.2.10:5060', 'SIP snapshot parser must extract server IP and port from sanitized account configuration.');
    $expect(($snapshotSummary['primary_extension'] ?? '') === '8003', 'SIP snapshot parser must extract User ID as the extension.');
    $expect(($snapshotSummary['account_count'] ?? 0) >= 1, 'SIP snapshot parser must retain at least one configured account.');
    $expect(($snapshotSummary['registered_count'] ?? 0) === 1, 'SIP snapshot parser must merge registration status by account index.');


    // Representative GDMS ATA response:
    // info uses fxsPortList, status uses sipAccountInfoList, and data.accountStatus
    // is a device-level value rather than a third SIP account.
    $ataSnapshot = [
        'info' => [
            'data' => ['fxsPortList' => [
                ['sipAccountId' => 'account-1', 'sipUserId' => '7001', 'sipServer' => '198.51.100.10'],
                ['sipAccountId' => 'account-2', 'sipUserId' => '7002', 'sipServer' => '198.51.100.10'],
            ]],
        ],
        'status' => [
            'data' => [
                'accountStatus' => 1,
                'sipAccountInfoList' => [
                    ['sipUserId' => '7001', 'sipServer' => '198.51.100.10', 'accountStatus' => 'Up'],
                    ['sipUserId' => '7002', 'sipServer' => '198.51.100.10', 'accountStatus' => 'Up'],
                ],
            ],
        ],
    ];
    $ataSummary = API::gdmsSummarizeSipSnapshot([
        'info' => API::gdmsSanitizeSipPayload($ataSnapshot['info']),
        'status' => API::gdmsSanitizeSipPayload($ataSnapshot['status']),
    ]);
    $ataExtensions = array_values(array_map(static fn(array $account): string => $account['extension'], $ataSummary['accounts']));
    sort($ataExtensions);
    $expect(($ataSummary['account_count'] ?? 0) === 2, 'ATA info/status must merge to exactly two SIP accounts, not five phantom/duplicate rows.');
    $expect(($ataSummary['registered_count'] ?? 0) === 2, 'Both ATA extensions must be counted as registered.');
    $expect($ataExtensions === ['7001', '7002'], 'ATA must retain both FXS extension numbers.');
    $expect(($ataSummary['accounts'][0]['status'] ?? '') === 'Up' && ($ataSummary['accounts'][1]['status'] ?? '') === 'Up', 'ATA info records must inherit per-account registration status from status API.');
    $expect(($ataSummary['server_count'] ?? 0) === 1 && ($ataSummary['primary_server'] ?? '') === '198.51.100.10', 'Two ATA extensions using one UCM must retain one PBX association.');

    $singlePhoneSummary = API::gdmsSummarizeSipSnapshot([
        'info' => ['data' => ['sipAccountInfoList' => [
            ['sipAccountId' => 'sip-7003', 'sipUserId' => '7003', 'sipServer' => '198.51.100.10'],
        ]]],
        'status' => ['data' => [
            'accountStatus' => 1,
            'sipAccountInfoList' => [
                ['sipUserId' => '7003', 'sipServer' => '198.51.100.10', 'accountStatus' => 'Up'],
            ],
        ]],
    ]);
    $expect(($singlePhoneSummary['account_count'] ?? 0) === 1, 'Device-level accountStatus must not become a phantom account on a one-line phone.');
    $expect(($singlePhoneSummary['registered_count'] ?? 0) === 1, 'One configured/registered extension must count exactly once.');

    $distinctIdsSummary = API::gdmsSummarizeSipSnapshot([
        'info' => ['data' => ['sipAccountInfoList' => [
            ['sipAccountId' => 'id-A', 'sipUserId' => 'same-ext', 'sipServer' => '10.0.0.1'],
            ['sipAccountId' => 'id-B', 'sipUserId' => 'same-ext', 'sipServer' => '10.0.0.1'],
        ]]],
        'status' => [],
    ]);
    $expect(($distinctIdsSummary['account_count'] ?? 0) === 2, 'Distinct GDMS sipAccountId values must not be collapsed even if extension/server fields match.');

    $multipleSipServers = API::gdmsSummarizeSipSnapshot([
        'info' => ['accounts' => [
            ['accountIndex' => 1, 'userId' => '8003', 'serverAddress' => '192.0.2.10:5060'],
            ['accountIndex' => 2, 'userId' => '8004', 'serverAddress' => '198.51.100.10:5060'],
        ]],
        'status' => [],
    ]);
    $expect(($multipleSipServers['server_count'] ?? 0) === 2, 'Multiple distinct account servers must be counted.');
    $expect(($multipleSipServers['primary_server'] ?? '') === '', 'A device with accounts on different PBXs must not receive an arbitrary primary PBX.');

    $sameSipServerDifferentPorts = API::gdmsSummarizeSipSnapshot([
        'info' => ['accounts' => [
            ['accountIndex' => 1, 'serverAddress' => '192.0.2.10:5060'],
            ['accountIndex' => 2, 'serverAddress' => '192.0.2.10:5061'],
        ]],
        'status' => [],
    ]);
    $expect(($sameSipServerDifferentPorts['server_count'] ?? 0) === 1, 'Different SIP ports on one PBX must still represent one server host.');

    $preferredAddress = API::gdmsSummarizeSipSnapshot([
        'info' => ['accounts' => [[
            'accountIndex' => 1,
            'serverName' => 'Primary PBX display label',
            'serverAddress' => '192.0.2.10:5060',
            'userId' => '8003',
        ]]],
        'status' => [],
    ]);
    $expect(($preferredAddress['primary_server'] ?? '') === '192.0.2.10:5060', 'Concrete server address must take precedence over a display server name.');

    $multipleServerDevice = $rows;
    $multipleServerDevice[5]['sip_server_count'] = 2;
    $multipleServerResolved = PbxAssociationResolver::resolveDetailed($multipleServerDevice);
    $expect(($multipleServerResolved['associations']['02:00:00:00:02:44'] ?? null) === null, 'Multiple SIP server hosts must suppress a misleading single-PBX subnet fallback.');
    $expect(($multipleServerResolved['diagnostics']['02:00:00:00:02:44']['reason'] ?? '') === 'multiple_sip_account_servers_on_device', 'Multiple SIP server hosts must have an explicit diagnostic reason.');

    $safeSipServer = new ReflectionMethod(PbxAssociationResolver::class, 'safeSipServer');
    $safeSipServer->setAccessible(true);
    $expect($safeSipServer->invoke(null, 'sip:user:secret@pbx.example.com:5061;transport=tls') === 'pbx.example.com:5061', 'Safe SIP server logger must keep host/port only and strip user-info/parameters.');

    $extractPrivateIp = new ReflectionMethod(Sync::class, 'extractPrivateIp');
    $extractPrivateIp->setAccessible(true);
    $expect($extractPrivateIp->invoke(null, ['privateIp' => '', 'privateip' => '198.51.100.50']) === '198.51.100.50', 'Empty privateIp alias must not hide populated privateip alias.');
    $expect($extractPrivateIp->invoke(null, ['privateip' => '', 'private_ip' => '198.51.100.51']) === '198.51.100.51', 'Alternate private_ip key must be accepted.');
    $expect($extractPrivateIp->invoke(null, ['privateip' => 'not-an-ip', 'localIp' => '198.51.100.52']) === '198.51.100.52', 'Invalid alias must not hide a later valid local IP.');

    $effectivePrivateIp = new ReflectionMethod(Device::class, 'effectivePrivateIp');
    $effectivePrivateIp->setAccessible(true);
    $expect($effectivePrivateIp->invoke(null, '', ['private_ip' => '198.51.100.53']) === '198.51.100.53', 'Blank sync must preserve last known local IP.');
    $expect($effectivePrivateIp->invoke(null, 'invalid', ['private_ip' => '198.51.100.53']) === '198.51.100.53', 'Invalid sync value must preserve last known local IP.');
    $expect($effectivePrivateIp->invoke(null, '198.51.100.54', ['private_ip' => '198.51.100.53']) === '198.51.100.54', 'New valid local IP must replace old value.');

    printf("PASS: %d PBX / SIP diagnostics / privacy / local-IP regression assertions\n", $checks);
}
