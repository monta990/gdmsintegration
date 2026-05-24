<?php
/**
 * GDMS Integration — Firmware check & schedule upgrade AJAX endpoint.
 *
 * GET  ?action=check
 *      GWN devices: calls GWN Cloud upgrade/version per network (official stable only).
 *      All devices: returns {mac, type, currentVersion, latestVersion, hasUpdate}
 *
 * GET  ?action=check_all
 *      Calls grandstream.com/support/firmware scraper for every device family.
 *      Returns {mac, model, currentVersion, official, officialUrl, hasUpdate} for all devices.
 *      Used by the dashboard firmware modal to show available official updates.
 *
 * POST ?action=upgrade
 *      GWN devices only. Body: {macs: [...], scheduleTimeMs?}
 *      Schedules firmware upgrade via GWN Cloud upgrade/add.
 *
 * POST ?action=upgrade_gdms
 *      UC devices (UCM/GCC/GRP/GXP/WP/HT etc.) Body: {mac, version, scheduleMs?}
 *      Creates GDMS task/add with taskName=UPGRADE.
 */

$_action_early = $_GET['action'] ?? 'check';
if ($_action_early === 'factory_reset_gdms') {
    Session::checkRight('config', PURGE);   // factory reset requires higher right than UPDATE
} elseif (in_array($_action_early, ['upgrade', 'upgrade_gdms', 'reboot_gdms'], true)) {
    Session::checkRight('config', UPDATE);
} else {
    Session::checkRight('config', READ);
}
header('Content-Type: application/json');

