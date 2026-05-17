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
 *      Returns {mac, type, currentVersion, official, beta, hasUpdate} for all devices.
 *      Used by the dashboard firmware modal to show official + beta options.
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
if (in_array($_action_early, ['upgrade', 'upgrade_gdms', 'reboot_gdms'], true)) {
    Session::checkRight('config', UPDATE);
} else {
    Session::checkRight('config', READ);
}
header('Content-Type: application/json');

$entities_id = (int) ($_GET['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
$action      = $_GET['action'] ?? 'check';

$config_obj = new PluginGdmsintegrationConfig();
$config     = $config_obj->getConfigByEntity($entities_id);

// ── MODEL → FIRMWARE PAGE SLUG MAP ───────────────────────────────────────────
// Maps model prefix (uppercase) to grandstream.com firmware page slug.
// A model matches if its uppercase name starts with any key in this map.
// Keys are ordered longest-first so more specific prefixes match first.
//
// Rules discovered from grandstream.com/support/firmware:
//   • UCM630x (x=0/1/2/4/8, A suffix) → ucm6300  (official + beta pages exist)
//   • UCM62xx → ucm62xx                            (official page only)
//   • UCM61xx → ucm61xx                            (official page only)
//   • UCM6510 → ucm6510                            (official page only)
//   • GCC601x → gcc601x                            (beta page only)
//   • GCC602x → gcc602x                            (beta page only)
//   • GRP260x (2601/2602/2603/2604) → grp260x      (beta page only = latest)
//   • GXV34xx → gxv34xx                            (official + beta pages exist)
//   • WP8x6 (816/826/836/856) → wp8x6              (beta page only = latest)
//   • HT8xxV2 (812/813/814/818) → ht8xxv2          (beta page only = latest)
//   • GWN700x (routers) — firmware from GWN Cloud API, not grandstream.com
//   • GWN78xx/GSS (switches) — firmware from GWN Cloud API
//   • Other GWN (APs) — firmware from GWN Cloud API
//
// For entries with only a beta page, 'official' field will be null and 'beta'
// will carry the latest available version (which is the authoritative version
// for that model family).

function gdmsFirmwareSlug(string $model): ?array {
    // Returns ['slug' => '...', 'hasOfficialPage' => bool] or null if not supported
    $m = strtoupper($model);

    // GWN devices use GWN Cloud API — not scraped from grandstream.com
    if (preg_match('/^GWN|^GSS/', $m)) return null;

    $map = [
        // PBX
        'UCM6300' => ['slug' => 'ucm6300',  'official' => true,  'beta' => true],
        'UCM6301' => ['slug' => 'ucm6300',  'official' => true,  'beta' => true],
        'UCM6302' => ['slug' => 'ucm6300',  'official' => true,  'beta' => true],
        'UCM6304' => ['slug' => 'ucm6300',  'official' => true,  'beta' => true],
        'UCM6308' => ['slug' => 'ucm6300',  'official' => true,  'beta' => true],
        'UCM62'   => ['slug' => 'ucm62xx',  'official' => true,  'beta' => false],
        'UCM61'   => ['slug' => 'ucm61xx',  'official' => true,  'beta' => false],
        'UCM6510' => ['slug' => 'ucm6510',  'official' => true,  'beta' => false],
        'GCC602'  => ['slug' => 'gcc602x',  'official' => false, 'beta' => true],
        'GCC601'  => ['slug' => 'gcc601x',  'official' => false, 'beta' => true],
        // IP Phones
        'GRP260'  => ['slug' => 'grp260x',  'official' => true,  'beta' => true],
        'GXV34'   => ['slug' => 'gxv34xx',  'official' => true,  'beta' => true],
        'WP8'     => ['slug' => 'wp8x6',    'official' => false, 'beta' => true],
        'HT8'     => ['slug' => 'ht8xxv2',  'official' => false, 'beta' => true],
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
    $slug_cache = []; // slug → ['official' => '...', 'beta' => '...']

    // GWN official versions from GWN Cloud API (same as check action)
    $gwn_official = [];
    if (!empty($config['gwn_client_id'])) {
        $by_network = [];
        foreach ($all as $row) {
            $mac = strtolower(trim($row['mac'] ?? ''));
            $nid = (int)($row['network_id'] ?? 0);
            if ($mac && $nid) $by_network[$nid][] = $mac;
        }
        foreach ($by_network as $network_id => $macs) {
            $versions = PluginGdmsintegrationAPI::gwnGetFirmwareVersions($config, $network_id);
            foreach ($versions as $v) {
                $apiMac = strtolower(str_replace(['-',' '], ':', trim($v['mac'] ?? '')));
                if ($apiMac) {
                    $gwn_official[$apiMac] = [
                        'official' => trim($v['lastVersion'] ?? '') ?: null,
                        'beta'     => null, // GWN Cloud API only returns official
                    ];
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

        // GWN devices: use GWN Cloud API result
        if (preg_match('/^GWN|^GSS/i', $model)) {
            $gwn = $gwn_official[$mac] ?? null;
            $official = $gwn['official'] ?? null;
            $beta     = null; // GWN Cloud API doesn't expose beta firmware
        } else {
            // UC/phone devices: scrape grandstream.com
            $slugInfo = gdmsFirmwareSlug($model);
            if ($slugInfo) {
                $slug = $slugInfo['slug'];
                if (!isset($slug_cache[$slug])) {
                    $slug_cache[$slug] = PluginGdmsintegrationAPI::scrapeFirmwareVersions($slug);
                    PluginGdmsintegrationUtils::debug("Scraped firmware for slug '{$slug}': " . json_encode($slug_cache[$slug]));
                }
                $versions = $slug_cache[$slug];
                $official = $slugInfo['official'] ? ($versions['official'] ?? null) : null;
                $beta     = null; // beta upgrades not supported
            }
        }

        // Determine if an update is available
        $hasUpdate = $official !== null && version_compare($official, $current, '>');

        PluginGdmsintegrationUtils::debug("FW_ALL {$mac} ({$model}): current={$current} official=" . ($official ?? 'n/a') . " beta=" . ($beta ?? 'n/a') . " hasUpdate=" . ($hasUpdate?'YES':'no'));

        $result[] = [
            'mac'            => $mac,
            'model'          => $model,
            'currentVersion' => $current,
            'official'       => $official,
            'officialUrl'    => isset($slugInfo['slug']) ? ($slug_cache[$slugInfo['slug']]['officialUrl'] ?? null) : null,
            'beta'           => $beta,
            'betaUrl'        => isset($slugInfo['slug']) ? ($slug_cache[$slugInfo['slug']]['betaUrl']     ?? null) : null,
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
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
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

    $rawBody = file_get_contents('php://input');
    $body    = json_decode($rawBody ?: '{}', true) ?? [];

    // Also support FormData
    $mac         = $_POST['mac']         ?? $body['mac']         ?? '';
    $version     = $_POST['version']     ?? $body['version']     ?? '';
    $downloadUrl = trim($_POST['downloadUrl'] ?? $body['downloadUrl'] ?? '');
    $scheduleMs  = (int)($_POST['scheduleMs'] ?? $body['scheduleMs'] ?? 0);

    $mac     = strtoupper(trim($mac));
    $version = trim($version);

    if (!$mac || !$version) {
        echo json_encode(['error' => 'mac and version are required']);
        return;
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
            $m    = strtoupper(preg_replace('/[\s\-_]+/', '', trim($devRow['model'])));
            $isV2 = str_contains($m, 'V2');
            if     (str_starts_with($m, 'GRP260'))           $cdnBase = 'grp2600';
            elseif (str_starts_with($m, 'GRP26'))            $cdnBase = 'grp2610';
            elseif ($m === 'HT841' || $m === 'HT881')        $cdnBase = 'ht8x1';
            elseif ($isV2 && preg_match('/^HT80[12]/', $m))  $cdnBase = 'ht80xv2';
            elseif ($isV2 && preg_match('/^HT81[248]/', $m)) $cdnBase = 'ht81xv2';
            elseif ($m === 'HT801')                          $cdnBase = 'ht801';
            elseif ($m === 'HT802')                          $cdnBase = 'ht802';
            elseif (preg_match('/^HT81[24]/', $m))           $cdnBase = 'ht81x';
            elseif ($m === 'HT818')                          $cdnBase = 'ht818';
            elseif ($m === 'HT813')                          $cdnBase = 'ht813';
            elseif ($m === 'GAC2570')                        $cdnBase = 'gac2570';
            elseif ($m === 'GAC2500')                        $cdnBase = 'gac2500';
            elseif ($m === 'GBX20')                          $cdnBase = 'gbx20';
            elseif ($m === 'GSC3574' || $m === 'GSC3575')    $cdnBase = 'gsc3575';
            elseif ($m === 'GSC3570')                        $cdnBase = 'gsc3570';
            elseif (str_starts_with($m, 'GSC3518'))          $cdnBase = 'gsc3518';
            elseif ($m === 'GSC3505')                        $cdnBase = 'gsc3505';
            elseif ($m === 'GSC3510')                        $cdnBase = 'gsc3510';
            elseif ($m === 'GSC3506' || $m === 'GSC3516')    $cdnBase = 'gsc35x6';
            elseif ($m === 'GDS3702')                        $cdnBase = 'gds3702';
            elseif ($m === 'GDS3705')                        $cdnBase = 'gds3705';
            elseif ($m === 'GDS3710' || $m === 'GDS3712')    $cdnBase = 'gds37xx_';
            elseif (preg_match('/^GDS372[567]/', $m))        $cdnBase = 'gds372x';
            else                                             $cdnBase = strtolower($m);
            $downloadUrl = 'https://firmware.grandstream.com/' . $cdnBase . 'fw.bin';
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

// ── REBOOT_GDMS — UC devices via task/add taskType=1 ──────────────────────────
if ($action === 'reboot_gdms') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['error' => 'POST required']); return; }
    if (empty($config['client_id']) || empty($config['client_secret'])) { echo json_encode(['error' => 'GDMS UC not configured']); return; }

    $mac = strtoupper(trim($_POST['mac'] ?? ''));
    if (!$mac) { echo json_encode(['error' => 'mac is required']); return; }
    if (!str_contains($mac, ':')) {
        $mac = implode(':', str_split($mac, 2));
    }

    PluginGdmsintegrationUtils::log("Reboot (GDMS task) requested — MAC: {$mac}");
    $result = PluginGdmsintegrationAPI::gdmsCreateRebootTask($config, $mac);
    if (!empty($result['error'])) {
        PluginGdmsintegrationUtils::log("Reboot (GDMS task) FAILED — " . $result['error']);
    }
    echo json_encode($result);
    return;
}

echo json_encode(['error' => 'Unknown action']);
