<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class FirmwareService
{
    private static function gdmsCdnUrl(string $model): string {
        $m = strtoupper(preg_replace('/[\s\-_]+/', '', trim($model)));
        if (preg_match('/^GWN|^GSS/', $m)) return '';
        $isV2 = str_contains($m, 'V2');
        if     (str_starts_with($m, 'GRP260'))           $b = 'grp2600';
        elseif (str_starts_with($m, 'GRP26'))            $b = 'grp2610';
        elseif ($m === 'HT841' || $m === 'HT881')        $b = 'ht8x1';
        elseif ($isV2 && preg_match('/^HT80[12]/', $m))  $b = 'ht80xv2';
        elseif ($isV2 && preg_match('/^HT81[248]/', $m)) $b = 'ht81xv2';
        elseif ($m === 'HT801')                          $b = 'ht801';
        elseif ($m === 'HT802')                          $b = 'ht802';
        elseif (preg_match('/^HT81[24]/', $m))           $b = 'ht81x';
        elseif ($m === 'HT818')                          $b = 'ht818';
        elseif ($m === 'HT813')                          $b = 'ht813';
        elseif ($m === 'GAC2570')                        $b = 'gac2570';
        elseif ($m === 'GAC2500')                        $b = 'gac2500';
        elseif ($m === 'GBX20')                          $b = 'gbx20';
        elseif ($m === 'GSC3574' || $m === 'GSC3575')    $b = 'gsc3575';
        elseif ($m === 'GSC3570')                        $b = 'gsc3570';
        elseif (str_starts_with($m, 'GSC3518'))          $b = 'gsc3518';
        elseif ($m === 'GSC3505')                        $b = 'gsc3505';
        elseif ($m === 'GSC3510')                        $b = 'gsc3510';
        elseif ($m === 'GSC3506' || $m === 'GSC3516')    $b = 'gsc35x6';
        elseif ($m === 'GDS3702')                        $b = 'gds3702';
        elseif ($m === 'GDS3705')                        $b = 'gds3705';
        elseif ($m === 'GDS3710' || $m === 'GDS3712')    $b = 'gds37xx_';
        elseif (preg_match('/^GDS372[567]/', $m))        $b = 'gds372x';
        else                                             $b = strtolower($m);
        return 'https://firmware.grandstream.com/' . $b . 'fw.bin';
    }

    private static function gdmsFirmwareSlug(string $model): ?array {
        $m = strtoupper($model);
    
        if (preg_match('/^GWN|^GSS/', $m)) return null;
    
        // Only models with a working grandstream.com firmware page (version scraping).
        // Phones use CDN URL only — no firmware page exists to scrape.
        $map = [
            'UCM6300'  => ['slug' => 'ucm6300',  'official' => true],
            'UCM6301'  => ['slug' => 'ucm6300',  'official' => true],
            'UCM6302'  => ['slug' => 'ucm6300',  'official' => true],
            'UCM6304'  => ['slug' => 'ucm6300',  'official' => true],
            'UCM6300A' => ['slug' => 'ucm6300', 'official' => true],
            'UCM6302A' => ['slug' => 'ucm6300', 'official' => true],
            'UCM6304A' => ['slug' => 'ucm6300', 'official' => true],
            'UCM6308A' => ['slug' => 'ucm6300', 'official' => true],
            'UCM6308'  => ['slug' => 'ucm6300',  'official' => true],
            'UCM6510'  => ['slug' => 'ucm6510',  'official' => true],
        ];
    
        // Longest-prefix match
        $best = null;
        $bestLen = 0;
        foreach ($map as $prefix => $info) {
            if (str_starts_with($m, $prefix) && strlen($prefix) > $bestLen) {
                $best    = $info;
                $bestLen = strlen($prefix);
            }
        }
        return $best;
    }

    private static function gdmsAcquireRateLimit(string $action, string $mac, int $ttl = 60): bool {
        global $DB;
        $col = $action === 'reboot' ? 'last_reboot_at' : 'last_factory_reset_at';
        $macl = strtolower($mac);
        $now = date('Y-m-d H:i:s');
        $cutoff = date('Y-m-d H:i:s', time() - $ttl);
        $table = 'glpi_plugin_gdmsintegration_devices';
    
        try {
            $rows = $DB->request([
                'SELECT' => ['id'],
                'FROM' => $table,
                'WHERE' => ['mac' => $macl],
                'LIMIT' => 1,
            ]);
    
            if (count($rows) === 0) {
                try {
                    $DB->insert($table, ['mac' => $macl, $col => $now]);
                    return true;
                } catch (\Throwable $e) {
                    // A concurrent sync/request may have created the row. Fall
                    // through to the atomic UPDATE below.
                }
            }
    
            $DB->update(
                $table,
                [$col => $now],
                [
                    'mac' => $macl,
                    [
                        'OR' => [
                            [$col => null],
                            [$col => ['<=', $cutoff]],
                        ],
                    ],
                ]
            );
            return $DB->affectedRows() > 0;
        } catch (\Throwable $e) {
            \GlpiPlugin\Gdmsintegration\Utils::warning('Rate-limit check failed for ' . $action . ': ' . $e->getMessage());
            return false;
        }
    }

    private static function gdmsClearRateLimit(string $action, string $mac): void {
        global $DB;
        $col  = $action === 'reboot' ? 'last_reboot_at' : 'last_factory_reset_at';
        $macl = strtolower($mac);
        try {
            $DB->update('glpi_plugin_gdmsintegration_devices', [$col => null], ['mac' => $macl]);
        } catch (\Throwable $e) {
            // Best-effort rollback of the cooldown when the provider rejects the action.
        }
    }

    public static function handle(Request $request): JsonResponse
    {
        $query = $request->query;
                $form = $request->request;
        $requestMethod = $request->getMethod();
        $actionEarly = $query->get('action', 'check');
        if ($actionEarly === 'factory_reset_gdms') {
            \Session::checkRight('config', PURGE);   // factory reset requires higher right than UPDATE
        } elseif (in_array($actionEarly, ['upgrade', 'upgrade_gdms', 'reboot_gdms'], true)) {
            \Session::checkRight('config', UPDATE);
        } else {
            \Session::checkRight('config', READ);
        }

        $entities_id = (int)$query->get('entities_id', \Session::getActiveEntity());
        if (!\Session::haveAccessToEntity($entities_id)) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Access denied'], 403); }
        $action      = $query->get('action', 'check');

        $config_obj = new \GlpiPlugin\Gdmsintegration\Config();
        $config     = $config_obj->getConfigByEntity($entities_id);

        // ── CDN URL HELPER ───────────────────────────────────────────────────────────
        // Returns https://firmware.grandstream.com/{base}fw.bin for any UC/phone model.
        // GWN/GSS devices return ''.


        // ── MODEL → FIRMWARE PAGE SLUG MAP ───────────────────────────────────────────
        // Maps model prefix (uppercase) to grandstream.com official firmware page slug.
        // Only models with a grandstream.com official firmware page are listed.
        // GWN devices use the GWN Cloud API instead.



        // ── CHECK (original — GWN stable only) ───────────────────────────────────────
        if ($action === 'check') {
            $state_obj = new \GlpiPlugin\Gdmsintegration\Device();
            $all       = $state_obj->find();

            if (empty($all)) { return new \Symfony\Component\HttpFoundation\JsonResponse([]); }

            $mac_data    = [];
            $mac_to_model = [];

            foreach ($all as $row) {
                $mac = strtolower(trim($row['mac'] ?? ''));
                $fw  = trim($row['firmware'] ?? '');
                if (!$mac || !$fw) continue;
                $mac_data[$mac] = $row;
            }

            // Peer-max per model group fallback
            $type_max_fw = [];
            foreach ($all as $row) {
                $mac  = strtolower(trim($row['mac'] ?? ''));
                $fw   = trim($row['firmware'] ?? '');
                $key  = 'model_' . ($row['model'] ?? '');
                if ($fw && (!isset($type_max_fw[$key]) || version_compare($fw, $type_max_fw[$key], '>'))) {
                    $type_max_fw[$key] = $fw;
                }
            }

            // GWN official versions via upgrade/version
            $official_latest = [];
            $firmware_errors = [];
            if (!empty($config['gwn_client_id'])) {
                $by_network = [];
                foreach ($mac_data as $mac => $row) {
                    $nid = (int)($row['network_id'] ?? 0);
                    if ($nid) $by_network[$nid][] = $mac;
                }
                foreach ($by_network as $network_id => $macs) {
                    $versions = \GlpiPlugin\Gdmsintegration\API::gwnGetFirmwareVersions($config, $network_id);
                    $firmware_errors += \GlpiPlugin\Gdmsintegration\API::gwnGetLastFirmwareErrors();
                    foreach ($versions as $v) {
                        $apiMac = strtolower(str_replace(['-',' '], ':', trim($v['mac'] ?? '')));
                        $stable = trim((string)($v['stableVersion'] ?? ''));
                        if ($apiMac && $stable !== '') {
                            if (!isset($official_latest[$apiMac]) || version_compare($stable, $official_latest[$apiMac], '>')) {
                                $official_latest[$apiMac] = $stable;
                            }
                        }
                    }
                }
            }

            $result = [];
            foreach ($mac_data as $mac => $row) {
                $current = $row['firmware'] ?? '';
                if (empty($current)) continue;
                $peerMax  = $type_max_fw['model_' . ($row['model'] ?? '')] ?? '';
                $official = $official_latest[$mac] ?? '';
                $latest   = '';
                if (!empty($official) && version_compare($official, $current, '>')) {
                    $latest = $official;
                } elseif (!empty($peerMax) && version_compare($peerMax, $current, '>')) {
                    $latest = $peerMax;
                }
                $hasUpdate = !empty($latest);
                \GlpiPlugin\Gdmsintegration\Utils::debug("FW {$mac}: current={$current} peerMax={$peerMax} official={$official} hasUpdate=" . ($hasUpdate?'YES':'no'));
                $result[] = [
                    'mac'            => $mac,
                    'currentVersion' => $current,
                    'latestVersion'  => $latest ?: $peerMax ?: $official,
                    'hasUpdate'      => $hasUpdate,
                    'network_id'     => (int)($row['network_id'] ?? 0),
                    'firmwareCheckError' => $firmware_errors[(int)($row['network_id'] ?? 0)] ?? null,
                ];
            }
            $updates = count(array_filter($result, fn($r) => $r['hasUpdate']));
            \GlpiPlugin\Gdmsintegration\Utils::log("Firmware check complete: " . count($result) . " device(s) checked, {$updates} update(s) available");
            return new \Symfony\Component\HttpFoundation\JsonResponse($result);
        }

        // ── CHECK_ALL (new — all devices, official + beta) ────────────────────────────
        if ($action === 'check_all') {
            $state_obj = new \GlpiPlugin\Gdmsintegration\Device();
            $all       = $state_obj->find();
            if (empty($all)) { return new \Symfony\Component\HttpFoundation\JsonResponse([]); }

            // Cache slug → versions to avoid duplicate HTTP requests for same model family
            $slug_cache = []; // slug → ['official' => '...', 'officialUrl' => '...']

            // GWN official versions from GWN Cloud API (same as check action)
            $gwn_official = [];
            $firmware_errors = [];
            if (!empty($config['gwn_client_id'])) {
                $by_network = [];
                foreach ($all as $row) {
                    $mac = strtolower(trim($row['mac'] ?? ''));
                    $nid = (int)($row['network_id'] ?? 0);
                    if ($mac && $nid) $by_network[$nid][] = $mac;
                }
                $allVersions = \GlpiPlugin\Gdmsintegration\API::gwnGetFirmwareVersionsBatch($config, array_keys($by_network));
                $firmware_errors = \GlpiPlugin\Gdmsintegration\API::gwnGetLastFirmwareErrors();
                foreach ($allVersions as $versions) {
                    foreach ($versions as $v) {
                        $apiMac = strtolower(str_replace(['-',' '], ':', trim($v['mac'] ?? '')));
                        if ($apiMac) {
                            $gwn_official[$apiMac] = trim($v['stableVersion'] ?? '') ?: null;
                        }
                    }
                }
            }

            $result = [];
            foreach ($all as $row) {
                $mac     = strtolower(trim($row['mac'] ?? ''));
                $current = trim($row['firmware'] ?? '');
                $model   = trim($row['model'] ?? '');
                if (!$mac || !$current) continue;

                $official = null;
                $beta     = null;
                $slugInfo = null;

                // GWN devices: use GWN Cloud API result
                if (preg_match('/^GWN|^GSS/i', $model)) {
                    $official = $gwn_official[$mac] ?? null;
                } else {
                    // UC/phone devices: scrape official firmware page only
                    $slugInfo = self::gdmsFirmwareSlug($model);
                    if ($slugInfo && !empty($slugInfo['official'])) {
                        $slug = $slugInfo['slug'];
                        if (!isset($slug_cache[$slug])) {
                            $slug_cache[$slug] = \GlpiPlugin\Gdmsintegration\API::scrapeFirmwareVersions($slug);
                            \GlpiPlugin\Gdmsintegration\Utils::debug("Scraped firmware for slug '{$slug}': " . json_encode($slug_cache[$slug]));
                        }
                        $versions = $slug_cache[$slug];
                        $official = $versions['official'] ?? null;
                    }
                }

                // Only official/stable firmware can create an actionable update.
                // Beta/RC/alpha/dev/test releases are deliberately ignored.
                $hasUpdate = $official !== null && version_compare($official, $current, '>');

                \GlpiPlugin\Gdmsintegration\Utils::debug("FW_ALL {$mac} ({$model}): current={$current} official=" . ($official ?? 'n/a') . " hasUpdate=" . ($hasUpdate?'YES':'no'));

                // officialUrl: scraped URL for UCM, else CDN URL for all UC/phone models
                $scrapedUrl = isset($slugInfo['slug']) ? ($slug_cache[$slugInfo['slug']]['officialUrl'] ?? null) : null;
                $officialUrl = $scrapedUrl ?: (preg_match('/^GWN|^GSS/i', $model) ? null : self::gdmsCdnUrl($model)) ?: null;

                $result[] = [
                    'mac'            => $mac,
                    'model'          => $model,
                    'currentVersion' => $current,
                    'official'       => $official,
                    'officialUrl'    => $officialUrl,
                    'hasUpdate'      => $hasUpdate,
                    'network_id'     => (int)($row['network_id'] ?? 0),
                    'firmwareCheckError' => $firmware_errors[(int)($row['network_id'] ?? 0)] ?? null,
                    'isGwn'          => (bool)preg_match('/^GWN|^GSS/i', $model),
                ];
            }

            $updates = count(array_filter($result, fn($r) => $r['hasUpdate']));
            \GlpiPlugin\Gdmsintegration\Utils::log("Firmware check_all complete: " . count($result) . " device(s), {$updates} update(s) available");
            return new \Symfony\Component\HttpFoundation\JsonResponse($result);
        }

        // ── UPGRADE (existing — GWN only) ─────────────────────────────────────────────
        if ($action === 'upgrade') {
            if ($requestMethod !== 'POST') { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'POST required']); }
            if (empty($config['gwn_client_id'])) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'GWN not configured']); }

            $rawMacs = $form->get('macs', null);
            if ($rawMacs !== null) {
                $macs = array_filter(array_map('strtoupper', json_decode($rawMacs, true) ?? []));
            } else {
                $body = json_decode($request->getContent() ?: '{}', true) ?? [];
                $macs = array_filter(array_map('strtoupper', (array)($body['macs'] ?? [])));
            }
            if (empty($macs)) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'No MACs provided']); }

            \GlpiPlugin\Gdmsintegration\Utils::log("Firmware upgrade (GWN) requested — MACs: " . implode(', ', $macs));

            $scheduleTimeMs = 0;
            $rawTime = $form->get('scheduleTimeMs', null);
            if ($rawTime !== null) $scheduleTimeMs = max(0, (int)$rawTime);

            $result = \GlpiPlugin\Gdmsintegration\API::gwnScheduleUpgrade($config, array_values($macs), $scheduleTimeMs);
            if (!empty($result['error'])) {
                \GlpiPlugin\Gdmsintegration\Utils::log("Firmware upgrade (GWN) FAILED — " . $result['error']);
            } else {
                $ok = implode(', ', (array)($result['success'] ?? []));
                \GlpiPlugin\Gdmsintegration\Utils::log("Firmware upgrade (GWN) scheduled OK — MACs: " . ($ok ?: 'none'));
            }
            return new \Symfony\Component\HttpFoundation\JsonResponse($result);
        }

        // ── UPGRADE_GDMS (new — UC devices via task/add) ──────────────────────────────
        if ($action === 'upgrade_gdms') {
            if ($requestMethod !== 'POST') { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'POST required']); }
            if (empty($config['client_id']) || empty($config['client_secret'])) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'GDMS UC not configured']); }

            $rawBody = $request->getContent() ?: '{}';
            $body    = json_decode($rawBody, true) ?? [];

            // Also support FormData
            $mac         = $form->get('mac', $body['mac']         ?? '');
            $version     = $form->get('version', $body['version']     ?? '');
            $downloadUrl = trim($form->get('downloadUrl', $body['downloadUrl'] ?? ''));
            $scheduleMs  = (int)($form->get('scheduleMs', $body['scheduleMs'] ?? 0));

            $mac     = strtoupper(trim($mac));
            $version = trim($version);

            if (!$mac || (!$version && !$downloadUrl)) {
                return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'mac and version (or downloadUrl) required']);
            }
            if ($downloadUrl !== '') {
                $dlScheme = strtolower(parse_url($downloadUrl, PHP_URL_SCHEME) ?? '');
                $dlHost   = strtolower(parse_url($downloadUrl, PHP_URL_HOST) ?? '');
                if (!in_array($dlScheme, ['http', 'https'], true) ||
                    !in_array($dlHost, ['firmware.grandstream.com', 'fw.gdms.cloud'], true)) {
                    return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'downloadUrl host not allowed']);
                }
            }

            // MAC must be in colon format for task/add
            // Accept both "C074ADDEE28E" and "C0:74:AD:DE:E2:8E"
            if (!str_contains($mac, ':')) {
                $mac = implode(':', str_split($mac, 2));
            }

            // Construct CDN URL from model when not supplied by caller
            if ($downloadUrl === '') {
                $state_obj = new \GlpiPlugin\Gdmsintegration\Device();
                $rows   = $state_obj->find(['mac' => strtolower($mac)]);
                $devRow = !empty($rows) ? reset($rows) : null;
                if ($devRow && !empty($devRow['model'])) {
                    $downloadUrl = self::gdmsCdnUrl($devRow['model']);
                }
            }

            \GlpiPlugin\Gdmsintegration\Utils::log("Firmware upgrade (GDMS task) requested — MAC: {$mac} version: {$version} url: " . ($downloadUrl ?: '(none)'));

            $result = \GlpiPlugin\Gdmsintegration\API::gdmsCreateUpgradeTask($config, $mac, $version, $scheduleMs, $downloadUrl);
            if (!empty($result['error'])) {
                \GlpiPlugin\Gdmsintegration\Utils::log("Firmware upgrade (GDMS task) FAILED — " . $result['error']);
            }
            return new \Symfony\Component\HttpFoundation\JsonResponse($result);
        }

        // ── DESTRUCTIVE ACTION RATE LIMIT — DB-backed, query-builder only ────────────
        // The timestamp is claimed atomically with an UPDATE whose WHERE clause checks
        // the previous timestamp. This avoids database advisory-lock SQL functions
        // while still preventing concurrent requests from both passing.





        // ── REBOOT_GDMS — UC devices via task/add taskType=1 ──────────────────────────
        if ($action === 'reboot_gdms') {
            if ($requestMethod !== 'POST') { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'POST required']); }
            if (empty($config['client_id']) || empty($config['client_secret'])) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'GDMS UC not configured']); }

            $mac = strtoupper(trim($form->get('mac', '')));
            if (!$mac) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'mac is required']); }
            if (!str_contains($mac, ':')) {
                $mac = implode(':', str_split($mac, 2));
            }
            if (!self::gdmsAcquireRateLimit('reboot', $mac, 60)) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Rate limit — wait 60 s between reboots for the same device']); }

            \GlpiPlugin\Gdmsintegration\Utils::log("Reboot (GDMS task) requested — MAC: {$mac}");
            $result = \GlpiPlugin\Gdmsintegration\API::gdmsCreateRebootTask($config, $mac);
            if (!empty($result['error'])) {
                \GlpiPlugin\Gdmsintegration\Utils::log("Reboot (GDMS task) FAILED — " . $result['error']);
                self::gdmsClearRateLimit('reboot', $mac);
            }
            return new \Symfony\Component\HttpFoundation\JsonResponse($result);
        }

        // ── FACTORY_RESET_GDMS — UC devices via task/add taskType=2 ───────────────────
        if ($action === 'factory_reset_gdms') {
            if ($requestMethod !== 'POST') { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'POST required']); }
            if (empty($config['client_id']) || empty($config['client_secret'])) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'GDMS UC not configured']); }

            $mac = strtoupper(trim($form->get('mac', '')));
            if (!$mac) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'mac is required']); }
            if (!str_contains($mac, ':')) {
                $mac = implode(':', str_split($mac, 2));
            }
            if (!self::gdmsAcquireRateLimit('factory_reset', $mac, 300)) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Rate limit — wait 5 min between factory resets for the same device']); }

            \GlpiPlugin\Gdmsintegration\Utils::log("Factory reset (GDMS task) requested — MAC: {$mac}");
            $result = \GlpiPlugin\Gdmsintegration\API::gdmsCreateFactoryResetTask($config, $mac);
            if (!empty($result['error'])) {
                \GlpiPlugin\Gdmsintegration\Utils::log("Factory reset (GDMS task) FAILED — " . $result['error']);
                self::gdmsClearRateLimit('factory_reset', $mac);
            }
            return new \Symfony\Component\HttpFoundation\JsonResponse($result);
        }

        return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Unknown action']);
    }
}