$entities_id = (int) ($_GET['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
$action      = $_GET['action'] ?? 'check';

$config_obj = new PluginGdmsintegrationConfig();
$config     = $config_obj->getConfigByEntity($entities_id);

// ── CDN URL HELPER ───────────────────────────────────────────────────────────
// Returns https://firmware.grandstream.com/{base}fw.bin for any UC/phone model.
// GWN/GSS devices return ''.
function gdmsCdnUrl(string $model): string {
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

// ── MODEL → FIRMWARE PAGE SLUG MAP ───────────────────────────────────────────
// Maps model prefix (uppercase) to grandstream.com official firmware page slug.
// Only models with a grandstream.com official firmware page are listed.
// GWN devices use the GWN Cloud API instead.

function gdmsFirmwareSlug(string $model): ?array {
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

// ── CHECK (original — GWN stable only) ───────────────────────────────────────
if ($action === 'check') {
    $state_obj = new PluginGdmsintegrationDevice();
    $all       = $state_obj->find();

    if (empty($all)) { echo json_encode([]); return; }

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
    if (!empty($config['gwn_client_id'])) {
        $by_network = [];
        foreach ($mac_data as $mac => $row) {
            $nid = (int)($row['network_id'] ?? 0);
            if ($nid) $by_network[$nid][] = $mac;
        }
        foreach ($by_network as $network_id => $macs) {
            $versions = PluginGdmsintegrationAPI::gwnGetFirmwareVersions($config, $network_id);
            foreach ($versions as $v) {
                $apiMac = strtolower(str_replace(['-',' '], ':', trim($v['mac'] ?? '')));
                $latest = trim($v['lastVersion'] ?? '');
                if ($apiMac && !empty($latest) && !preg_match('/beta|rc|dev|alpha/i', $latest)) {
                    $official_latest[$apiMac] = $latest;
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
        PluginGdmsintegrationUtils::debug("FW {$mac}: current={$current} peerMax={$peerMax} official={$official} hasUpdate=" . ($hasUpdate?'YES':'no'));
        $result[] = [
            'mac'            => $mac,
            'currentVersion' => $current,
            'latestVersion'  => $latest ?: $peerMax ?: $official,
            'hasUpdate'      => $hasUpdate,
            'network_id'     => (int)($row['network_id'] ?? 0),
        ];
    }
    $updates = count(array_filter($result, fn($r) => $r['hasUpdate']));
    PluginGdmsintegrationUtils::log("Firmware check complete: " . count($result) . " device(s) checked, {$updates} update(s) available");
    echo json_encode($result);
    return;
}

// ── CHECK_ALL (new — all devices, official + beta) ────────────────────────────
if ($action === 'check_all') {
    $state_obj = new PluginGdmsintegrationDevice();
    $all       = $state_obj->find();
    if (empty($all)) { echo json_encode([]); return; }

    // Cache slug → versions to avoid duplicate HTTP requests for same model family
    $slug_cache = []; // slug → ['official' => '...', 'officialUrl' => '...']

    // GWN official versions from GWN Cloud API (same as check action)
    $gwn_official = [];
    if (!empty($config['gwn_client_id'])) {
        $by_network = [];
        foreach ($all as $row) {
            $mac = strtolower(trim($row['mac'] ?? ''));
            $nid = (int)($row['network_id'] ?? 0);
            if ($mac && $nid) $by_network[$nid][] = $mac;
        }
        $allVersions = PluginGdmsintegrationAPI::gwnGetFirmwareVersionsBatch($config, array_keys($by_network));
        foreach ($allVersions as $versions) {
            foreach ($versions as $v) {
                $apiMac = strtolower(str_replace(['-',' '], ':', trim($v['mac'] ?? '')));
                if ($apiMac) {
                    $gwn_official[$apiMac] = trim($v['lastVersion'] ?? '') ?: null;
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
            $slugInfo = gdmsFirmwareSlug($model);
            if ($slugInfo && !empty($slugInfo['official'])) {
                $slug = $slugInfo['slug'];
                if (!isset($slug_cache[$slug])) {
                    $slug_cache[$slug] = PluginGdmsintegrationAPI::scrapeFirmwareVersions($slug);
                    PluginGdmsintegrationUtils::debug("Scraped firmware for slug '{$slug}': " . json_encode($slug_cache[$slug]));
                }
                $versions = $slug_cache[$slug];
                $official = $versions['official'] ?? null;
            }
        }

        // Determine if an update is available
        $hasUpdate = $official !== null && version_compare($official, $current, '>');

        PluginGdmsintegrationUtils::debug("FW_ALL {$mac} ({$model}): current={$current} official=" . ($official ?? 'n/a') . " beta=" . ($beta ?? 'n/a') . " hasUpdate=" . ($hasUpdate?'YES':'no'));

        // officialUrl: scraped URL for UCM, else CDN URL for all UC/phone models
        $scrapedUrl = isset($slugInfo['slug']) ? ($slug_cache[$slugInfo['slug']]['officialUrl'] ?? null) : null;
        $officialUrl = $scrapedUrl ?: (preg_match('/^GWN|^GSS/i', $model) ? null : gdmsCdnUrl($model)) ?: null;

        $result[] = [
            'mac'            => $mac,
            'model'          => $model,
            'currentVersion' => $current,
            'official'       => $official,
            'officialUrl'    => $officialUrl,
            'hasUpdate'      => $hasUpdate,
            'network_id'     => (int)($row['network_id'] ?? 0),
            'isGwn'          => (bool)preg_match('/^GWN|^GSS/i', $model),
        ];
    }

    $updates = count(array_filter($result, fn($r) => $r['hasUpdate']));
    PluginGdmsintegrationUtils::log("Firmware check_all complete: " . count($result) . " device(s), {$updates} update(s) available");
    echo json_encode($result);
    return;
}

// ── UPGRADE (existing — GWN only) ─────────────────────────────────────────────
if ($action === 'upgrade') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST required']); return; }
    if (empty($config['gwn_client_id'])) { echo json_encode(['error' => 'GWN not configured']); return; }

    $rawMacs = $_POST['macs'] ?? null;
    if ($rawMacs !== null) {
        $macs = array_filter(array_map('strtoupper', json_decode($rawMacs, true) ?? []));
    } else {
        $body = json_decode(stream_get_contents(fopen('php://input', 'r'), 65536) ?: '{}', true) ?? [];
        $macs = array_filter(array_map('strtoupper', (array)($body['macs'] ?? [])));
    }
    if (empty($macs)) { echo json_encode(['error' => 'No MACs provided']); return; }

    PluginGdmsintegrationUtils::log("Firmware upgrade (GWN) requested — MACs: " . implode(', ', $macs));

    $scheduleTimeMs = 0;
    $rawTime = $_POST['scheduleTimeMs'] ?? null;
    if ($rawTime !== null) $scheduleTimeMs = max(0, (int)$rawTime);

    $result = PluginGdmsintegrationAPI::gwnScheduleUpgrade($config, array_values($macs), $scheduleTimeMs);
    if (!empty($result['error'])) {
        PluginGdmsintegrationUtils::log("Firmware upgrade (GWN) FAILED — " . $result['error']);
    } else {
        $ok = implode(', ', (array)($result['success'] ?? []));
        PluginGdmsintegrationUtils::log("Firmware upgrade (GWN) scheduled OK — MACs: " . ($ok ?: 'none'));
    }
    echo json_encode($result);
    return;
}

// ── UPGRADE_GDMS (new — UC devices via task/add) ──────────────────────────────
if ($action === 'upgrade_gdms') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST required']); return; }
    if (empty($config['client_id']) || empty($config['client_secret'])) { echo json_encode(['error' => 'GDMS UC not configured']); return; }

    $rawBody = stream_get_contents(fopen('php://input', 'r'), 65536) ?: '{}';
    $body    = json_decode($rawBody, true) ?? [];

    // Also support FormData
    $mac         = $_POST['mac']         ?? $body['mac']         ?? '';
    $version     = $_POST['version']     ?? $body['version']     ?? '';
    $downloadUrl = trim($_POST['downloadUrl'] ?? $body['downloadUrl'] ?? '');
    $scheduleMs  = (int)($_POST['scheduleMs'] ?? $body['scheduleMs'] ?? 0);

    $mac     = strtoupper(trim($mac));
    $version = trim($version);

    if (!$mac || (!$version && !$downloadUrl)) {
        echo json_encode(['error' => 'mac and version (or downloadUrl) required']);
        return;
    }
    if ($downloadUrl !== '') {
        $dlScheme = strtolower(parse_url($downloadUrl, PHP_URL_SCHEME) ?? '');
        $dlHost   = strtolower(parse_url($downloadUrl, PHP_URL_HOST) ?? '');
        if (!in_array($dlScheme, ['http', 'https'], true) ||
            !in_array($dlHost, ['firmware.grandstream.com', 'fw.gdms.cloud'], true)) {
            echo json_encode(['error' => 'downloadUrl host not allowed']);
            return;
        }
    }

    // MAC must be in colon format for task/add
    // Accept both "C074ADDEE28E" and "C0:74:AD:DE:E2:8E"
    if (!str_contains($mac, ':')) {
        $mac = implode(':', str_split($mac, 2));
    }

    // Construct CDN URL from model when not supplied by caller
    if ($downloadUrl === '') {
        $state_obj = new PluginGdmsintegrationDevice();
        $rows   = $state_obj->find(['mac' => strtolower($mac)]);
        $devRow = !empty($rows) ? reset($rows) : null;
        if ($devRow && !empty($devRow['model'])) {
            $downloadUrl = gdmsCdnUrl($devRow['model']);
        }
    }

    PluginGdmsintegrationUtils::log("Firmware upgrade (GDMS task) requested — MAC: {$mac} version: {$version} url: " . ($downloadUrl ?: '(none)'));

    $result = PluginGdmsintegrationAPI::gdmsCreateUpgradeTask($config, $mac, $version, $scheduleMs, $downloadUrl);
    if (!empty($result['error'])) {
        PluginGdmsintegrationUtils::log("Firmware upgrade (GDMS task) FAILED — " . $result['error']);
    }
    echo json_encode($result);
    return;
}

// ── DESTRUCTIVE ACTION RATE LIMIT — DB-backed, survives session reset ─────────
// Check+commit are wrapped in an advisory lock by the caller to prevent TOCTOU
// races from concurrent POSTs for the same MAC.

function gdmsRateLimitLock(string $action, string $mac): bool {
    global $DB;
    $name = 'gdms_rl_' . $action . '_' . strtolower($mac);
    $res  = $DB->doQuery("SELECT GET_LOCK('" . $DB->escape($name) . "', 3) AS lk");
    $row  = $res ? $res->fetch_assoc() : null;
    return ($row['lk'] ?? 0) == 1;
}

function gdmsRateLimitUnlock(string $action, string $mac): void {
    global $DB;
    $name = 'gdms_rl_' . $action . '_' . strtolower($mac);
    $DB->doQuery("SELECT RELEASE_LOCK('" . $DB->escape($name) . "')");
}

function gdmsCheckRateLimit(string $action, string $mac, int $ttl = 60): bool {
    global $DB;
    $col  = $action === 'reboot' ? 'last_reboot_at' : 'last_factory_reset_at';
    $macl = strtolower($mac);
    try {
        $rows = $DB->request(['SELECT' => [$col], 'FROM' => 'glpi_plugin_gdmsintegration_devices',
                              'WHERE'  => ['mac' => $macl], 'LIMIT' => 1]);
    } catch (\Throwable $e) {
        // Column absent on unmigrated schema — allow the action, do not block.
        return true;
    }
    if (count($rows) === 0) return true;
    $last = strtotime($rows->current()[$col] ?? '') ?: 0;
    return time() - $last >= $ttl;
}

function gdmsCommitRateLimit(string $action, string $mac): void {
    global $DB;
    $col  = $action === 'reboot' ? 'last_reboot_at' : 'last_factory_reset_at';
    $macl = strtolower($mac);
    try {
        $rows = $DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_plugin_gdmsintegration_devices',
                              'WHERE'  => ['mac' => $macl], 'LIMIT' => 1]);
        $now  = date('Y-m-d H:i:s');
        if (count($rows) === 0) {
            $DB->insert('glpi_plugin_gdmsintegration_devices', ['mac' => $macl, $col => $now]);
        } else {
            $DB->update('glpi_plugin_gdmsintegration_devices', [$col => $now], ['mac' => $macl]);
        }
    } catch (\Throwable $e) {
        // Column absent on unmigrated schema — skip write, action already succeeded.
    }
}

// ── REBOOT_GDMS — UC devices via task/add taskType=1 ──────────────────────────
if ($action === 'reboot_gdms') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST required']); return; }
    if (empty($config['client_id']) || empty($config['client_secret'])) { echo json_encode(['error' => 'GDMS UC not configured']); return; }

    $mac = strtoupper(trim($_POST['mac'] ?? ''));
    if (!$mac) { echo json_encode(['error' => 'mac is required']); return; }
    if (!str_contains($mac, ':')) {
        $mac = implode(':', str_split($mac, 2));
    }
    if (!gdmsRateLimitLock('reboot', $mac)) { echo json_encode(['error' => 'Rate limit — wait 60 s between reboots for the same device']); return; }
    if (!gdmsCheckRateLimit('reboot', $mac)) { gdmsRateLimitUnlock('reboot', $mac); echo json_encode(['error' => 'Rate limit — wait 60 s between reboots for the same device']); return; }
    gdmsCommitRateLimit('reboot', $mac);
    gdmsRateLimitUnlock('reboot', $mac);

    PluginGdmsintegrationUtils::log("Reboot (GDMS task) requested — MAC: {$mac}");
    $result = PluginGdmsintegrationAPI::gdmsCreateRebootTask($config, $mac);
    if (!empty($result['error'])) {
        PluginGdmsintegrationUtils::log("Reboot (GDMS task) FAILED — " . $result['error']);
    }
    echo json_encode($result);
    return;
}

// ── FACTORY_RESET_GDMS — UC devices via task/add taskType=2 ───────────────────
if ($action === 'factory_reset_gdms') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST required']); return; }
    if (empty($config['client_id']) || empty($config['client_secret'])) { echo json_encode(['error' => 'GDMS UC not configured']); return; }

    $mac = strtoupper(trim($_POST['mac'] ?? ''));
    if (!$mac) { echo json_encode(['error' => 'mac is required']); return; }
    if (!str_contains($mac, ':')) {
        $mac = implode(':', str_split($mac, 2));
    }
    if (!gdmsRateLimitLock('factory_reset', $mac)) { echo json_encode(['error' => 'Rate limit — wait 5 min between factory resets for the same device']); return; }
    if (!gdmsCheckRateLimit('factory_reset', $mac, 300)) { gdmsRateLimitUnlock('factory_reset', $mac); echo json_encode(['error' => 'Rate limit — wait 5 min between factory resets for the same device']); return; }
    gdmsCommitRateLimit('factory_reset', $mac);
    gdmsRateLimitUnlock('factory_reset', $mac);

    PluginGdmsintegrationUtils::log("Factory reset (GDMS task) requested — MAC: {$mac}");
    $result = PluginGdmsintegrationAPI::gdmsCreateFactoryResetTask($config, $mac);
    if (!empty($result['error'])) {
        PluginGdmsintegrationUtils::log("Factory reset (GDMS task) FAILED — " . $result['error']);
    }
    echo json_encode($result);
    return;
}

echo json_encode(['error' => 'Unknown action']);
