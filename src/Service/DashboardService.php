<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Glpi\Application\View\TemplateRenderer;
use Glpi\DBAL\QueryExpression;
use Glpi\DBAL\QueryFunction;
use Glpi\Exception\Http\AccessDeniedHttpException;
use GlpiPlugin\Gdmsintegration\API;
use GlpiPlugin\Gdmsintegration\Config;
use GlpiPlugin\Gdmsintegration\Device;
use GlpiPlugin\Gdmsintegration\History;
use GlpiPlugin\Gdmsintegration\Link;
use GlpiPlugin\Gdmsintegration\Sync;
use GlpiPlugin\Gdmsintegration\Utils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DashboardService
{
    /**
     * Run a dashboard synchronization when no prior run exists or the configured
     * refresh interval has elapsed. This avoids a redundant full sync on every
     * page visit while ensuring a stale dashboard refreshes as soon as it opens.
     */
    public static function shouldRunDashboardSync(
        ?string $lastSyncAt,
        int $refreshInterval,
        bool $canSync,
        bool $providerReady,
        ?int $now = null,
    ): bool {
        if (!$canSync || !$providerReady) {
            return false;
        }
        if ($lastSyncAt === null || trim($lastSyncAt) === '') {
            return true;
        }
        $lastSyncTimestamp = strtotime(trim($lastSyncAt) . ' UTC');
        if ($lastSyncTimestamp === false) {
            return true;
        }
        return max(0, ($now ?? time()) - $lastSyncTimestamp) >= max(30, $refreshInterval);
    }

    /**
     * Attach a managed PBX display target to each SIP account whose server
     * address matches one managed UCM by private IP or RemoteConnect MAC.
     * This is intentionally per account: a device can have extensions
     * registered to different PBXs.
     * Ambiguous duplicate PBX IPs remain unresolved instead of picking one.
     *
     * @param array<int, array<string, mixed>> $accounts
     * @param array<int, array<string, mixed>> $pbxRows
     * @return array<int, array<string, mixed>>
     */
    /**
     * Build reusable indexes once per render; RemoteConnect PBX lookup must not
     * depend on the PBX having a valid management IP.
     *
     * @param array<int, array<string, mixed>> $pbxRows
     * @return array{by_ip: array<string, array<int, array{name:string,ip:string,url:string,ip_url:string}>>, by_mac: array<string, array<int, array{name:string,ip:string,url:string,ip_url:string}>>}
     */
    private static function buildSipPbxDisplayIndexes(array $pbxRows): array
    {
        $pbxByIp = [];
        $pbxByMac = [];
        foreach ($pbxRows as $pbxRow) {
            if (API::getDeviceCategory((string) ($pbxRow['raw_model'] ?? '')) !== 'pbx') {
                continue;
            }
            $rawIp = trim((string) ($pbxRow['private_ip'] ?? ''));
            $packedIp = filter_var($rawIp, FILTER_VALIDATE_IP) !== false ? inet_pton($rawIp) : false;
            $ip = $packedIp === false ? '' : (inet_ntop($packedIp) ?: '');
            $host = $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? '[' . $ip . ']'
                : $ip;
            $pbxDisplay = [
                'name' => (string) ($pbxRow['name'] ?? ''),
                'ip' => $ip,
                'url' => (string) ($pbxRow['asset_url'] ?? ''),
                'ip_url' => $ip !== '' ? 'http://' . $host : '',
            ];
            $pbxMac = PbxAssociationResolver::normalizeMac((string) ($pbxRow['mac'] ?? ''));
            if ($pbxMac !== '') {
                $pbxByMac[$pbxMac][] = $pbxDisplay;
            }
            // Only inventory-eligible PBXs with a valid MAC can be associated
            // by IP, matching PbxAssociationResolver's candidate rules.
            if ($pbxMac !== '' && $ip !== '') {
                $pbxByIp[strtolower($ip)][] = $pbxDisplay;
            }
        }
        return ['by_ip' => $pbxByIp, 'by_mac' => $pbxByMac];
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @param array{by_ip: array<string, array<int, array{name:string,ip:string,url:string,ip_url:string}>>, by_mac: array<string, array<int, array{name:string,ip:string,url:string,ip_url:string}>>} $indexes
     * @return array<int, array<string, mixed>>
     */
    private static function enrichSipAccountsWithIndexes(array $accounts, array $indexes): array
    {
        foreach ($accounts as &$account) {
            $server = (string) ($account['server'] ?? '');
            $serverIp = PbxAssociationResolver::sipServerIp($server);
            $serverPbxMac = $serverIp === '' ? PbxAssociationResolver::sipServerPbxMac($server) : '';
            $candidates = $serverIp !== ''
                ? ($indexes['by_ip'][strtolower($serverIp)] ?? [])
                : ($serverPbxMac !== '' ? ($indexes['by_mac'][$serverPbxMac] ?? []) : []);
            if (count($candidates) !== 1) {
                $account['pbx_name'] = '';
                $account['pbx_ip'] = '';
                $account['pbx_url'] = '';
                $account['pbx_ip_url'] = '';
                continue;
            }
            $pbx = $candidates[0];
            $account['pbx_name'] = $pbx['name'];
            $account['pbx_ip'] = $pbx['ip'];
            $account['pbx_url'] = $pbx['url'];
            $account['pbx_ip_url'] = $pbx['ip_url'];
        }
        unset($account);
        return $accounts;
    }

    /**
     * Public compatibility wrapper for integrations and regression tests.
     *
     * @param array<int, array<string, mixed>> $accounts
     * @param array<int, array<string, mixed>> $pbxRows
     * @return array<int, array<string, mixed>>
     */
    public static function enrichSipAccountsForDisplay(array $accounts, array $pbxRows): array
    {
        return self::enrichSipAccountsWithIndexes($accounts, self::buildSipPbxDisplayIndexes($pbxRows));
    }

    public static function render(Request $request): Response
    {
        ob_start();
        global $CFG_GLPI, $DB;

        \Session::checkLoginUser();
        if (!\Session::haveRight(Config::$rightname, READ) && !\Session::haveRight('networking', READ)) {
            throw new AccessDeniedHttpException();
        }

        \Html::requireJs('charts');    // ECharts 5 - lib/echarts.js
        \Html::requireJs('flatpickr'); // Flatpickr - lib/flatpickr.js

        \Html::header(
            'GDMS — ' . __('Dashboard', 'gdmsintegration'),
            '',
            'tools',
            '\GlpiPlugin\Gdmsintegration\Menu',
        );

        $entities_id = (int) (\Session::getActiveEntity() ?? 0);
        if (!\Session::haveAccessToEntity($entities_id)) {
            throw new AccessDeniedHttpException();
        }
        // AJAX URL built inline in JS

        // Guard: no config
        $config_obj       = new Config();
        $config           = $config_obj->getConfigByEntity($entities_id);
        $is_configured    = !empty($config['client_id']) || !empty($config['gwn_client_id']);
        $refresh_interval = max(30, (int) ($config['refresh_interval'] ?? 300));
        $last_sync_at     = $config['last_sync_at'] ?? null;
        $can_sync         = (bool) \Session::haveRight('config', UPDATE);
        $has_complete_gwn = !empty($config['gwn_client_id']) && !empty($config['gwn_client_secret']);
        $has_complete_gdms = !empty($config['client_id']) && !empty($config['client_secret'])
            && !empty($config['username']) && !empty($config['password']);
        $needs_auto_sync = self::shouldRunDashboardSync(
            is_string($last_sync_at) ? $last_sync_at : null,
            $refresh_interval,
            $can_sync,
            $has_complete_gwn || $has_complete_gdms,
        );
        $last_sync_label  = '';
        if ($last_sync_at) {
            // last_sync_at is stored as UTC (gmdate). Append ' UTC' so strtotime() parses it correctly
            // regardless of the server's local timezone setting.
            $diff = max(0, time() - strtotime($last_sync_at . ' UTC'));
            if ($diff < 60) {
                $last_sync_label = __('Last sync: just now', 'gdmsintegration');
            } elseif ($diff < 3600) {
                $last_sync_label = sprintf(__('Last sync: %d min ago', 'gdmsintegration'), intdiv($diff, 60));
            } elseif ($diff < 86400) {
                $last_sync_label = sprintf(__('Last sync: %dh ago', 'gdmsintegration'), intdiv($diff, 3600));
            } else {
                $last_sync_label = sprintf(__('Last sync: %dd ago', 'gdmsintegration'), intdiv($diff, 86400));
            }
        }
        $chart_days       = max(7, min(365, (int) ($config['chart_days'] ?? 60)));
        $show_topology    = (int) ($config['show_topology'] ?? 1);
        $ip_version       = in_array($config['ip_version'] ?? '', ['ipv4', 'ipv6'], true) ? $config['ip_version'] : 'ipv4';

        // Flatpickr locale --- extract language and region from GLPI session
        $_fp_lang   = 'en';
        $_fp_region = '';
        if (!empty(\Session::getLanguage())) {
            $_fp_parts  = explode('_', \Session::getLanguage() ?? '');
            $_fp_lang   = strtolower($_fp_parts[0] ?? 'en');
            $_fp_region = strtoupper($_fp_parts[1] ?? '');
        }

        $_plugin_web = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration';

        if (!$is_configured) {
            echo TemplateRenderer::getInstance()->render('@gdmsintegration/dashboard.html.twig', [
                'is_configured' => false,
                'config_url'    => $_plugin_web . '/config',
            ]);
            \Html::footer();
            return new Response(ob_get_clean());
        }

        // Load GDMS-managed devices from the plugin's own device-state table.
        // This is the authoritative list of what GDMS manages --- NOT all GLPI assets.
        $online  = 0;
        $offline = 0;
        $rows    = [];
        $root    = rtrim($CFG_GLPI['root_doc'] ?? '', '/');

        $state_obj  = new Device();

        $all_states = $state_obj->find(AccessService::entityCriteria($state_obj->getTable(), $entities_id));

        // Batch-load uptime for all MACs in one query instead of one query per device
        $_all_macs    = array_filter(array_column($all_states, 'mac'));
        $_uptime_map  = Sync::calculateUptimeBatch($_all_macs, $entities_id);

        // Build MAC --- GLPI asset map across ALL entities (entity 0 vs active entity mismatch)
        $mac_to_asset = [];
        foreach (['NetworkEquipment', 'Phone'] as $itemtype) {
            $obj     = new $itemtype();
            $rows_db = $obj->find(\getEntitiesRestrictCriteria($obj->getTable(), 'entities_id', $entities_id, true));
            foreach ($rows_db as $d) {
                if (!empty($d['uuid'])) {
                    $mac_to_asset[strtolower(trim($d['uuid']))] = array_merge($d, ['_itemtype' => $itemtype]);
                }
            }
        }

        // Resolve model names with at most one query per model table instead
        // of issuing a getFromDB() for each dashboard row.
        $model_ids = ['NetworkEquipmentModel' => [], 'PhoneModel' => []];
        foreach ($mac_to_asset as $asset) {
            $model_id = (int) ($asset['networkequipmentmodels_id'] ?? $asset['phonemodels_id'] ?? 0);
            if ($model_id > 0) {
                $model_class = $asset['_itemtype'] === 'Phone' ? 'PhoneModel' : 'NetworkEquipmentModel';
                $model_ids[$model_class][$model_id] = $model_id;
            }
        }
        $model_names = ['NetworkEquipmentModel' => [], 'PhoneModel' => []];
        foreach ($model_ids as $model_class => $ids) {
            if ($ids === []) {
                continue;
            }
            $model_obj = new $model_class();
            foreach ($model_obj->find(['id' => array_values($ids)]) as $model_row) {
                $model_names[$model_class][(int) $model_row['id']] = (string) ($model_row['name'] ?? '');
            }
        }

        foreach ($all_states as $state) {
            $mac      = strtolower(trim($state['mac'] ?? ''));
            if (empty($mac)) {
                continue;
            }

            $isOnline    = ($state['status'] ?? '') === 'online';
            $uptime      = $_uptime_map[$mac] ?? 0.0;
            $sla         = Sync::slaLabel($uptime);
            $net_name    = (string) ($state['network_name'] ?? '');
            $ip          = (string) ($state['ip'] ?? '');
            $firmware         = (string) ($state['firmware'] ?? '');
            $sip_status       = $state['sip_status']      ?? '';
            $sip_extension    = $state['sip_extension']  ?? '';
            $dnd              = (int) ($state['dnd']            ?? 0);
            $is_synchronized  = (int) ($state['is_synchronized'] ?? 0);
            $sync_failure_msg = $state['sync_failure_msg'] ?? '';
            $scheduled_task   = (int) ($state['scheduled_task']  ?? 0);
            $ipv6             = (string) ($state['ipv6'] ?? '');
            $private_ip       = (string) ($state['private_ip'] ?? '');
            $location         = (string) ($state['location'] ?? '');
            $uptime_sec       = (int) ($state['uptime_sec']                   ?? 0);
            $sn_cloud         = (string) ($state['sn_cloud'] ?? '');
            $sipSnapshot = json_decode((string) ($state['sip_accounts_json'] ?? ''), true);
            $sipSummary = is_array($sipSnapshot) ? API::gdmsSummarizeSipSnapshot($sipSnapshot) : [];
            $sip_accounts = is_array($sipSummary['accounts'] ?? null) ? $sipSummary['accounts'] : [];
            if ($sip_accounts !== []) {
                $hasUnknownStatus = false;
                $hasNegativeStatus = false;
                foreach ($sip_accounts as $sipAccount) {
                    $accountStatus = strtolower(trim((string) ($sipAccount['status'] ?? '')));
                    if ($accountStatus === '') {
                        $hasUnknownStatus = true;
                    } elseif (preg_match('/^(?:1|true|up|normal|registered|online|ok)$/', $accountStatus) !== 1) {
                        $hasNegativeStatus = true;
                    }
                }
                $sip_status = $hasNegativeStatus
                    ? 'unregistered'
                    : ($hasUnknownStatus ? 'unknown' : 'registered');
            }
            if (($sipSummary['primary_extension'] ?? '') !== '') {
                $sip_extension = (string) $sipSummary['primary_extension'];
            }

            // Find the GLPI asset if it exists
            $glpi     = $mac_to_asset[$mac] ?? null;
            // Name priority: 1) GLPI asset name  2) cloud name stored during sync  3) MAC
            $cloud_name_raw = $state['cloud_name'] ?? '';
            if ($glpi) {
                $name = (string) $glpi['name'];
            } elseif ($cloud_name_raw !== '') {
                $name = (string) $cloud_name_raw;
            } else {
                $name = (string) $mac;
            }
            $serial   = $glpi ? (string) ($glpi['serial'] ?? '') : '';
            $itemtype = $glpi ? $glpi['_itemtype'] : 'NetworkEquipment';
            $glpi_id  = $glpi ? (int) $glpi['id'] : 0;

            if ($glpi_id > 0) {
                $asset_url = $root . ($itemtype === 'Phone' ? '/front/phone.form.php?id=' : '/front/networkequipment.form.php?id=') . $glpi_id;
            } else {
                $asset_url = '';
            }

            $isOnline ? $online++ : $offline++;
            // Resolve model: GLPI catalog first, then apType stored by sync (e.g. "GWN7001")
            $model_name = '';
            if ($glpi) {
                $model_id = (int) ($glpi['networkequipmentmodels_id'] ?? $glpi['phonemodels_id'] ?? 0);
                if ($model_id > 0) {
                    $model_class = $itemtype === 'Phone' ? 'PhoneModel' : 'NetworkEquipmentModel';
                    $model_name = $model_names[$model_class][$model_id] ?? '';
                }
            }
            // Fallback: apType from GWN/GDMS cloud stored during sync (e.g. "GWN7001", "GRP2601")
            if (!$model_name) {
                $model_name = $state['model'] ?? '';
            }

            $rows[] = [
                'name'         => $name,
                'asset_url'    => $asset_url,
                'type'         => $itemtype,
                'model'        => (string) $model_name,
                'mac'          => (string) $mac,
                'serial'       => $sn_cloud ?: $serial,
                'network_name' => $net_name,
                'network_id'   => (int) ($state['network_id'] ?? 0),
                'ip'           => $ip,
                'firmware'     => $firmware,
                'uptime_sec'   => $uptime_sec,
                'online'       => $isOnline,
                'uptime'       => $uptime,
                'sla'          => (string) $sla,
                'raw_model'      => $state['model'] ?? '',
                'clients'        => (int) ($state['clients']        ?? 0),
                'upload_bytes'   => (int) ($state['upload_bytes']   ?? 0),
                'download_bytes' => (int) ($state['download_bytes'] ?? 0),
                // WAN aggregate: sum txBytes/rxBytes from all WAN ports (more accurate than ap/list usage field)
                'wan_tx_bytes'   => (function () use ($state): int {
                    $ports = json_decode($state['wan_ports_json'] ?? '', true) ?? [];
                    $tx = 0;
                    foreach ($ports as $p) {
                        // wan_ports_json contains normalized WAN and LAN records.
                        // connectStatus/wanType/gateway are present on LAN records too
                        // (with sentinel values), so they must never be used to classify
                        // a stored port as WAN.
                        if ((int) ($p['role'] ?? 0) === 1) {
                            $tx += (int) ($p['txBytes'] ?? 0);
                        }
                    } return $tx;
                })(),
                'wan_rx_bytes'   => (function () use ($state): int {
                    $ports = json_decode($state['wan_ports_json'] ?? '', true) ?? [];
                    $rx = 0;
                    foreach ($ports as $p) {
                        if ((int) ($p['role'] ?? 0) === 1) {
                            $rx += (int) ($p['rxBytes'] ?? 0);
                        }
                    } return $rx;
                })(),
                // Parsed WAN ports are also exposed to the operational summary. Previously
                // only byte aggregates were built, so the WAN card always saw an empty array.
                'wan_ports'      => json_decode($state['wan_ports_json'] ?? '', true) ?: [],
                'channel_2g'      => (int) ($state['channel_2g']     ?? 0),
                'channel_5g'      => (int) ($state['channel_5g']     ?? 0),
                'last_seen'       => $state['last_seen']  ?? '',
                'first_seen'      => $state['first_seen'] ?? '',
                'mgmt_ip'         => (string) ($state['mgmt_ip'] ?? ''),
                'sip_status'       => $sip_status,
                'sip_extension'    => $sip_extension,
                'sip_accounts'     => $sip_accounts,
                'sip_server'       => (string) ($state['sip_server'] ?? ''),
                'sip_server_count' => (int) ($sipSummary['server_count'] ?? 0),
                'dnd'              => $dnd,
                'is_synchronized'  => $is_synchronized,
                'sync_failure_msg' => $sync_failure_msg,
                'scheduled_task'   => $scheduled_task,
                'ipv6'             => $ipv6,
                'private_ip'       => $private_ip,
                'pbx_mac'          => (string) ($state['pbx_mac'] ?? ''),
                'location'         => $location,
                'sla_rank'         => ($uptime === 0.0 && $isOnline ? 4 : ($uptime >= 99.9 ? 0 : ($uptime >= 99.0 ? 1 : ($uptime >= 95.0 ? 2 : 3)))),
            ];
        }

        // Keep infrastructure, PBX/UCM systems and IP phones in distinct sections.
        // This order is also retained when a user sorts one of the table columns.
        $tableGroupOrder = ['network' => 0, 'pbx' => 1, 'phone' => 2, 'other' => 3];
        $tableGroup = static function (array $row): string {
            $category = API::getDeviceCategory((string) ($row['raw_model'] ?? ''));
            if (($row['type'] ?? '') === 'Phone' || $category === 'phone') {
                return 'phone';
            }
            return $category === 'pbx' ? 'pbx' : 'network';
        };
        usort($rows, static function (array $a, array $b) use ($tableGroup, $tableGroupOrder): int {
            $groupCompare = $tableGroupOrder[$tableGroup($a)] <=> $tableGroupOrder[$tableGroup($b)];
            return $groupCompare !== 0 ? $groupCompare : strnatcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
        });

        // One resolver feeds the SIP detail, device list and network topology.
        // Public/WAN IP and GDMS site-name coincidences are diagnostics only;
        // only an explicit SIP-to-UCM reference or a unique private subnet can
        // associate a phone automatically.
        $pbxResolution = PbxAssociationResolver::resolveDetailed($rows);
        $pbx_for_device_mac = $pbxResolution['associations'];
        Utils::debug(sprintf(
            'Dashboard PBX inventory: count=%d devices=%s',
            count($pbxResolution['pbx_inventory']),
            json_encode($pbxResolution['pbx_inventory'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        ));

        $pbxResolutionSummary = [
            'phones' => 0,
            'associated' => 0,
            'unassigned' => 0,
            'methods' => [],
            'reasons' => [],
        ];
        foreach ($rows as $_associationRow) {
            if (API::getDeviceCategory((string) $_associationRow['raw_model']) !== 'phone') {
                continue;
            }
            $_associationMac = PbxAssociationResolver::normalizeMac((string) ($_associationRow['mac'] ?? ''));
            if ($_associationMac === '') {
                continue;
            }
            $_association = $pbx_for_device_mac[$_associationMac] ?? null;
            $_diagnostic = $pbxResolution['diagnostics'][$_associationMac] ?? [];
            $_method = is_array($_association) ? (string) ($_association['match_method'] ?? 'unknown') : 'none';
            $_reason = (string) ($_diagnostic['reason'] ?? 'no_diagnostic');
            $pbxResolutionSummary['phones']++;
            if (is_array($_association)) {
                $pbxResolutionSummary['associated']++;
            } else {
                $pbxResolutionSummary['unassigned']++;
            }
            $pbxResolutionSummary['methods'][$_method] = ($pbxResolutionSummary['methods'][$_method] ?? 0) + 1;
            $pbxResolutionSummary['reasons'][$_reason] = ($pbxResolutionSummary['reasons'][$_reason] ?? 0) + 1;

            Utils::debug(sprintf(
                'Dashboard PBX resolution: device=%s mac=%s private_ip=%s subnet=%s public_ip=%s sip_server=%s extension_present=%s result=%s pbx=%s pbx_mac=%s method=%s reason=%s candidates=%s',
                (string) ($_associationRow['name'] ?? ''),
                $_associationMac,
                (string) ($_diagnostic['private_ip'] ?? ''),
                (string) ($_diagnostic['private_subnet'] ?? ''),
                (string) ($_diagnostic['public_ip'] ?? ''),
                (string) ($_diagnostic['sip_server'] ?? ''),
                !empty($_diagnostic['sip_extension_present']) ? 'yes' : 'no',
                is_array($_association) ? 'ASSIGNED' : 'UNASSIGNED',
                is_array($_association) ? (string) ($_association['name'] ?? '') : '',
                is_array($_association) ? (string) ($_association['mac'] ?? '') : '',
                $_method,
                $_reason,
                json_encode([
                    'explicit' => $_diagnostic['explicit_candidates'] ?? [],
                    'sip_server_ip' => $_diagnostic['sip_server_ip_candidates'] ?? [],
                    'private_subnet' => $_diagnostic['private_subnet_candidates'] ?? [],
                    'public_ip_non_authoritative' => $_diagnostic['public_ip_candidates'] ?? [],
                    'network_name_non_authoritative' => $_diagnostic['network_name_candidates'] ?? [],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
            ));
        }
        Utils::debug(sprintf(
            'Dashboard PBX resolution summary: %s',
            json_encode($pbxResolutionSummary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ));

        // Build per-network device stats for tooltip (router/switch/AP/clients counts)
        // Device classification: GWN7001/7002/7003 prefix -- router; GWN7800/GSS -- switch; GWN76xx -- AP; UCM/GCC/GRP/GXP etc -- phone/pbx
        $net_stats = []; // network_name -- [router_on, router_off, switch_on, switch_off, ap_on, ap_off, clients_wired, clients_wireless]
        foreach ($rows as $r) {
            $nname = (string) ($r['network_name'] ?? '');
            $networkId = (int) $r['network_id'];
            $networkStatsKey = $networkId > 0
                ? 'id:' . $networkId
                : 'name:' . strtolower(trim(html_entity_decode($nname, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($nname === '' && $networkId <= 0) {
                continue;
            }
            if (!isset($net_stats[$networkStatsKey])) {
                $net_stats[$networkStatsKey] = ['name' => $nname, 'network_id' => $networkId, 'router_on' => 0,'router_off' => 0,'switch_on' => 0,'switch_off' => 0,'ap_on' => 0,'ap_off' => 0,'phone_on' => 0,'phone_off' => 0,'clients' => 0,'upload_bytes' => 0,'download_bytes' => 0];
            }
            $m   = strtoupper($r['raw_model']);
            $on  = $r['online'];
            $deviceCategory = API::getDeviceCategory((string) ($r['raw_model'] ?? ''));
            $isPhone = ($r['type'] === 'Phone') || $deviceCategory === 'phone';
            $isPbxDev = $deviceCategory === 'pbx';
            if (!$isPhone && !$isPbxDev) {
                if (preg_match('/^GWN700[0-9]/', $m)) {
                    $on ? $net_stats[$networkStatsKey]['router_on']++ : $net_stats[$networkStatsKey]['router_off']++;
                } elseif (preg_match('/^GWN78|^GSS/', $m)) {
                    $on ? $net_stats[$networkStatsKey]['switch_on']++ : $net_stats[$networkStatsKey]['switch_off']++;
                } elseif (preg_match('/^GWN/', $m)) {
                    $on ? $net_stats[$networkStatsKey]['ap_on']++ : $net_stats[$networkStatsKey]['ap_off']++;
                }
            }
            if ($isPhone || $isPbxDev) {
                $on ? $net_stats[$networkStatsKey]['phone_on']++ : $net_stats[$networkStatsKey]['phone_off']++;
            }
            $net_stats[$networkStatsKey]['clients']        += $r['clients'];
            $net_stats[$networkStatsKey]['upload_bytes']   += $r['upload_bytes'];
            $net_stats[$networkStatsKey]['download_bytes'] += $r['download_bytes'];
        }

        // Uptime history -- last N days per device per day (configurable via chart_days)
        $history_obj = new History();
        $history_ago  = gmdate('Y-m-d H:i:s', strtotime("-{$chart_days} days"));
        // Aggregate uptime points in SQL instead of loading every raw history row into PHP.
        // This keeps the same chart semantics while reducing memory and transfer volume
        // from O(number of history rows) to O(devices x days).
        $hist_rows = $DB->request([
            'SELECT' => [
                new QueryExpression('DATE(`date`)', 'day'),
                new QueryExpression('LOWER(`mac`)', 'mac_normalized'),
                QueryFunction::count('id', false, 'total'),
                new QueryExpression("SUM(CASE WHEN `status` = 'online' THEN 1 ELSE 0 END)", 'online'),
            ],
            'FROM'   => $history_obj->getTable(),
            'WHERE'  => ['date' => ['>', $history_ago]] + AccessService::entityCriteria($history_obj->getTable(), $entities_id),
            'GROUPBY' => ['day', 'mac_normalized'],
            'ORDER'  => ['day'],
        ]);

        // Build: mac -- (name) and date -- per-mac status
        $mac_to_name = [];
        foreach ($all_states as $st) {
            $m = strtolower(trim($st['mac'] ?? ''));
            if ($m) {
                $glpi = $mac_to_asset[$m] ?? null;
                $mac_to_name[$m] = $glpi ? ($glpi['name'] ?? $m) : $m;
            }
        }

        // per_device[mac][day] = [online, total]
        $per_device = [];
        $all_days   = [];
        foreach ($hist_rows as $h) {
            $day = (string) ($h['day'] ?? '');
            $mac = strtolower(trim((string) ($h['mac_normalized'] ?? '')));
            if ($day === '' || $mac === '') {
                continue;
            }
            $all_days[$day] = true;
            $per_device[$mac][$day] = [
                'online' => (int) ($h['online'] ?? 0),
                'total'  => (int) ($h['total'] ?? 0),
            ];
        }
        ksort($all_days);
        $chart_labels = array_keys($all_days);

        // Build dataset per device -- only include MACs that are currently managed
        // (present in the devices table). Removed devices are excluded even if
        // their history rows survived the last cleanup cycle.
        $chart_datasets = [];
        $palette = ['#28a745','#007bff','#fd7e14','#dc3545','#6f42c1','#20c997','#ffc107','#e83e8c','#17a2b8','#6c757d'];
        $pi = 0;
        foreach ($per_device as $mac => $days) {
            if (!isset($mac_to_name[$mac])) {
                continue;
            } // device removed from cloud — skip
            $label  = $mac_to_name[$mac];
            $color  = $palette[$pi % count($palette)];
            $pi++;
            $vals = [];
            foreach ($chart_labels as $day) {
                $d    = $days[$day] ?? ['online' => 0, 'total' => 0];
                $vals[] = $d['total'] > 0 ? round($d['online'] / $d['total'] * 100) : null;
            }
            $chart_datasets[] = [
                'label'           => $label,
                'data'            => $vals,
                'borderColor'     => $color,
                'backgroundColor' => $color . '22',
                'borderWidth'     => 2,
                'fill'            => false,
                'tension'         => 0.3,
                'pointRadius'     => 2,
                'spanGaps'        => true,
            ];
        }

        // Topology -- only build if show_topology is enabled (saves DB query when disabled)
        $nodes = [];
        $edges = [];
        if ($show_topology) {
            $entity_macs = array_keys($mac_to_asset);
            $link        = new Link();
            $links_raw   = empty($entity_macs) ? [] : $link->find(['source_mac' => $entity_macs, 'target_mac' => $entity_macs]);

            foreach ($rows as $r) {
                $category = API::getDeviceCategory((string) ($r['raw_model'] ?? ''));
                if ($r['type'] === 'Phone') {
                    $category = 'phone';
                }
                $shape = match ($category) {
                    'pbx' => 'database',
                    'phone' => 'box',
                    'switch' => 'hexagon',
                    'router' => 'diamond',
                    default => 'dot',
                };
                $nodeMac = PbxAssociationResolver::normalizeMac((string) ($r['mac'] ?? ''));
                $nodeId = $nodeMac !== '' ? $nodeMac : (string) $r['mac'];
                $nodes[] = [
                    'id'    => $nodeId,
                    'label' => $r['name'],
                    'shape' => $shape,
                    'color' => ['background' => $r['online'] ? '#28a745' : '#dc3545', 'border' => '#aaa'],
                    'font'  => ['color' => '#ffffff'],
                    'title' => $r['name'] . ' — ' . strtoupper($category) . ' — ' . ($r['online'] ? __('Online', 'gdmsintegration') : __('Offline', 'gdmsintegration')),
                ];
            }
            foreach ($links_raw as $l) {
                if (!empty($l['source_mac']) && !empty($l['target_mac'])) {
                    $sourceMac = PbxAssociationResolver::normalizeMac((string) $l['source_mac']);
                    $targetMac = PbxAssociationResolver::normalizeMac((string) $l['target_mac']);
                    $edges[] = [
                        'from' => $sourceMac !== '' ? $sourceMac : (string) $l['source_mac'],
                        'to' => $targetMac !== '' ? $targetMac : (string) $l['target_mac'],
                    ];
                }
            }
            // Reuse the same unambiguous PBX resolver as the SIP information modal.
            foreach ($rows as $_r) {
                if (($_r['type'] !== 'Phone' && API::getDeviceCategory((string) ($_r['raw_model'] ?? '')) !== 'phone') || empty($_r['mac'])) {
                    continue;
                }
                $_phone_mac = PbxAssociationResolver::normalizeMac((string) $_r['mac']);
                if ($_phone_mac === '') {
                    continue;
                }
                $_phone_pbx = $pbx_for_device_mac[$_phone_mac] ?? null;
                $_pbx_mac = PbxAssociationResolver::normalizeMac((string) ($_phone_pbx['mac'] ?? ''));
                if ($_pbx_mac !== '' && $_pbx_mac !== $_phone_mac) {
                    $edges[] = [
                        'from' => $_phone_mac,
                        'to' => $_pbx_mac,
                        'color' => ['color' => '#6f42c1', 'highlight' => '#8b5cf6'],
                        'dashes' => true,
                        'width' => 2,
                    ];
                }
            }
        } // end show_topology block

        // Build global summary totals from net_stats + rows
        $total_networks = count($net_stats);

        $summary = [
            'router_on'  => 0, 'router_off' => 0,
            'switch_on'  => 0, 'switch_off' => 0,
            'ap_on'      => 0, 'ap_off'     => 0,
            'clients'    => 0,
            'phone_on'   => 0, 'phone_off'  => 0,
        ];
        foreach ($net_stats as $ns) {
            $summary['router_on']  += $ns['router_on'];
            $summary['router_off'] += $ns['router_off'];
            $summary['switch_on']  += $ns['switch_on'];
            $summary['switch_off'] += $ns['switch_off'];
            $summary['ap_on']      += $ns['ap_on'];
            $summary['ap_off']     += $ns['ap_off'];
            $summary['phone_on']   += $ns['phone_on'];
            $summary['phone_off']  += $ns['phone_off'];
            $summary['clients']    += $ns['clients'];
        }

        // ---- Row pre-processing -----
        $fmtB = function (int $b): string {
            if ($b >= 1073741824) {
                return round($b / 1073741824, 1) . ' GB';
            }
            if ($b >= 1048576) {
                return round($b / 1048576, 1) . ' MB';
            }
            if ($b >= 1024) {
                return round($b / 1024, 0) . ' KB';
            }
            return $b . ' B';
        };
        $ipHref = function (string $ip): string {
            return 'http://' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : urlencode($ip));
        };

        $previousTableGroup = null;
        $sipPbxDisplayIndexes = null;
        foreach ($rows as &$r) {
            $us = (int) ($r['uptime_sec'] ?? 0);
            $ud = intdiv($us, 86400);
            $uh = intdiv($us % 86400, 3600);
            $um = intdiv($us % 3600, 60);
            $r['_uptime_str']     = $ud > 0 ? "{$ud}d {$uh}h {$um}m" : ($uh > 0 ? "{$uh}h {$um}m" : "{$um}m");
            $r['_uptime_display'] = $us > 0 ? $r['_uptime_str'] : '—';
            $r['_device_category'] = API::getDeviceCategory((string) ($r['raw_model'] ?? ''));
            $r['_is_phone'] = ($r['type'] === 'Phone') || $r['_device_category'] === 'phone';
            if ($r['_is_phone']) {
                $r['_device_category'] = 'phone';
            }
            $r['_table_group'] = $r['_is_phone'] ? 'phone' : ($r['_device_category'] === 'pbx' ? 'pbx' : 'network');
            $r['_table_group_start'] = $r['_table_group'] !== $previousTableGroup;
            $previousTableGroup = $r['_table_group'];

            $r['_pref_ip'] = $ip_version === 'ipv6'
                ? (!empty($r['ipv6']) ? $r['ipv6'] : $r['private_ip'])
                : (!empty($r['private_ip']) ? $r['private_ip'] : $r['ipv6']);
            $r['_sec_ip']  = $ip_version === 'ipv6'
                ? (!empty($r['ipv6']) && !empty($r['private_ip']) ? $r['private_ip'] : '')
                : (!empty($r['private_ip']) && !empty($r['ipv6']) ? $r['ipv6'] : '');
            $r['_pref_ip_href']   = !empty($r['_pref_ip']) ? $ipHref($r['_pref_ip']) : '';
            $r['_sec_ip_href']    = !empty($r['_sec_ip']) ? $ipHref($r['_sec_ip']) : '';
            $r['_sec_ip_is_ipv6'] = strpos((string) ($r['_sec_ip'] ?? ''), ':') !== false;

            $ul        = (int) ($r['upload_bytes']   ?? 0);
            $dl        = (int) ($r['download_bytes'] ?? 0);
            $ch2       = (int) ($r['channel_2g'] ?? 0);
            $ch5       = (int) ($r['channel_5g'] ?? 0);
            $ls        = $r['last_seen'] ?? '';
            $tip_lines = [];
            if ($ul > 0 || $dl > 0) {
                $tip_lines[] = __('Network usage', 'gdmsintegration');
                $tip_lines[] = __('Upload', 'gdmsintegration') . ': ↑ ' . $fmtB($ul) . '  ' . __('Download', 'gdmsintegration') . ': ↓ ' . $fmtB($dl);
            }
            if ($ch2 > 0 || $ch5 > 0) {
                $ch_parts = [];
                if ($ch2 > 0) {
                    $ch_parts[] = '2.4GHz ch' . $ch2;
                }
                if ($ch5 > 0) {
                    $ch_parts[] = '5GHz ch' . $ch5;
                }
                $tip_lines[] = implode('  ', $ch_parts);
            }
            if (!empty($r['location'])) {
                $tip_lines[] = __('Location', 'gdmsintegration') . ': ' . html_entity_decode($r['location']);
            }
            if (!empty($r['first_seen'])) {
                $tip_lines[] = __('First seen', 'gdmsintegration') . ': ' . substr($r['first_seen'], 0, 16);
            }
            if ($ls) {
                $tip_lines[] = __('Last seen', 'gdmsintegration') . ': ' . substr($ls, 0, 16);
            }
            $r['_uptime_tip'] = implode("\n", $tip_lines);

            $wan_tx           = (int) ($r['wan_tx_bytes'] ?? 0);
            $wan_rx           = (int) ($r['wan_rx_bytes'] ?? 0);
            $r['_ul_val']     = $wan_tx > 0 ? $wan_tx : $ul;
            $r['_dl_val']     = $wan_rx > 0 ? $wan_rx : $dl;
            $r['_ul_fmt']     = $fmtB($r['_ul_val']);
            $r['_dl_fmt']     = $fmtB($r['_dl_val']);
            $traffic_src      = $wan_tx > 0
                ? __('WAN port aggregate (sum of all WAN ports since last router reboot)', 'gdmsintegration')
                : __('Device-reported traffic (wireless client usage)', 'gdmsintegration');
            $fs_raw           = $r['first_seen'] ?? '';
            $r['_traffic_tip'] = $fs_raw
                ? sprintf(__('%s — since first seen in cloud (%s, ~%d days)', 'gdmsintegration'), $traffic_src, substr($fs_raw, 0, 10), max(1, (int) ((time() - strtotime($fs_raw)) / 86400)))
                : $traffic_src;

            $rnet = (string) ($r['network_name'] ?? '');
            $rnetworkId = (int) $r['network_id'];
            $rnetworkKey = $rnetworkId > 0
                ? 'id:' . $rnetworkId
                : 'name:' . strtolower(trim(html_entity_decode($rnet, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $rstat = $net_stats[$rnetworkKey] ?? null;
            $r['_net_data'] = $rstat !== null
                ? array_merge(['name' => html_entity_decode($rnet), 'network_id' => $rnetworkId], $rstat)
                : null;

            // Firmware update badges are controlled exclusively by the live official-firmware check.
            $r['_fw_badge_shown'] = false;

            $_device_mac = PbxAssociationResolver::normalizeMac((string) ($r['mac'] ?? ''));
            $r['_pbx'] = $pbx_for_device_mac[$_device_mac] ?? null;
            $r['_sip_accounts_display'] = [];
            if (!empty($r['sip_accounts'])) {
                if ($sipPbxDisplayIndexes === null) {
                    $sipPbxDisplayIndexes = self::buildSipPbxDisplayIndexes($rows);
                }
                $r['_sip_accounts_display'] = self::enrichSipAccountsWithIndexes($r['sip_accounts'], $sipPbxDisplayIndexes);
            }
            $_sip_tip = !empty($r['sip_status'])
                ? ($r['sip_status'] === 'registered' ? __('SIP Registered', 'gdmsintegration') : __('SIP Unregistered', 'gdmsintegration'))
                : __('Unknown', 'gdmsintegration');
            if (!empty($r['sip_status']) || !empty($r['sip_accounts'])) {
                if (!empty($r['sip_accounts'])) {
                    $extensions = array_values(array_filter(array_map(static fn(array $account): string => (string) ($account['extension'] ?? ''), $r['sip_accounts'])));
                    if ($extensions !== []) {
                        $_sip_tip .= ' · ' . __('Ext', 'gdmsintegration') . ': ' . implode(', ', $extensions);
                    }
                } elseif (!empty($r['sip_extension'])) {
                    $_sip_tip .= ' · ' . __('Ext', 'gdmsintegration') . ': ' . $r['sip_extension'];
                }
                if (!empty($r['dnd'])) {
                    $_sip_tip .= ' · ' . __('Do Not Disturb', 'gdmsintegration');
                }
            }
            $r['_sip_tip']   = $_sip_tip;
            $r['_sip_color'] = $r['sip_status'] === 'registered'
                ? '#28a745'
                : ($r['sip_status'] === 'unregistered' ? '#dc3545' : '#6c757d');
        }
        unset($r);

        // --- Summary cards ----
        $total = $online + $offline;
        $pct   = $total > 0 ? round($online / $total * 100) : 0;
        $cards = [
            ['icon' => 'ti-network', 'label' => __('Networks', 'gdmsintegration'), 'vals' => [['v' => $total_networks,       'lbl' => __('Total', 'gdmsintegration'), 'cls' => '']]],
            ['icon' => 'ti-sitemap', 'label' => __('Router', 'gdmsintegration'), 'vals' => [['v' => $summary['router_on'], 'lbl' => __('Online', 'gdmsintegration'), 'cls' => 'text-success'], ['v' => $summary['router_off'],  'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger']]],
            ['icon' => 'ti-server',  'label' => __('Switch', 'gdmsintegration'), 'vals' => [['v' => $summary['switch_on'], 'lbl' => __('Online', 'gdmsintegration'), 'cls' => 'text-success'], ['v' => $summary['switch_off'],  'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger']]],
            ['icon' => 'ti-wifi',    'label' => 'AP',                                  'vals' => [['v' => $summary['ap_on'],     'lbl' => __('Online', 'gdmsintegration'), 'cls' => 'text-success'], ['v' => $summary['ap_off'],      'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger']]],
            ['icon' => 'ti-phone',   'label' => __('Phones & PBX', 'gdmsintegration'), 'vals' => [['v' => $summary['phone_on'],  'lbl' => __('Online', 'gdmsintegration'), 'cls' => 'text-success'], ['v' => $summary['phone_off'],  'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger']]],
            ['icon' => 'ti-users',   'label' => __('Clients', 'gdmsintegration'), 'vals' => [['v' => $summary['clients'],   'lbl' => __('Connected', 'gdmsintegration'), 'cls' => 'text-info']]],
        ];

        $critical_devices = array_values(array_filter($rows, fn($r) => $r['sla'] === __('Critical', 'gdmsintegration') && !$r['online']));

        // Operational intelligence: infrastructure health and WAN overview.
        $health_sum = 0;
        $wan_up = 0;
        $wan_down = 0;
        $wan_degraded = 0;
        foreach ($rows as &$rr) {
            $score = 100;
            if (!$rr['online']) {
                $score -= 55;
            }
            if (($rr['uptime'] ?? 100) < 99) {
                $score -= 15;
            }
            if (($rr['uptime'] ?? 100) < 95) {
                $score -= 15;
            }
            if (!empty($rr['sync_failure_msg'])) {
                $score -= 10;
            }
            $rr['health'] = max(0, $score);
            $health_sum += $rr['health'];
            foreach (($rr['wan_ports'] ?? []) as $wp) {
                // Use the normalized role written by Sync. LAN records also carry
                // connectStatus/wanType/gateway keys, so those fields are not WAN markers.
                if ((int) ($wp['role'] ?? 0) !== 1) {
                    continue;
                }
                $link = (int) ($wp['link'] ?? 0);
                $conn = (int) ($wp['connectStatus'] ?? 0);
                $ip = trim((string) ($wp['ip'] ?? $wp['ipv4'] ?? ''));
                if ($link !== 1) {
                    $wan_down++;
                } elseif ($conn !== 1 || $ip === '') {
                    $wan_degraded++;
                } else {
                    $wan_up++;
                }
            }
        }
        unset($rr);
        $health_score = $total > 0 ? round($health_sum / $total, 1) : 100.0;
        // Operational WAN state is based on Internet availability, not only the
        // physical Ethernet link. A port with link up but connectStatus=0 is
        // operationally DOWN (no Internet), while wan_degraded keeps the
        // useful clarification that the physical port itself remains active.
        $wan_down_total = $wan_down + $wan_degraded;
        $wan_active = $wan_up;
        $ops_summary = ['health' => $health_score,'wan_up' => $wan_up,'wan_active' => $wan_active,'wan_down' => $wan_down_total,'wan_degraded' => $wan_degraded];

        // ---- URL map ----
        $_base = $_plugin_web . '/';
        $_eid  = 'entities_id=' . $entities_id;
        $urls  = [
            'sync'            => $_base . 'sync?' . $_eid,
            'fw_check'        => $_base . 'firmware?action=check&' . $_eid,
            'fw_upgrade'      => $_base . 'firmware?action=upgrade&' . $_eid,
            'fw_check_all'    => $_base . 'firmware?action=check_all&' . $_eid,
            'fw_upgrade_gdms' => $_base . 'firmware?action=upgrade_gdms&' . $_eid,
            'reboot_gdms'        => $_base . 'firmware?action=reboot_gdms&' . $_eid,
            'factory_reset_gdms' => $_base . 'firmware?action=factory_reset_gdms&' . $_eid,
            'ports'           => $_base . 'ports?action=status&' . $_eid,
            'clients'         => $_base . 'clients?' . $_eid,
            'alerts'          => $_base . 'alerts?' . $_eid,
            'history_export'  => $_base . 'history/export?' . $_eid,
        ];

        // --- JS strings ----
        $js_strings = [
            'syncing'         => __('Syncing…', 'gdmsintegration'),
            'rebootWarning'   => __('The device will reboot during the update. Schedule during a maintenance window.', 'gdmsintegration'),
            'reqFailed'       => __('Request failed. Check connection.', 'gdmsintegration'),
            'schedOk'         => __('Update scheduled successfully. The device will update shortly.', 'gdmsintegration'),
            'applyAsap'       => __('Apply now (ASAP)', 'gdmsintegration'),
            'schedUpdate'     => __('Schedule update', 'gdmsintegration'),
            'scheduling'      => __('Scheduling…', 'gdmsintegration'),
            'linkDown'        => __('Link down', 'gdmsintegration'),
            'linkUp'          => __('Link up', 'gdmsintegration'),
            'online'          => __('Online', 'gdmsintegration'),
            'noInternet'      => __('No internet', 'gdmsintegration'),
            'noPorts'         => __('No ports found', 'gdmsintegration'),
            'netUsage'        => __('Network usage', 'gdmsintegration'),
            'netTraffic'      => __('Network traffic', 'gdmsintegration'),
            'firstSeen'       => __('First seen', 'gdmsintegration'),
            'lastSeen'        => __('Last seen', 'gdmsintegration'),
            'wanOnline'       => __('WAN online', 'gdmsintegration'),
            'wanNoInet'       => __('WAN up, no internet', 'gdmsintegration'),
            'wanUnknown'      => __('WAN up, unknown', 'gdmsintegration'),
            'lanUp'           => __('LAN up', 'gdmsintegration'),
            'connection'      => __('Connection', 'gdmsintegration'),
            'ipAddress'       => __('IP Address', 'gdmsintegration'),
            'wanTypeLbl'      => __('Type', 'gdmsintegration'),
            'connectedFor'    => __('Connected for', 'gdmsintegration'),
            'statusLbl'       => __('Status', 'gdmsintegration'),
            'portType'        => __('Port type', 'gdmsintegration'),
            'negotiatedSpeed' => __('Negotiated speed', 'gdmsintegration'),
            'portLabel'       => __('Port label', 'gdmsintegration'),
            'unknown'         => __('Unknown', 'gdmsintegration'),
            'firmwareUnverified' => __('Firmware version could not be verified', 'gdmsintegration'),
            'firmwareStatus'     => __('Firmware status', 'gdmsintegration'),
            'betaChannelPublished' => __('Beta channel published version', 'gdmsintegration'),
            'officialFw'      => __('Official firmware', 'gdmsintegration'),
            'latestAvail'     => __('Latest available', 'gdmsintegration'),
            'gdmsManaged'     => __('GDMS managed', 'gdmsintegration'),
            'gdmsVersionNote' => __('GDMS applies the latest firmware available in its repository. The selected version is informational only.', 'gdmsintegration'),
            'selectVersion'   => __('Select version', 'gdmsintegration'),
            'curFw'           => __('Current firmware', 'gdmsintegration'),
            'deviceMac'       => __('Device MAC', 'gdmsintegration'),
            'hdx'             => __('HDX', 'gdmsintegration'),
            'fdx'             => __('FDX', 'gdmsintegration'),
            'disconnected'    => __('Disconnected', 'gdmsintegration'),
            'lbl_online'      => __('online', 'gdmsintegration'),
            'lbl_offline'     => __('offline', 'gdmsintegration'),
            'lbl_total'       => __('Total', 'gdmsintegration'),
            'lbl_router'      => __('Router', 'gdmsintegration'),
            'lbl_switch'      => __('Switch', 'gdmsintegration'),
            'lbl_ap'          => __('AP', 'gdmsintegration'),
            'lbl_phones'      => __('Phones & PBX', 'gdmsintegration'),
            'lbl_clients'     => __('Clients', 'gdmsintegration'),
            'noClients'       => __('No clients connected', 'gdmsintegration'),
            'noAlerts'        => __('No recent alerts', 'gdmsintegration'),
            'alertsError'     => __('Could not load alerts', 'gdmsintegration'),
            'alertTime'       => __('Time', 'gdmsintegration'),
            'alertSev'        => __('Severity', 'gdmsintegration'),
            'alertDevice'     => __('Device', 'gdmsintegration'),
            'alertMsg'        => __('Alert', 'gdmsintegration'),
            'alertDismiss'    => __('Dismiss', 'gdmsintegration'),
            'hostname'        => __('Hostname', 'gdmsintegration'),
            'band'            => __('Band', 'gdmsintegration'),
            'signal'          => __('Signal', 'gdmsintegration'),
            'txrx'            => __('TX / RX', 'gdmsintegration'),
            'alertCategory'   => __('Category', 'gdmsintegration'),
            'alertReason'     => __('Reason', 'gdmsintegration'),
            'wanDhcp'         => __('DHCP', 'gdmsintegration'),
            'wanStatic'       => __('Static', 'gdmsintegration'),
            'wanPppoe'        => __('PPPoE', 'gdmsintegration'),
            'wanPptp'         => __('PPTP', 'gdmsintegration'),
            'wanL2tp'         => __('L2TP', 'gdmsintegration'),
            'upgrading'       => __('Updating…', 'gdmsintegration'),
            'deviceName'      => __('Device', 'gdmsintegration'),
            'fwPrivateIp'     => __('Private IP', 'gdmsintegration'),
            'fwMacCopied'     => __('Copied!', 'gdmsintegration'),
            'fwPageLbl'       => __('Firmware downloads', 'gdmsintegration'),
            'fwUpdateTitle'   => __('Firmware Update Available', 'gdmsintegration'),
            'fwChecking'      => __('Checking firmware…', 'gdmsintegration'),
            'fwScheduleFor'   => __('Schedule for', 'gdmsintegration'),
            'fwShowPicker'    => __('Show date picker', 'gdmsintegration'),
            'fwLeaveEmpty'    => __('Leave empty to apply as soon as possible', 'gdmsintegration'),
            'close'           => __('Close', 'gdmsintegration'),
            'portStatus'      => __('Port Status', 'gdmsintegration'),
            'upload'          => __('Upload', 'gdmsintegration'),
            'download'        => __('Download', 'gdmsintegration'),
            'gateway'         => __('Gateway', 'gdmsintegration'),
            'dns'             => __('DNS', 'gdmsintegration'),
            'wanMac'          => __('WAN MAC', 'gdmsintegration'),
            'txrxPkts'        => __('TX / RX packets', 'gdmsintegration'),
            'linkSpeed'       => __('Link speed', 'gdmsintegration'),
            'description'     => __('Description', 'gdmsintegration'),
            'connectedClients' => __('Connected Clients', 'gdmsintegration'),
            'sipRegistered'   => __('Registered', 'gdmsintegration'),
            'sipUnregistered' => __('Unregistered', 'gdmsintegration'),
            'sipStatusLbl'    => __('SIP Status', 'gdmsintegration'),
            'extension'       => __('Extension', 'gdmsintegration'),
            'sipAccounts'     => __('SIP Accounts', 'gdmsintegration'),
            'site'            => __('Site', 'gdmsintegration'),
            'macLbl'          => __('MAC', 'gdmsintegration'),
            'copyMac'         => __('Copy MAC', 'gdmsintegration'),
            'publicIpLbl'     => __('Public IP', 'gdmsintegration'),
            'lastSeenCap'     => __('Last Seen', 'gdmsintegration'),
            'pbxUcm'          => __('PBX / UCM', 'gdmsintegration'),
            'dndLbl'          => __('Do Not Disturb', 'gdmsintegration'),
            'active'          => __('Active', 'gdmsintegration'),
            'off'             => __('Off', 'gdmsintegration'),
            'synchronized'    => __('Synchronized', 'gdmsintegration'),
            'yes'             => __('Yes', 'gdmsintegration'),
            'no'              => __('No', 'gdmsintegration'),
            'syncError'       => __('Sync Error', 'gdmsintegration'),
            'scheduledTask'   => __('Scheduled Task', 'gdmsintegration'),
            'pending'         => __('Pending', 'gdmsintegration'),
            'none'            => __('None', 'gdmsintegration'),
            'factoryResetBtn'        => __('Factory Reset', 'gdmsintegration'),
            'factoryResetWarning'    => __('WARNING: Factory reset will permanently erase ALL device configuration, SIP accounts, contacts, and customizations. The device will restart and return to factory defaults. This action cannot be undone.', 'gdmsintegration'),
            'factoryResetConfirmYes' => __('I understand — Reset now', 'gdmsintegration'),
            'factoryResetOk'         => __('Factory reset task created. Device will restart and restore factory defaults.', 'gdmsintegration'),
            'factoryResetting'       => __('Resetting…', 'gdmsintegration'),
            'rebootBtn'       => __('Reboot', 'gdmsintegration'),
            'rebootConfirm'   => __('Reboot this device?', 'gdmsintegration'),
            'rebootConfirmYes' => __('Yes, reboot now', 'gdmsintegration'),
            'rebootOk'        => __('Reboot task created. Device will restart shortly.', 'gdmsintegration'),
            'rebooting'       => __('Rebooting…', 'gdmsintegration'),
            'confirm'         => __('Confirm', 'gdmsintegration'),
            'cancel'          => __('Cancel', 'gdmsintegration'),
            'clientsPartialRetry' => __('The API returned a partial client list. Please try again.', 'gdmsintegration'),
            'clientsPartialNotice' => __('The API returned a partial client list; some clients may not be shown.', 'gdmsintegration'),
            'alertsPartialRetry' => __('The API returned partial alerts. Please try again.', 'gdmsintegration'),
            'alertsPartialNotice' => __('The alert list is partial; some alerts may not be shown.', 'gdmsintegration'),
            'filterAll'       => __('All', 'gdmsintegration'),
            'filterOnline'    => __('Online', 'gdmsintegration'),
            'filterOffline'   => __('Offline', 'gdmsintegration'),
            'filterNetwork'   => __('Network', 'gdmsintegration'),
            'filterPhone'     => __('Phone', 'gdmsintegration'),
            'filterDevice'    => __('Device', 'gdmsintegration') . '...',
            'syncWaiting' => __('Another synchronization is running. Waiting to retry…', 'gdmsintegration'),
            'fwScheduleExpired' => __('The selected time has passed. Please choose a new future time.', 'gdmsintegration'),
        ];

        // Keep data in an HTML-escaped textarea, never in an executable inline script.
        // JSON_HEX_* prevents untrusted strings from introducing HTML delimiters.
        $json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $dashboard_data_json = json_encode([
            'strings' => $js_strings,
            'nodes' => $nodes,
            'edges' => $edges,
            'chartLabels' => $chart_labels,
            'chartDatasets' => $chart_datasets,
            'urls' => $urls,
            'refreshInterval' => $refresh_interval,
            'needsAutoSync' => $needs_auto_sync,
            'canSync' => $can_sync,
            'fpLang' => $_fp_lang,
            'fpRegion' => $_fp_region,
            'showTopology' => $show_topology === 1,
        ], $json_flags);


        echo TemplateRenderer::getInstance()->render('@gdmsintegration/dashboard.html.twig', [
            'is_configured'    => true,
            'entities_id'      => $entities_id,
            'rows'             => $rows,
            'cards'            => $cards,
            'summary'          => $summary,
            'net_stats'        => $net_stats,
            'online'           => $online,
            'offline'          => $offline,
            'total'            => $total,
            'pct'              => $pct,
            'total_networks'   => $total_networks,
            'critical_devices' => $critical_devices,
            'ops_summary'      => $ops_summary,
            'chart_datasets'   => $chart_datasets,
            'chart_labels'     => $chart_labels,
            'nodes'            => $nodes,
            'edges'            => $edges,
            'show_topology'    => $show_topology,
            'chart_days'       => $chart_days,
            'has_gwn_config'   => !empty($config['gwn_client_id']),
            'js_strings'       => $js_strings,
            'urls'             => $urls,
            'dashboard_data_json' => $dashboard_data_json,
            'fp_lang'          => $_fp_lang,
            'fp_region'        => $_fp_region,
            'refresh_interval' => $refresh_interval,
            'needs_auto_sync' => $needs_auto_sync,
            'last_sync_label'  => $last_sync_label,
            'last_sync_at'     => $last_sync_at ?? '',
            'config_url'       => $_plugin_web . '/config',
        ]);

        \Html::footer();
        return new Response(ob_get_clean());
    }
}
