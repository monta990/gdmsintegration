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
        else                                             return ''; // Do not invent CDN paths for unknown models.
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

    private static function normalizeMacAddress(string $mac): string {
        $hex = strtoupper(preg_replace('/[^0-9A-F]/i', '', trim($mac)) ?? '');
        return strlen($hex) === 12 ? implode(':', str_split($hex, 2)) : '';
    }

    private static function isFirmwareVersion(string $version): bool {
        return preg_match('/^\d+(?:\.\d+){2,3}$/', trim($version)) === 1;
    }

    private static function isTrustedFirmwareDownloadUrl(string $url): bool {
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
        $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
        return in_array($scheme, ['http', 'https'], true)
            && in_array($host, ['firmware.grandstream.com', 'fw.gdms.cloud'], true);
    }

    /** Resolve stable firmware and its canonical download path from the vendor source. */
    private static function getOfficialStableFirmwareDetails(string $model): array {
        $slugInfo = self::gdmsFirmwareSlug($model);
        if ($slugInfo !== null && !empty($slugInfo['slug'])) {
            $versions = \GlpiPlugin\Gdmsintegration\API::scrapeFirmwareVersions($slugInfo['slug']);
            $official = trim((string)($versions['official'] ?? ''));
            $checked = !empty($versions['sourceChecked']) && self::isFirmwareVersion($official);
            $downloadUrl = trim((string)($versions['officialUrl'] ?? ''));
            if (!$checked) {
                $official = '';
            }
            if ($downloadUrl === '' || !self::isTrustedFirmwareDownloadUrl($downloadUrl)) {
                $downloadUrl = self::gdmsCdnUrl($model);
            }
            return [
                'version' => $official !== '' ? $official : null,
                'downloadUrl' => self::isTrustedFirmwareDownloadUrl($downloadUrl) ? $downloadUrl : '',
                'sourceChecked' => $checked,
            ];
        }

        $catalog = \GlpiPlugin\Gdmsintegration\API::scrapeFirmwareCatalog();
        $entry = \GlpiPlugin\Gdmsintegration\API::findFirmwareCatalogEntry($catalog, $model);
        $official = trim((string)($entry['official'] ?? ''));
        $checked = !empty($catalog['ok']) && self::isFirmwareVersion($official);
        $downloadUrl = self::gdmsCdnUrl($model);
        return [
            'version' => $checked ? $official : null,
            'downloadUrl' => self::isTrustedFirmwareDownloadUrl($downloadUrl) ? $downloadUrl : '',
            'sourceChecked' => $checked,
        ];
    }


    /** Only permit an exact official-stable target that is newer than the installed version. */
    private static function canScheduleStableFirmware(string $current, string $requested, ?string $official): bool {
        $current = trim($current);
        $requested = trim($requested);
        $official = trim((string)$official);
        if (!self::isFirmwareVersion($current)
            || !self::isFirmwareVersion($requested)
            || !self::isFirmwareVersion($official)) {
            return false;
        }
        return version_compare($requested, $official) === 0
            && version_compare($current, $official) < 0;
    }

    private static function firmwareStatusLabel(string $status): string {
        return match ($status) {
            'update_available' => __('Stable firmware update available', 'gdmsintegration'),
            'up_to_date' => __('Installed firmware matches the official stable version', 'gdmsintegration'),
            'beta_installed' => __('A beta firmware version is installed; automatic downgrade is blocked', 'gdmsintegration'),
            'ahead_of_stable' => __('Installed version is newer than the known stable version; channel not confirmed', 'gdmsintegration'),
            'check_error' => __('Firmware could not be verified because the source could not be queried', 'gdmsintegration'),
            'unsupported' => __('This model was not found in the official firmware catalog', 'gdmsintegration'),
            default => __('Firmware version could not be verified', 'gdmsintegration'),
        };
    }

    private static function resolveFirmwareStatus(
        string $current,
        ?string $official,
        ?string $beta,
        bool $sourceError,
        bool $modelFound
    ): string {
        // Missing or malformed installed versions are not proof that the device
        // needs an update; surface them for review and never authorize scheduling.
        if (!self::isFirmwareVersion($current)) {
            if ($sourceError) {
                return 'check_error';
            }
            return $modelFound ? 'unverified' : 'unsupported';
        }
        if ($official !== null && self::isFirmwareVersion($official)) {
            if (version_compare($current, $official, '<')) {
                return 'update_available';
            }
            if (version_compare($current, $official, '=')) {
                return 'up_to_date';
            }
            if ($beta !== null && $beta !== '' && version_compare($current, $beta, '=')) {
                return 'beta_installed';
            }
            return 'ahead_of_stable';
        }
        if ($beta !== null && $beta !== '' && version_compare($current, $beta, '=')) {
            return 'beta_installed';
        }
        if ($sourceError) {
            return 'check_error';
        }
        return $modelFound ? 'unverified' : 'unsupported';
    }

    private static function gdmsAcquireRateLimit(string $action, string $mac, int $entities_id, int $ttl = 60): bool {
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
                'WHERE' => ['mac' => $macl, 'entities_id' => $entities_id],
                'LIMIT' => 1,
            ]);
    
            if (count($rows) === 0) {
                try {
                    $DB->insert($table, ['mac' => $macl, 'entities_id' => $entities_id, $col => $now]);
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
                    'entities_id' => $entities_id,
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

    private static function gdmsClearRateLimit(string $action, string $mac, int $entities_id): void {
        global $DB;
        $col  = $action === 'reboot' ? 'last_reboot_at' : 'last_factory_reset_at';
        $macl = strtolower($mac);
        try {
            $DB->update('glpi_plugin_gdmsintegration_devices', [$col => null], ['mac' => $macl, 'entities_id' => $entities_id]);
        } catch (\Throwable $e) {
            // Best-effort rollback of the cooldown when the provider rejects the action.
        }
    }

    private static function deviceForEntity(string $mac, int $entities_id): ?array {
        $device_obj = new \GlpiPlugin\Gdmsintegration\Device();
        $rows = $device_obj->find(['mac' => strtolower(trim($mac))] + AccessService::entityCriteria($device_obj->getTable(), $entities_id), [], 1);
        return empty($rows) ? null : reset($rows);
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



        // ── CHECK (legacy GWN endpoint; only verified stable versions are actionable) ──
        if ($action === 'check') {
            $state_obj = new \GlpiPlugin\Gdmsintegration\Device();
            $all       = $state_obj->find(AccessService::entityCriteria($state_obj->getTable(), $entities_id));
            if (empty($all)) { return new \Symfony\Component\HttpFoundation\JsonResponse([]); }

            $mac_data = [];
            foreach ($all as $row) {
                $mac = strtolower(trim((string)($row['mac'] ?? '')));
                $macKey = strtolower(preg_replace('/[^0-9a-f]/i', '', $mac) ?? '');
                $fw = trim((string)($row['firmware'] ?? ''));
                if (strlen($macKey) !== 12 || !self::isFirmwareVersion($fw)) continue;
                $mac_data[$macKey] = $row;
            }

            // GWN official stable versions via upgrade/version. Do not use the highest
            // version installed on another peer as a proxy for the official release.
            $official_latest = [];
            $firmware_errors = [];
            if (!empty($config['gwn_client_id']) && !empty($config['gwn_client_secret'])) {
                $by_network = [];
                foreach ($mac_data as $macKey => $row) {
                    $nid = (int)($row['network_id'] ?? 0);
                    if ($nid > 0) $by_network[$nid][] = $macKey;
                }
                foreach ($by_network as $network_id => $unusedMacKeys) {
                    $versions = \GlpiPlugin\Gdmsintegration\API::gwnGetFirmwareVersions($config, (int)$network_id);
                    $firmware_errors += \GlpiPlugin\Gdmsintegration\API::gwnGetLastFirmwareErrors();
                    foreach ($versions as $v) {
                        $apiMac = strtolower(preg_replace('/[^0-9a-f]/i', '', trim((string)($v['mac'] ?? ''))) ?? '');
                        $stable = trim((string)($v['stableVersion'] ?? ''));
                        if (strlen($apiMac) === 12 && self::isFirmwareVersion($stable)) {
                            if (!isset($official_latest[$apiMac]) || version_compare($stable, $official_latest[$apiMac], '>')) {
                                $official_latest[$apiMac] = $stable;
                            }
                        }
                    }
                }
            }

            $result = [];
            foreach ($mac_data as $macKey => $row) {
                $current = trim((string)($row['firmware'] ?? ''));
                $official = $official_latest[$macKey] ?? '';
                $networkId = (int)($row['network_id'] ?? 0);
                $checkError = $firmware_errors[$networkId] ?? null;
                $hasUpdate = empty($checkError)
                    && self::isFirmwareVersion($current)
                    && self::isFirmwareVersion($official)
                    && version_compare($official, $current, '>');
                \GlpiPlugin\Gdmsintegration\Utils::debug(
                    'FW ' . (string)($row['mac'] ?? $macKey) . ': current=' . $current
                    . ' official=' . ($official !== '' ? $official : 'n/a')
                    . ' status=' . ($hasUpdate ? 'update_available' : ($checkError ? 'check_error' : 'no_verified_update'))
                );
                $result[] = [
                    'mac' => strtolower(trim((string)($row['mac'] ?? $macKey))),
                    'currentVersion' => $current,
                    'latestVersion' => $official,
                    'hasUpdate' => $hasUpdate,
                    'network_id' => $networkId,
                    'firmwareCheckError' => $checkError,
                ];
            }
            $updates = count(array_filter($result, static fn(array $r): bool => !empty($r['hasUpdate'])));
            $unverified = count(array_filter($result, static fn(array $r): bool => empty($r['latestVersion']) || !empty($r['firmwareCheckError'])));
            \GlpiPlugin\Gdmsintegration\Utils::log(
                'Legacy GWN firmware check complete: ' . count($result) . ' device(s), '
                . $updates . ' stable update(s) available, ' . $unverified . ' device(s) unverified'
            );
            return new \Symfony\Component\HttpFoundation\JsonResponse($result);
        }

        // ── CHECK_ALL — stable, beta and unverified states for all tracked models ────
        if ($action === 'check_all') {
            $state_obj = new \GlpiPlugin\Gdmsintegration\Device();
            $all       = $state_obj->find(AccessService::entityCriteria($state_obj->getTable(), $entities_id));
            if (empty($all)) { return new \Symfony\Component\HttpFoundation\JsonResponse([]); }

            // One public catalog request provides stable/beta versions for product
            // families whose version is listed in the main Grandstream catalog.
            $catalog = \GlpiPlugin\Gdmsintegration\API::scrapeFirmwareCatalog();
            if (empty($catalog['ok'])) {
                \GlpiPlugin\Gdmsintegration\Utils::debug('Firmware catalog unavailable: ' . (string)($catalog['error'] ?? 'unknown error'));
            }

            // Product pages provide more reliable channel detail for UCM families.
            $slug_cache = [];
            $gwn_official = [];
            $firmware_errors = [];
            if (!empty($config['gwn_client_id'])) {
                $networkIds = [];
                foreach ($all as $row) {
                    $nid = (int)($row['network_id'] ?? 0);
                    if ($nid > 0 && preg_match('/^GWN|^GSS/i', (string)($row['model'] ?? ''))) {
                        $networkIds[$nid] = $nid;
                    }
                }
                if ($networkIds !== []) {
                    $allVersions = \GlpiPlugin\Gdmsintegration\API::gwnGetFirmwareVersionsBatch($config, array_values($networkIds));
                    $firmware_errors = \GlpiPlugin\Gdmsintegration\API::gwnGetLastFirmwareErrors();
                    foreach ($allVersions as $versions) {
                        foreach ($versions as $v) {
                            $apiMac = strtolower(preg_replace('/[^0-9a-f]/i', '', trim((string)($v['mac'] ?? ''))) ?? '');
                            $stable = trim((string)($v['stableVersion'] ?? ''));
                            if (strlen($apiMac) === 12 && $stable !== '') {
                                $gwn_official[$apiMac] = $stable;
                            }
                        }
                    }
                }
            }

            $result = [];
            foreach ($all as $row) {
                $mac     = strtolower(trim((string)($row['mac'] ?? '')));
                $macKey  = strtolower(preg_replace('/[^0-9a-f]/i', '', $mac) ?? '');
                $current = trim((string)($row['firmware'] ?? ''));
                $model   = trim((string)($row['model'] ?? ''));
                if (strlen($macKey) !== 12 || $model === '') {
                    continue;
                }

                $isGwn = (bool)preg_match('/^GWN|^GSS/i', $model);
                $slugInfo = null;
                $official = null;
                $beta = null;
                $officialUrl = null;
                $sourceError = false;
                $modelFound = false;
                $source = 'none';

                if ($isGwn) {
                    $official = $gwn_official[$macKey] ?? null;
                    $networkId = (int)($row['network_id'] ?? 0);
                    $sourceError = isset($firmware_errors[$networkId])
                        || (empty($config['gwn_client_id']) && empty($catalog['ok']));
                    if ($official !== null) {
                        $modelFound = true;
                        $source = 'gwn_cloud';
                    } else {
                        // The official catalog is a fallback when GWN Cloud returns
                        // no per-device stable version. Keep the API error visible.
                        $entry = \GlpiPlugin\Gdmsintegration\API::findFirmwareCatalogEntry($catalog, $model);
                        if ($entry !== null) {
                            $official = $entry['official'] ?? null;
                            $beta = $entry['beta'] ?? null;
                            $modelFound = true;
                            $source = 'grandstream_catalog_fallback';
                        }
                    }
                } else {
                    $slugInfo = self::gdmsFirmwareSlug($model);
                    if ($slugInfo && !empty($slugInfo['official'])) {
                        $slug = $slugInfo['slug'];
                        if (!isset($slug_cache[$slug])) {
                            $slug_cache[$slug] = \GlpiPlugin\Gdmsintegration\API::scrapeFirmwareVersions($slug);
                            \GlpiPlugin\Gdmsintegration\Utils::debug("Scraped official/beta firmware for slug '{$slug}': " . json_encode($slug_cache[$slug]));
                        }
                        $versions = $slug_cache[$slug];
                        $official = $versions['official'] ?? null;
                        $beta = $versions['beta'] ?? null;
                        $officialUrl = $versions['officialUrl'] ?? null;
                        $modelFound = $official !== null || $beta !== null;
                        $sourceError = empty($versions['sourceChecked']);
                        if ($modelFound) {
                            $source = 'grandstream_product_page';
                        }
                    }

                    // Fallback for phones, ATAs and other devices listed on the
                    // official catalog rather than a dedicated product page.
                    if ($official === null && $beta === null) {
                        $entry = \GlpiPlugin\Gdmsintegration\API::findFirmwareCatalogEntry($catalog, $model);
                        if ($entry !== null) {
                            $official = $entry['official'] ?? null;
                            $beta = $entry['beta'] ?? null;
                            $modelFound = true;
                            $source = 'grandstream_catalog';
                            $sourceError = false;
                        } elseif (!empty($catalog['ok'])) {
                            $modelFound = false;
                            // Successful catalog fetch with no matching model is
                            // an unsupported/unlisted model, not an HTTP failure.
                            $sourceError = false;
                        } else {
                            $sourceError = true;
                        }
                    }
                }

                // A source's missing version is not proof that there is no update.
                // Only a known, higher official stable version is actionable.
                $status = self::resolveFirmwareStatus($current, $official, $beta, $sourceError, $modelFound);
                if ($isGwn && $official === null && $beta === null && $source === 'none' && !$sourceError) {
                    // The provider may omit a firmware row even though the catalog
                    // itself is reachable; this is unverified, not proof the model is unsupported.
                    $status = 'unverified';
                }
                $hasUpdate = $status === 'update_available';
                $networkId = (int)($row['network_id'] ?? 0);
                $firmwareCheckError = $firmware_errors[$networkId] ?? null;
                if ($officialUrl === null || $officialUrl === '') {
                    $scrapedUrl = is_array($slugInfo) ? ($slug_cache[$slugInfo['slug']]['officialUrl'] ?? null) : null;
                    $officialUrl = $scrapedUrl ?: ($isGwn ? null : self::gdmsCdnUrl($model));
                }

                $statusLabel = self::firmwareStatusLabel($status);
                if ($status === 'ahead_of_stable' && $beta === null) {
                    $statusLabel = __('Installed firmware is newer than the stable version found; beta channel could not be confirmed. Automatic downgrade is blocked.', 'gdmsintegration');
                }
                if ($isGwn && $source === 'grandstream_catalog_fallback') {
                    $statusLabel .= ' ' . __('GWN Cloud did not provide a verified per-device stable version; the official catalog is shown for reference and scheduling is disabled until GWN Cloud verification succeeds.', 'gdmsintegration');
                }
                if ($isGwn && $hasUpdate && (empty($config['gwn_client_id']) || empty($config['gwn_client_secret']))) {
                    $statusLabel .= ' ' . __('GWN Cloud credentials are missing; firmware scheduling is disabled.', 'gdmsintegration');
                }
                if ($hasUpdate && !$isGwn && !self::isTrustedFirmwareDownloadUrl((string)$officialUrl)) {
                    $statusLabel .= ' ' . __('An update was detected, but no verified download path is available for this model; automatic scheduling is disabled.', 'gdmsintegration');
                }

                \GlpiPlugin\Gdmsintegration\Utils::debug(
                    "FW_ALL {$mac} ({$model}): current={$current} official=" . ($official ?? 'n/a')
                    . ' beta=' . ($beta ?? 'n/a') . ' source=' . $source . ' status=' . $status
                );

                $result[] = [
                    'mac' => $mac,
                    'model' => $model,
                    'currentVersion' => $current,
                    'official' => $official,
                    'betaVersion' => $beta,
                    'officialUrl' => $officialUrl ?: null,
                    'latestVersion' => $official,
                    'hasUpdate' => $hasUpdate,
                    'firmwareStatus' => $status,
                    'firmwareStatusLabel' => $statusLabel,
                    'firmwareSource' => $source,
                    'network_id' => $networkId,
                    'firmwareCheckError' => $firmwareCheckError ?: ($sourceError ? ($catalog['error'] ?? __('Firmware source unavailable', 'gdmsintegration')) : null),
                    'isGwn' => $isGwn,
                    'canScheduleUpdate' => $hasUpdate && ($isGwn
                        ? ($source === 'gwn_cloud' && !empty($config['gwn_client_id']) && !empty($config['gwn_client_secret']) && empty($firmwareCheckError))
                        : self::isTrustedFirmwareDownloadUrl((string)$officialUrl)),
                ];
            }

            $updates = count(array_filter($result, static fn(array $r): bool => !empty($r['hasUpdate'])));
            $unverified = count(array_filter($result, static fn(array $r): bool => in_array($r['firmwareStatus'], ['unverified', 'unsupported', 'check_error', 'ahead_of_stable', 'beta_installed'], true)));
            \GlpiPlugin\Gdmsintegration\Utils::log(
                'Firmware check_all complete: ' . count($result) . ' device(s), ' . $updates
                . ' stable update(s) available, ' . $unverified . ' device(s) requiring review'
            );
            return new \Symfony\Component\HttpFoundation\JsonResponse($result);
        }

        // ── UPGRADE (existing — GWN only) ─────────────────────────────────────────────
        if ($action === 'upgrade') {
            if ($requestMethod !== 'POST') { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'POST required']); }
            if (empty($config['gwn_client_id']) || empty($config['gwn_client_secret'])) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'GWN not configured']); }

            $rawMacs = $form->get('macs', null);
            if ($rawMacs !== null) {
                $requestedMacs = json_decode($rawMacs, true) ?? [];
            } else {
                $body = json_decode($request->getContent() ?: '{}', true) ?? [];
                $requestedMacs = (array)($body['macs'] ?? []);
            }
            $macs = [];
            foreach ($requestedMacs as $requestedMac) {
                if (!is_scalar($requestedMac)) continue;
                $canonical = self::normalizeMacAddress((string)$requestedMac);
                if ($canonical === '') continue;
                $macs[$canonical] = $canonical;
            }
            $macs = array_values($macs);
            if ($macs === []) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'No valid MAC addresses provided'], 400); }

            $device_obj = new \GlpiPlugin\Gdmsintegration\Device();
            $lookupMacs = array_map('strtolower', $macs);
            $allowedRows = $device_obj->find(['mac' => $lookupMacs] + AccessService::entityCriteria($device_obj->getTable(), $entities_id));
            $allowedRowsByMac = [];
            foreach ($allowedRows as $drow) {
                $rawDbMac = self::normalizeMacAddress((string)($drow['mac'] ?? ''));
                if ($rawDbMac !== '') {
                    $allowedRowsByMac[$rawDbMac] = $drow;
                }
            }
            $macs = array_values(array_filter($macs, static fn(string $m): bool => isset($allowedRowsByMac[$m])));
            if ($macs === []) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Access denied'], 403); }

            // GWN upgrade/add does not accept an explicit version: verify each
            // device against the current official stable version before scheduling.
            $networkIds = [];
            foreach ($macs as $mac) {
                $networkId = (int)($allowedRowsByMac[$mac]['network_id'] ?? 0);
                if ($networkId > 0) $networkIds[$networkId] = $networkId;
            }
            $stableByMac = [];
            $firmwareErrors = [];
            if ($networkIds !== []) {
                $versionRowsByNetwork = \GlpiPlugin\Gdmsintegration\API::gwnGetFirmwareVersionsBatch($config, array_values($networkIds));
                $firmwareErrors = \GlpiPlugin\Gdmsintegration\API::gwnGetLastFirmwareErrors();
                foreach ($versionRowsByNetwork as $versionRows) {
                    foreach ($versionRows as $versionRow) {
                        $firmwareMac = self::normalizeMacAddress((string)($versionRow['mac'] ?? ''));
                        if ($firmwareMac === '') continue;
                        $stable = trim((string)($versionRow['stableVersion'] ?? ''));
                        if ($stable !== '') $stableByMac[$firmwareMac] = $stable;
                    }
                }
            }
            $unsafeTargets = [];
            foreach ($macs as $mac) {
                $row = $allowedRowsByMac[$mac];
                $networkId = (int)($row['network_id'] ?? 0);
                $current = trim((string)($row['firmware'] ?? ''));
                $stable = $stableByMac[$mac] ?? null;
                if (isset($firmwareErrors[$networkId]) || !self::isFirmwareVersion($current)
                    || !self::isFirmwareVersion((string)$stable) || version_compare($current, (string)$stable) >= 0) {
                    $unsafeTargets[] = [
                        'mac' => $mac,
                        'currentVersion' => $current ?: null,
                        'officialStableVersion' => $stable,
                        'reason' => isset($firmwareErrors[$networkId]) ? 'firmware version source failed'
                            : ((!self::isFirmwareVersion((string)$stable)) ? 'official stable version unverified'
                            : ((!self::isFirmwareVersion($current)) ? 'installed version unverified' : 'device is already at or newer than stable')),
                    ];
                }
            }
            if ($unsafeTargets !== []) {
                \GlpiPlugin\Gdmsintegration\Utils::warning('GWN firmware upgrade rejected; target stability could not be verified for: ' . json_encode($unsafeTargets));
                return new \Symfony\Component\HttpFoundation\JsonResponse([
                    'error' => __('GWN firmware upgrade blocked for one or more devices because the installed version is not older than a verified official stable version, or firmware verification failed.', 'gdmsintegration'),
                    'devices' => $unsafeTargets,
                ], 409);
            }

            \GlpiPlugin\Gdmsintegration\Utils::log("Firmware upgrade (GWN) requested — MACs: " . implode(', ', $macs));

            $scheduleTimeMs = 0;
            $rawTime = $form->get('scheduleTimeMs', null);
            if ($rawTime !== null) $scheduleTimeMs = max(0, (int)$rawTime);

            // Keep the compact uppercase MAC format used by the existing GWN API request,
            // while using canonical colon-separated MACs for local authorization and matching.
            $apiMacs = array_map(static fn(string $mac): string => str_replace(':', '', $mac), array_values($macs));
            $result = \GlpiPlugin\Gdmsintegration\API::gwnScheduleUpgrade($config, $apiMacs, $scheduleTimeMs);
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

            $mac = self::normalizeMacAddress((string)$mac);
            $version = trim((string)$version);

            if ($mac === '' || $version === '') {
                return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => __('MAC address and verified stable firmware version are required', 'gdmsintegration')], 400);
            }
            $device = self::deviceForEntity($mac, $entities_id);
            if ($device === null) {
                return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => __('Device not found', 'gdmsintegration')], 404);
            }
            $deviceModel = trim((string)($device['model'] ?? ''));
            $currentFirmware = trim((string)($device['firmware'] ?? ''));
            if (preg_match('/^GWN|^GSS/i', $deviceModel)) {
                return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'GWN devices must use the GWN firmware upgrade action'], 400);
            }

            // Never trust the browser's version badge as authorization to flash a
            // device. Re-read the official stable version server-side and reject
            // stale targets, unverified targets, and any update that is not newer.
            $firmwareDetails = self::getOfficialStableFirmwareDetails($deviceModel);
            $verifiedStable = $firmwareDetails['version'];
            if (!self::canScheduleStableFirmware($currentFirmware, $version, $verifiedStable)) {
                \GlpiPlugin\Gdmsintegration\Utils::warning(
                    'GDMS firmware upgrade rejected for ' . $mac . ' (' . $deviceModel . '): current='
                    . ($currentFirmware ?: 'unknown') . ' requested=' . ($version ?: 'unknown')
                    . ' verified_stable=' . ($verifiedStable ?? 'unverified')
                );
                return new \Symfony\Component\HttpFoundation\JsonResponse([
                    'error' => __('Firmware upgrade blocked: the requested version is not the currently verified official stable version, or it is not newer than the installed firmware. Refresh the firmware check and review the device before retrying.', 'gdmsintegration'),
                    'currentVersion' => $currentFirmware ?: null,
                    'requestedVersion' => $version,
                    'officialStableVersion' => $verifiedStable,
                ], 409);
            }
            if ($downloadUrl !== '' && !self::isTrustedFirmwareDownloadUrl($downloadUrl)) {
                return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'downloadUrl host not allowed'], 400);
            }

            // The browser-supplied URL is not authoritative, even when its host is trusted.
            // Only use the URL freshly obtained from the official product page or a known,
            // explicitly mapped vendor CDN path. This prevents cross-model firmware URLs.
            $downloadUrl = trim((string)($firmwareDetails['downloadUrl'] ?? ''));
            if ($downloadUrl === '' || !self::isTrustedFirmwareDownloadUrl($downloadUrl)) {
                return new \Symfony\Component\HttpFoundation\JsonResponse([
                    'error' => __('No verified official firmware download path is available for this model; automatic scheduling is disabled.', 'gdmsintegration'),
                    'officialStableVersion' => $verifiedStable,
                ], 409);
            }

            \GlpiPlugin\Gdmsintegration\Utils::log("Firmware upgrade (GDMS task) requested — MAC: {$mac} version: {$version} url: " . $downloadUrl);

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
            if (self::deviceForEntity($mac, $entities_id) === null) {
                return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => __('Device not found', 'gdmsintegration')], 404);
            }
            if (!self::gdmsAcquireRateLimit('reboot', $mac, $entities_id, 60)) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Rate limit — wait 60 s between reboots for the same device']); }

            \GlpiPlugin\Gdmsintegration\Utils::log("Reboot (GDMS task) requested — MAC: {$mac}");
            $result = \GlpiPlugin\Gdmsintegration\API::gdmsCreateRebootTask($config, $mac);
            if (!empty($result['error'])) {
                \GlpiPlugin\Gdmsintegration\Utils::log("Reboot (GDMS task) FAILED — " . $result['error']);
                self::gdmsClearRateLimit('reboot', $mac, $entities_id);
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
            if (self::deviceForEntity($mac, $entities_id) === null) {
                return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => __('Device not found', 'gdmsintegration')], 404);
            }
            if (!self::gdmsAcquireRateLimit('factory_reset', $mac, $entities_id, 300)) { return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Rate limit — wait 5 min between factory resets for the same device']); }

            \GlpiPlugin\Gdmsintegration\Utils::log("Factory reset (GDMS task) requested — MAC: {$mac}");
            $result = \GlpiPlugin\Gdmsintegration\API::gdmsCreateFactoryResetTask($config, $mac);
            if (!empty($result['error'])) {
                \GlpiPlugin\Gdmsintegration\Utils::log("Factory reset (GDMS task) FAILED — " . $result['error']);
                self::gdmsClearRateLimit('factory_reset', $mac, $entities_id);
            }
            return new \Symfony\Component\HttpFoundation\JsonResponse($result);
        }

        return new \Symfony\Component\HttpFoundation\JsonResponse(['error' => 'Unknown action']);
    }
}
