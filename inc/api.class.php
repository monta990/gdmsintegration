<?php
/**
 * GDMS Integration — PluginGdmsintegrationAPI
 *
 * Two separate Grandstream cloud APIs:
 *
 * GDMS API  (gdms.cloud)  — Phones GRP/GXP/GXV/WP, UCM/GCC PBX
 *   Auth: username + SHA256(MD5(pw)) + client_id + secret (form POST)
 *   Signature for every request (non-form / application/json):
 *     1. URL query params + access_token + timestamp + client_id + client_secret
 *        sorted ascending, joined as k=v&k=v
 *     2. If POST body present: also include sha256(body) in the string
 *     3. final sig = sha256( & <sorted_params> & sha256(body) & )
 *        If no body:        sha256( & <sorted_params> & )
 *
 * GWN API   (gdms.cloud)  — GWN APs, Switches, Routers (Networking)
 *   NOTE: gwn.cloud is now redirected to gdms.cloud GDMS Networking section
 *   Auth: client_credentials grant — only client_id + secret (no username needed)
 *   Base URL: https://www.gwn.cloud (legacy, still works for API calls)
 */
class PluginGdmsintegrationAPI {

    private const GDMS_BASE = 'https://www.gdms.cloud/oapi';
    private const GWN_BASE  = 'https://www.gwn.cloud';
    private const VERSION   = 'v1.0.0';
    private const TIMEOUT   = 25;
    private const MAX_PAGES = 50;

    // -----------------------------------------------------------------------
    // Device classification
    // -----------------------------------------------------------------------
    private const PHONE_PREFIXES = ['GRP','GXP','GXV','GXW','WP','HT','DP','GHP','GVC','GSC','GDS','GAC'];
    private const PBX_PREFIXES   = ['UCM','GCC','CLOUDUCM','SOFTWAREUCM'];

    public static function classifyModel(string $model): ?string {
        $upper = strtoupper(trim($model));
        foreach (self::PHONE_PREFIXES as $p) {
            if (str_starts_with($upper, $p)) return 'Phone';
        }
        foreach (self::PBX_PREFIXES as $p) {
            if (str_starts_with($upper, $p)) return 'NetworkEquipment';
        }
        return null;
    }

    /**
     * Returns device category string for ticket-type gating:
     * 'phone' | 'router' | 'switch' | 'ap' | 'pbx' | 'unknown'
     */
    public static function getDeviceCategory(string $model): string {
        $upper = strtoupper(trim($model));
        foreach (self::PHONE_PREFIXES as $p) {
            if (str_starts_with($upper, $p)) return 'phone';
        }
        foreach (self::PBX_PREFIXES as $p) {
            if (str_starts_with($upper, $p)) return 'pbx';
        }
        if (preg_match('/^GWN700[123]/i', $upper)) return 'router';
        if (preg_match('/^GWN78|^GSS/i', $upper))  return 'switch';
        if (str_starts_with($upper, 'GWN'))          return 'ap';
        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // HTTP helper — returns decoded array or false
    // -----------------------------------------------------------------------
    private static function curl(
        string $url,
        array  $headers = [],
        ?string $body   = null   // raw JSON string for POST
    ): array|false {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POST,       true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $code >= 400) {
            PluginGdmsintegrationUtils::log("API ERROR — {$url} | cURL:{$err} | HTTP:{$code} | body:" . substr((string)$raw, 0, 300));
            return false;
        }
        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            PluginGdmsintegrationUtils::debug("API — non-JSON response from {$url} | HTTP:{$code} | raw:" . substr((string)$raw, 0, 300));
            return false;
        }
        return $decoded;
    }

    // -----------------------------------------------------------------------
    // GDMS signature calculation
    // For POST+JSON body: sig = sha256( & sorted_params & sha256(body) & )
    // For GET / no body:  sig = sha256( & sorted_params & )
    // -----------------------------------------------------------------------
    private static function gdmsBuildSignature(
        array   $urlParams,   // params that go in the URL query string
        array   $config,
        int     $timestamp,
        ?string $body = null  // raw JSON body string, or null
    ): string {
        // Combine URL params + meta params (for signing only)
        $all = array_merge($urlParams, [
            'access_token'  => $config['access_token'],
            'timestamp'     => (string) $timestamp,
            'client_id'     => $config['client_id'],
            'client_secret' => $config['client_secret'],
        ]);
        ksort($all); // ascending, case-sensitive

        $parts = [];
        foreach ($all as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        $sortedStr = implode('&', $parts);

        if ($body !== null && $body !== '') {
            $bodyHash = hash('sha256', $body);
            $toSign   = '&' . $sortedStr . '&' . $bodyHash . '&';
        } else {
            $toSign   = '&' . $sortedStr . '&';
        }

        $sig = hash('sha256', $toSign);
        return $sig;
    }

    // -----------------------------------------------------------------------
    // GDMS — get token (with in-process cache, same pattern as GWN)
    // -----------------------------------------------------------------------
    /** In-process token cache: [cacheKey => [tokenData, expires_at]] */
    private static array $gdmsTokenCache = [];

    public static function gdmsGetToken(array $config): array|false {
        $cacheKey = ($config['username'] ?? '') . '|' . ($config['client_id'] ?? '');
        $now      = time();
        if (isset(self::$gdmsTokenCache[$cacheKey])) {
            [$cachedData, $expiresAt] = self::$gdmsTokenCache[$cacheKey];
            if ($now < $expiresAt) {
                PluginGdmsintegrationUtils::debug("GDMS token OK (cached) — username:{$config['username']}");
                return $cachedData;
            }
        }
        $hashedPw = hash('sha256', md5($config['password']));
        $data = self::curl(
            self::GDMS_BASE . '/oauth/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'username'      => $config['username'],
                'password'      => $hashedPw,
                'grant_type'    => 'password',
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ])
        );
        if ($data === false || empty($data['access_token'])) {
            PluginGdmsintegrationUtils::log("GDMS token ERROR — response: " . json_encode($data));
            return false;
        }
        $expiresIn = (int)($data['expires_in'] ?? 3600);
        $tokenData = [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in'    => $expiresIn,
        ];
        self::$gdmsTokenCache[$cacheKey] = [$tokenData, $now + $expiresIn - 30];
        PluginGdmsintegrationUtils::log("GDMS token OK — username:{$config['username']} | API ID:{$config['client_id']} | expires:{$expiresIn}s");
        return $tokenData;
    }

    // -----------------------------------------------------------------------
    // GDMS — device list (POST + JSON body + signature)
    // URL query: access_token, timestamp, signature
    // Body: pageNum, pageSize, orgId (optional)
    // -----------------------------------------------------------------------
    public static function gdmsGetDevices(array $config): array {
        $page = 1;
        $all  = [];
        do {
            $ts   = (int) (microtime(true) * 1000);
            $body = json_encode(['pageNum' => $page, 'pageSize' => 100]);

            // URL query params (only access_token goes in URL for this endpoint)
            $urlParams = ['access_token' => $config['access_token']];
            $sig = self::gdmsBuildSignature($urlParams, $config, $ts, $body);

            $url = self::GDMS_BASE . '/' . self::VERSION . '/device/list?'
                 . http_build_query([
                     'access_token' => $config['access_token'],
                     'timestamp'    => $ts,
                     'signature'    => $sig,
                 ]);

            $data = self::curl(
                $url,
                ['Content-Type: application/json'],
                $body
            );

            if ($data === false) {
                break;
            }
            if ((int)($data['retCode'] ?? -1) !== 0) {
                PluginGdmsintegrationUtils::log("GDMS device/list error: " . ($data['msg'] ?? json_encode($data)));
                break;
            }
            $batch = $data['data']['result'] ?? [];
            $batch = is_array($batch) ? $batch : [];
            PluginGdmsintegrationUtils::log("GDMS page {$page}: " . count($batch) . " device(s)");
            if ($page === 1 && !empty($batch)) {
                PluginGdmsintegrationUtils::debug("GDMS device/list fields (first device): " . json_encode(array_keys($batch[0])));
                PluginGdmsintegrationUtils::debug("GDMS device/list sample: " . json_encode($batch[0]));
            }
            $all  = array_merge($all, $batch);
            $page++;
        } while (!empty($batch) && $page <= self::MAX_PAGES);
        if (!empty($batch)) {
            PluginGdmsintegrationUtils::log(
                'GDMS WARNING: device list truncated at page ' . self::MAX_PAGES
                . ' (~' . (self::MAX_PAGES * 100) . ' devices max). Some devices may be missing from sync.'
            );
        }
        return $all;
    }

    // -----------------------------------------------------------------------
    // GWN signature
    // sig = sha256( & access_token=x&appID=x&secretKey=x&timestamp=x & sha256(body) & )
    // Note: GWN uses 'appID'/'secretKey', not 'client_id'/'client_secret'
    // -----------------------------------------------------------------------
    private static function gwnBuildSignature(
        string  $accessToken,
        string  $appId,
        string  $secretKey,
        int     $timestamp,
        ?string $body = null
    ): string {
        $params = [
            'access_token' => $accessToken,
            'appID'        => $appId,
            'secretKey'    => $secretKey,
            'timestamp'    => (string) $timestamp,
        ];
        ksort($params);
        $parts = [];
        foreach ($params as $k => $v) {
            $parts[] = "{$k}={$v}";
        }
        $paramStr = implode('&', $parts);

        if ($body !== null && $body !== '') {
            $bodyHash = hash('sha256', $body);
            $toSign   = '&' . $paramStr . '&' . $bodyHash . '&';
        } else {
            $toSign   = '&' . $paramStr . '&';
        }

        $sig = hash('sha256', $toSign);
        return $sig;
    }

    // -----------------------------------------------------------------------
    // GWN — get token (client_credentials grant)
    // -----------------------------------------------------------------------
    /** In-process token cache: [cacheKey => [token, expires_at]] */
    private static array $gwnTokenCache = [];

    public static function gwnGetToken(array $config): string|false {
        $cacheKey = ($config['gwn_client_id'] ?? '') . '|' . ($config['gwn_client_secret'] ?? '');
        $now      = time();
        if (isset(self::$gwnTokenCache[$cacheKey])) {
            [$cachedToken, $expiresAt] = self::$gwnTokenCache[$cacheKey];
            if ($now < $expiresAt) {
                PluginGdmsintegrationUtils::debug("GWN token OK (cached) — appID:{$config['gwn_client_id']}");
                return $cachedToken;
            }
        }
        $url = self::GWN_BASE . '/oauth/token?' . http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $config['gwn_client_id'],
            'client_secret' => $config['gwn_client_secret'],
        ]);
        PluginGdmsintegrationUtils::debug("GWN token request — appID:{$config['gwn_client_id']} [credentials redacted]");
        $data = self::curl($url);
        PluginGdmsintegrationUtils::debug("GWN token response — expires_in:" . ($data['expires_in'] ?? '?'));
        if ($data === false || empty($data['access_token'])) {
            PluginGdmsintegrationUtils::log("GWN token ERROR — appID:{$config['gwn_client_id']}");
            return false;
        }
        $expiresIn = (int)($data['expires_in'] ?? 3599);
        self::$gwnTokenCache[$cacheKey] = [$data['access_token'], $now + $expiresIn - 30];
        PluginGdmsintegrationUtils::debug("GWN token OK — appID:{$config['gwn_client_id']}");
        return $data['access_token'];
    }

    // -----------------------------------------------------------------------
    // GWN — signed request helper
    // All GWN API calls need: access_token + appID + timestamp + signature in URL
    // -----------------------------------------------------------------------
    private static function gwnSignedUrl(string $path, array $config, ?string $body = null): string {
        $ts  = (int) (microtime(true) * 1000);
        $sig = self::gwnBuildSignature(
            $config['gwn_access_token'],
            $config['gwn_client_id'],
            $config['gwn_client_secret'],
            $ts,
            $body
        );
        return self::GWN_BASE . '/oapi/' . self::VERSION . $path . '?' . http_build_query([
            'access_token' => $config['gwn_access_token'],
            'appID'        => $config['gwn_client_id'],
            'timestamp'    => $ts,
            'signature'    => $sig,
        ]);
    }

    // -----------------------------------------------------------------------
    // GWN — get all networks
    // -----------------------------------------------------------------------
    /**
     * @return array<string,string>|false  networkId => networkName map, or false on API error.
     *   An empty array means the account has no networks (valid).
     *   false means the API call itself failed (curl error or non-zero retCode).
     */
    private static function gwnGetNetworkIds(array $config): array|false {
        $body = json_encode(['pageNum' => 1, 'pageSize' => 200]);
        $url  = self::gwnSignedUrl('/network/list', $config, $body);
        PluginGdmsintegrationUtils::debug("GWN network/list URL: {$url}");
        PluginGdmsintegrationUtils::debug("GWN network/list body: {$body}");
        $data = self::curl($url, ['Content-Type: application/json'], $body);
        PluginGdmsintegrationUtils::debug("GWN network/list response: " . json_encode($data));
        if ($data === false || (int)($data['retCode'] ?? -1) !== 0) {
            PluginGdmsintegrationUtils::log("GWN network/list error: " . ($data['msg'] ?? json_encode($data)));
            return false;
        }
        $networks = $data['data']['result'] ?? [];
        if (!is_array($networks)) {
            PluginGdmsintegrationUtils::log("GWN network/list: unexpected data structure: " . json_encode($data['data'] ?? null));
            return false;
        }
        // Return id → networkName map instead of just IDs
        $map = [];
        foreach ($networks as $nw) {
            $map[(string)($nw['id'] ?? '')] = $nw['networkName'] ?? '';
        }
        PluginGdmsintegrationUtils::debug("GWN networks: " . json_encode($map));
        return $map;
    }

    // -----------------------------------------------------------------------
    // GWN — device/info for a single device (returns sn, ip, model, etc.)
    // -----------------------------------------------------------------------
    private static function gwnGetDeviceInfo(array $config, string $mac, string $networkId): ?array {
        $body = json_encode(['mac' => $mac, 'networkId' => (int)$networkId]);
        $url  = self::gwnSignedUrl('/device/info', $config, $body);
        PluginGdmsintegrationUtils::debug("GWN device/info {$mac} URL: {$url}");
        PluginGdmsintegrationUtils::debug("GWN device/info {$mac} body: {$body}");
        $data = self::curl($url, ['Content-Type: application/json'], $body);
        PluginGdmsintegrationUtils::debug("GWN device/info {$mac} response: " . substr(json_encode($data), 0, 500));
        if ($data === false || (int)($data['retCode'] ?? -1) !== 0) {
            PluginGdmsintegrationUtils::debug("GWN device/info {$mac} error: " . ($data['msg'] ?? json_encode($data)));
            return null;
        }
        // result[] is array of {type, value, key} objects — find key==='sn'
        $outerData = $data['data'] ?? null;
        if (!is_array($outerData)) return null;

        $rawResult = $outerData['result'] ?? null;
        $info = ['sn' => '', 'SN' => ''];
        if (is_array($rawResult)) {
            foreach ($rawResult as $item) {
                if (!is_array($item)) continue;
                $k = strtolower($item['key'] ?? '');
                $v = $item['value'] ?? '';
                if ($k === 'sn' || $k === 'serial' || $k === 'serialno') {
                    $info['sn'] = $v;
                } elseif ($k === 'firmwareversion' || $k === 'firmware') {
                    $info['firmwareVersion'] = $v;
                } elseif ($k === 'uptime') {
                    $info['upTime'] = (int)$v;
                } else {
                    $info[$item['key'] ?? $k] = $v;
                }
            }
        }
        if (empty($info['sn'])) {
            $info['sn'] = $outerData['sn'] ?? $outerData['SN'] ?? '';
        }

        $sn = $info['sn'] ?? '';
        PluginGdmsintegrationUtils::debug("GWN device/info {$mac} SN:'{$sn}'");
        return array_merge($outerData, $info);
    }

    // -----------------------------------------------------------------------
    // GWN — devices per network
    // -----------------------------------------------------------------------
    /**
     * Returns device list on success, or FALSE if a network-level API call failed.
     * An empty array means the account has no devices (valid state).
     * FALSE means the API itself could not be reached — callers must NOT purge stored state.
     *
     * @return array|false
     */
    public static function gwnGetDevices(array $config): array|false {
        $networkMap = self::gwnGetNetworkIds($config); // id => name, or false on error
        if ($networkMap === false) {
            PluginGdmsintegrationUtils::log("GWN: network/list failed — aborting device fetch");
            return false;
        }
        if (empty($networkMap)) {
            PluginGdmsintegrationUtils::debug("GWN: no networks configured — skipping devices");
            return [];
        }
        // Pre-fetch token once — reuse for all page requests and info batches
        $token = self::gwnGetToken($config) ?: '';

        // Pre-load SN cache for all managed MACs — avoids one DB query per device below.
        $_snCache = [];
        foreach ((new PluginGdmsintegrationDevice())->find() as $_row) {
            $_m = strtolower(trim($_row['mac'] ?? ''));
            if ($_m && !empty($_row['sn_cloud'])) $_snCache[$_m] = $_row['sn_cloud'];
        }

        $all            = [];
        $hadNetworkError = false;
        foreach ($networkMap as $networkId => $networkName) {
            $page = 1;
            do {
                $body = json_encode(['networkId' => $networkId, 'pageNum' => $page, 'pageSize' => 100]);
                $url  = self::gwnSignedUrl('/ap/list', $config, $body);
                PluginGdmsintegrationUtils::debug("GWN ap/list URL: {$url}");
                PluginGdmsintegrationUtils::debug("GWN ap/list body: {$body}");
                $data = self::curl($url, ['Content-Type: application/json'], $body);
                PluginGdmsintegrationUtils::debug("GWN ap/list network {$networkId} page {$page} response: " . json_encode($data));
                if ($data === false || (int)($data['retCode'] ?? -1) !== 0) {
                    PluginGdmsintegrationUtils::log("GWN ap/list error network {$networkId}: " . ($data['msg'] ?? ''));
                    $hadNetworkError = true;
                    break;
                }
                $raw   = $data['data']['result'] ?? $data['data'] ?? [];
                $batch = is_array($raw) ? $raw : [];
                PluginGdmsintegrationUtils::log("GWN network {$networkId} page {$page}: " . count($batch) . " device(s)");
                if (!empty($batch)) {
                    $batch = array_map(static function (array $d) use ($networkId, $networkName): array {
                        return array_merge($d, [
                            'deviceName'  => $d['name']   ?? ($d['deviceName']  ?? ''),
                            'deviceType'  => $d['apType'] ?? ($d['deviceType']  ?? ''),
                            'sn'          => $d['sn']     ?? '',
                            'networkId'   => $networkId,
                            'networkName' => $networkName,
                            'ipv6'        => $d['ipv6']   ?? $d['ipv6Address'] ?? '',
                            'location'    => $d['location'] ?? $d['site'] ?? '',
                        ]);
                    }, $batch);
                    // Enrich SN via device/info — use pre-loaded cache first, parallel curl_multi for misses.
                    $needsInfo = [];
                    foreach ($batch as $idx => $dev) {
                        $devMac = strtolower(trim($dev['mac'] ?? ''));
                        if (empty($dev['sn']) && !empty($devMac)) {
                            if (!empty($_snCache[$devMac])) {
                                $batch[$idx]['sn'] = $_snCache[$devMac];
                                PluginGdmsintegrationUtils::debug("GWN SN cached for {$devMac}: {$_snCache[$devMac]}");
                            } else {
                                $needsInfo[$idx] = $dev;
                            }
                        }
                    }

                    // Parallel fetch device/info for all devices needing SN
                    if (!empty($needsInfo)) {
                        $infoResults = self::gwnGetDeviceInfoBatch($config, $needsInfo, $networkId, $token ?? '');
                        foreach ($infoResults as $idx => $info) {
                            if ($info === null) continue;
                            $sn = $info['sn'] ?? $info['SN'] ?? $info['serialNo'] ?? $info['serial'] ?? '';
                            $batch[$idx]['sn'] = $sn;
                            if (empty($batch[$idx]['versionFirmware']) && !empty($info['firmwareVersion'])) {
                                $batch[$idx]['versionFirmware'] = $info['firmwareVersion'];
                            }
                            if (empty($batch[$idx]['upTime']) && !empty($info['upTime'])) {
                                $batch[$idx]['upTime'] = (int)$info['upTime'];
                            }
                        }
                    }
                    $all = array_merge($all, array_values($batch));
                }
                $page++;
            } while (!empty($batch) && $page <= self::MAX_PAGES);
        }
        if ($hadNetworkError) {
            return false;
        }
        return $all;
    }

    // -----------------------------------------------------------------------
    // Connection test — called from config form on Save
    // Returns ['gwn' => bool|string, 'gdms' => bool|string]
    // -----------------------------------------------------------------------
    /**
     * Batch parallel device/info using curl_multi — all requests fire simultaneously.
     * @param array $devices  [idx => device_row] only devices needing SN enrichment
     */
    private static function gwnGetDeviceInfoBatch(array $config, array $devices, int $networkId, string $cachedToken = ''): array {
        $token   = $cachedToken ?: self::gwnGetToken($config);
        if (!$token) return [];
        $appId   = $config['gwn_client_id']     ?? '';
        $secret  = $config['gwn_client_secret'] ?? '';
        $baseUrl = 'https://www.gwn.cloud/oapi/v1.0.0/device/info';

        $mh      = curl_multi_init();
        $handles = [];

        foreach ($devices as $idx => $dev) {
            $mac     = $dev['mac'];
            $body    = json_encode(['mac' => $mac, 'networkId' => $networkId]);
            $ts      = (int)(microtime(true) * 1000);
            $bHash   = hash('sha256', $body);
            $sigIn   = "&access_token={$token}&appID={$appId}&secretKey={$secret}&timestamp={$ts}&{$bHash}&";
            $sig     = hash('sha256', $sigIn);
            $url     = $baseUrl . '?access_token=' . urlencode($token)
                                . '&appID='        . urlencode($appId)
                                . "&timestamp={$ts}&signature={$sig}";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$idx] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running > 0);

        $results = [];
        foreach ($handles as $idx => $ch) {
            $raw  = curl_multi_getcontent($ch);
            $data = $raw ? json_decode($raw, true) : null;
            $info = null;
            if (is_array($data)) {
                $outer = $data['data'] ?? null;
                if (is_array($outer)) {
                    $rawRes = $outer['result'] ?? null;
                    // result[] is an array of {type, value, key} objects
                    // Find the element where key === 'sn' (or 'SN')
                    $info = ['sn' => '', 'SN' => ''];
                    if (is_array($rawRes)) {
                        foreach ($rawRes as $item) {
                            if (!is_array($item)) continue;
                            $k = strtolower($item['key'] ?? '');
                            $v = $item['value'] ?? '';
                            if ($k === 'sn' || $k === 'serial' || $k === 'serialno') {
                                $info['sn'] = $v;
                            } elseif ($k === 'firmwareversion' || $k === 'firmware') {
                                $info['firmwareVersion'] = $v;
                            } elseif ($k === 'uptime') {
                                $info['upTime'] = (int)$v;
                            } elseif ($k === 'ipv4' || $k === 'ip') {
                                if (empty($info['ip'])) $info['ip'] = $v;
                            } else {
                                $info[$item['key'] ?? $k] = $v;
                            }
                        }
                    }
                    // Also check top-level outer fields (sn/SN at data level)
                    if (empty($info['sn'])) {
                        $info['sn'] = $outer['sn'] ?? $outer['SN'] ?? '';
                    }
                }
            }
            $mac = $devices[$idx]['mac'] ?? '?';
            $sn  = $info ? ($info['sn'] ?? $info['SN'] ?? '') : '';
            PluginGdmsintegrationUtils::log(
                "GWN batchInfo {$mac} SN:'{$sn}' sample:" .
                substr(json_encode(array_slice((array)$info, 0, 6, true)), 0, 300)
            );
            $results[$idx] = $info;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
        return $results;
    }

    /**
     * Get connected WiFi clients for a network (or a specific AP MAC).
     * POST /oapi/v1.0.0/client/list {networkId[, mac, pageNum, pageSize]}
     * Returns: [{mac, hostname, ip, rssi, band, ssid, apMac, connectTime, txRate, rxRate}]
     */
    public static function gwnGetClientList(array $config, int $networkId, string $mac = '', int $pageSize = 200): array {
        $token = self::gwnGetToken($config);
        if (!$token) return [];
        $config['gwn_access_token'] = $token; // required by gwnSignedUrl
        $body  = ['networkId' => $networkId, 'pageNum' => 1, 'pageSize' => $pageSize];
        if ($mac !== '') $body['mac'] = $mac;
        $bodyStr = json_encode($body);
        $url = self::gwnSignedUrl('/client/list', $config, $bodyStr);
        $data = self::curl($url, ['Content-Type: application/json'], $bodyStr);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            PluginGdmsintegrationUtils::debug("GWN client/list error network {$networkId}: " . ($data['msg'] ?? json_encode($data)));
            return [];
        }
        $raw = $data['data']['result'] ?? $data['data'] ?? [];
        return is_array($raw) ? $raw : [];
    }

    /**
     * Get recent cloud alerts for a network.
     * POST /oapi/v1.0.0/alert/list {networkId, pageNum, pageSize}
     * Returns normalized: [{id, alertType, severity, deviceMac, deviceName, description, createTime}]
     */
    public static function gwnGetAlerts(array $config, int $networkId, int $pageSize = 50): array {
        $token = self::gwnGetToken($config);
        if (!$token) return [];
        $config['gwn_access_token'] = $token;
        $body    = json_encode(['networkId' => $networkId, 'pageNum' => 1, 'pageSize' => $pageSize]);
        $url     = self::gwnSignedUrl('/alert/list', $config, $body);
        $data    = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            PluginGdmsintegrationUtils::log("GWN alert/list error network {$networkId}: " . ($data['msg'] ?? json_encode($data)));
            return [];
        }
        $raw = $data['data']['result'] ?? $data['data']['list'] ?? $data['data'] ?? [];
        if (!is_array($raw)) return [];
        // Log raw structure once per call to help identify field names
        if (!empty($raw)) {
            PluginGdmsintegrationUtils::debug("GWN alert sample (network {$networkId}): " . json_encode($raw[0]));
        }
        // Normalize field names — actual GWN API uses: id, time, level, content, detailMap
        // GWN Cloud: higher level = more severe (5=Emergency, 4=Alert, 3=Warning, 2=Notice, 1=Info)
        $sevMap = [5 => 'critical', 4 => 'warning', 3 => 'medium', 2 => 'low', 1 => 'info'];
        foreach ($raw as &$a) {
            // id (may be int)
            $a['id'] = (string)($a['id'] ?? $a['alertId'] ?? $a['alert_id'] ?? '');
            // createTime (ms) — actual field is 'time'
            if (empty($a['createTime'])) {
                $ct = $a['time'] ?? $a['createAt'] ?? $a['create_at'] ?? $a['create_time']
                   ?? $a['alertTime'] ?? $a['alert_time'] ?? $a['alarmTime']
                   ?? $a['occurTime'] ?? $a['alertOccurTime'] ?? $a['timestamp'] ?? 0;
                if ($ct && $ct < 9999999999) $ct *= 1000; // seconds → ms
                $a['createTime'] = (int)$ct;
            } elseif ($a['createTime'] > 0 && $a['createTime'] < 9999999999) {
                $a['createTime'] = (int)$a['createTime'] * 1000;
            }
            // severity string
            if (empty($a['severity'])) {
                $lvl = $a['level'] ?? $a['alertLevel'] ?? $a['alert_level'] ?? null;
                $a['severity'] = is_int($lvl) ? ($sevMap[$lvl] ?? 'info') : (string)($lvl ?? 'info');
            }
            // deviceName — actual field is detailMap.apName
            if (empty($a['deviceName'])) {
                $dm = $a['detailMap'] ?? [];
                $a['deviceName'] = $dm['apName'] ?? $dm['name'] ?? $dm['routerName']
                                ?? $a['device_name'] ?? $a['apName'] ?? $a['ap_name']
                                ?? $a['routerName'] ?? $a['name'] ?? '';
            }
            // deviceMac — actual field is detailMap.detail (when isMac=1)
            if (empty($a['deviceMac'])) {
                $dm = $a['detailMap'] ?? [];
                $a['deviceMac'] = (($dm['isMac'] ?? '') === '1' ? ($dm['detail'] ?? '') : '')
                               ?: ($dm['apMac'] ?? $dm['mac'] ?? $a['device_mac']
                                ?? $a['apMac'] ?? $a['ap_mac'] ?? $a['mac'] ?? '');
            }
            // description — actual field is 'content'
            if (empty($a['description'])) {
                $a['description'] = $a['content'] ?? $a['alertContent'] ?? $a['alert_content']
                                 ?? $a['alertTitle'] ?? $a['title'] ?? $a['alertType'] ?? '';
            }
            // category — basicDataKey (e.g. "offline", "wan", "cpu") for filtering/display
            if (empty($a['category'])) {
                $a['category'] = $a['basicDataKey'] ?? $a['alertCategory'] ?? $a['category'] ?? '';
            }
            // detailMap extras — reason and port_id for enriched display
            $dm = $a['detailMap'] ?? [];
            if (is_array($dm)) {
                if (empty($a['reason']))      $a['reason']      = $dm['reason']      ?? $dm['failReason']   ?? '';
                if (empty($a['port_id']))     $a['port_id']     = $dm['port_id']     ?? $dm['portId']       ?? '';
                if (empty($a['deviceType']))  $a['deviceType']  = $dm['deviceType']  ?? '';
            }
        }
        unset($a);
        return $raw;
    }

    /**
     * POST /oapi/v1.0.0/alert/dismiss — dismiss (delete) alerts by ID.
     * @param array $ids  alert id strings
     */
    public static function gwnDismissAlerts(array $config, array $ids): bool {
        $token = self::gwnGetToken($config);
        if (!$token) return false;
        $config['gwn_access_token'] = $token;
        $body = json_encode(['ids' => array_values($ids)]);
        $url  = self::gwnSignedUrl('/alert/dismiss', $config, $body);
        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            PluginGdmsintegrationUtils::log("GWN alert/dismiss error: " . ($data['msg'] ?? json_encode($data)));
            return false;
        }
        return true;
    }

    /**
     * Get firmware versions for all devices in a network.
     * POST /oapi/v1.0.0/upgrade/version {networkId}
     * Returns: [{mac, type, currentVersion, lastVersion}]
     */
    /**
     * Get switch port status via /switch/portInfo.
     * Returns normalised port array (role=0, link, speed, type, customName, desc, txBytes, rxBytes, vlan).
     * Only meaningful for GWN78xx / GSS switches — routers/APs return empty.
     */
    public static function gwnGetSwitchPortInfo(array $config, string $mac, int $networkId): array {
        $token  = self::gwnGetToken($config);
        if (!$token) return [];
        $appId  = $config['gwn_client_id']     ?? '';
        $secret = $config['gwn_client_secret'] ?? '';

        $body = json_encode(['mac' => $mac, 'networkId' => $networkId]);
        $ts   = (int)(microtime(true) * 1000);
        $sig  = self::gwnBuildSignature($token, $appId, $secret, $ts, $body);
        $url  = self::GWN_BASE . '/oapi/' . self::VERSION . '/switch/portInfo'
                . '?access_token=' . urlencode($token)
                . '&appID='        . urlencode($appId)
                . "&timestamp={$ts}&signature={$sig}";

        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            PluginGdmsintegrationUtils::log("GWN switch/portInfo error for {$mac}: " . ($data['msg'] ?? json_encode($data)));
            return [];
        }
        $raw = $data['data']['result'] ?? [];
        PluginGdmsintegrationUtils::debug("GWN switch/portInfo {$mac}: " . json_encode($raw));
        return is_array($raw) ? $raw : [];
    }

    /**
     * Get router port info including WAN status via device/info.
     * Returns portInfo[] and ipv4Info[] for the given router MAC.
     * Only meaningful for routers (GWN7001, GWN7002, etc.) — APs return empty portInfo.
     */
    public static function gwnGetRouterPortInfo(array $config, string $mac, int $networkId): array {
        $token  = self::gwnGetToken($config);
        if (!$token) return [];
        $appId  = $config['gwn_client_id']     ?? '';
        $secret = $config['gwn_client_secret'] ?? '';

        $body = json_encode(['mac' => $mac, 'networkId' => $networkId]);
        $ts   = (int)(microtime(true) * 1000);
        $sig  = self::gwnBuildSignature($token, $appId, $secret, $ts, $body);
        $url  = self::GWN_BASE . '/oapi/v1.0.0/device/info'
                . '?access_token=' . urlencode($token)
                . '&appID='        . urlencode($appId)
                . "&timestamp={$ts}&signature={$sig}";

        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            PluginGdmsintegrationUtils::log("GWN device/info port error for {$mac}: " . ($data['msg'] ?? json_encode($data)));
            return [];
        }

        $info      = $data['data'] ?? [];
        $portInfo  = [];
        // Flatten result[] array of {type,value,key} objects into associative
        $resultArr = $info['result'] ?? [];
        if (is_array($resultArr)) {
            foreach ($resultArr as $item) {
                if (isset($item['key'])) {
                    $portInfo[$item['key']] = $item['value'] ?? '';
                }
            }
        }

        PluginGdmsintegrationUtils::debug("GWN portInfo for {$mac}: " . json_encode($info['portInfo'] ?? []));
        PluginGdmsintegrationUtils::debug("GWN ipv4Info for {$mac}: " . json_encode($info['ipv4Info'] ?? []));
        return [
            'portInfo'  => $info['portInfo']  ?? [],
            'ipv4Info'  => $info['ipv4Info']  ?? [],
            'raw_info'  => $info,
        ];
    }

        public static function gwnGetFirmwareVersions(array $config, int $networkId): array {
        return self::gwnGetFirmwareVersionsBatch($config, [$networkId])[$networkId] ?? [];
    }

    /**
     * Parallel firmware version fetch for multiple networks via curl_multi.
     * Returns [networkId => [firmware rows]]
     */
    public static function gwnGetFirmwareVersionsBatch(array $config, array $networkIds): array {
        $token  = self::gwnGetToken($config);
        if (!$token || empty($networkIds)) return [];
        $appId  = $config['gwn_client_id']     ?? '';
        $secret = $config['gwn_client_secret'] ?? '';

        $mh      = curl_multi_init();
        $handles = [];
        foreach ($networkIds as $nid) {
            $body = json_encode(['networkId' => $nid]);
            $ts   = (int)(microtime(true) * 1000);
            $sig  = self::gwnBuildSignature($token, $appId, $secret, $ts, $body);
            $url  = self::GWN_BASE . '/oapi/v1.0.0/upgrade/version'
                  . '?access_token=' . urlencode($token)
                  . '&appID='        . urlencode($appId)
                  . "&timestamp={$ts}&signature={$sig}";
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[$nid] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) curl_multi_select($mh, 0.5);
        } while ($running > 0);

        $results = [];
        foreach ($handles as $nid => $ch) {
            $raw  = curl_multi_getcontent($ch);
            $data = $raw ? json_decode($raw, true) : null;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            if (is_array($data) && (int)($data['retCode'] ?? -1) === 0) {
                $r = $data['data']['result'] ?? [];
                $results[$nid] = is_array($r) ? $r : [];
            } else {
                PluginGdmsintegrationUtils::log("GWN upgrade/version error network {$nid}: " . ($data['msg'] ?? ''));
                $results[$nid] = [];
            }
        }
        curl_multi_close($mh);
        return $results;
    }

    /**
     * Schedule firmware upgrade for given MACs.
     * POST /oapi/v1.0.0/upgrade/add {macs: [...], time: <ms_timestamp_or_0>}
     * time=0 means "apply as soon as possible" (Grandstream immediate mode).
     * Returns: {success_upgrade_macs: [...]}
     */
    public static function gwnScheduleUpgrade(array $config, array $macs, int $scheduleTimeMs = 0): array {
        $token  = self::gwnGetToken($config);
        if (!$token) return ['error' => 'Cannot obtain token'];
        $appId  = $config['gwn_client_id']     ?? '';
        $secret = $config['gwn_client_secret'] ?? '';

        $payload = ['macs' => $macs];
        if ($scheduleTimeMs > 0) {
            $payload['time'] = $scheduleTimeMs; // scheduled datetime in ms epoch
        }
        // scheduleTimeMs === 0 → omit field, API defaults to ASAP

        $body   = json_encode($payload);
        $ts  = (int)(microtime(true) * 1000);
        $sig = self::gwnBuildSignature($token, $appId, $secret, $ts, $body);
        $url = self::GWN_BASE . '/oapi/v1.0.0/upgrade/add'
               . '?access_token=' . urlencode($token)
               . '&appID='        . urlencode($appId)
               . "&timestamp={$ts}&signature={$sig}";

        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            $err = $data['msg'] ?? json_encode($data);
            PluginGdmsintegrationUtils::log("GWN upgrade/add error: {$err}");
            return ['error' => $err];
        }
        $ok = $data['data']['success_upgrade_macs'] ?? [];
        $mode = $scheduleTimeMs > 0 ? "scheduled @{$scheduleTimeMs}" : "ASAP";
        PluginGdmsintegrationUtils::log("GWN upgrade ({$mode}) for MACs: " . implode(', ', (array)$ok));
        return ['success' => $ok];
    }

    // -----------------------------------------------------------------------
    // GDMS — Task signature (different from device/list signature)
    // task/add: SHA256(&access_token=…&client_id=…&client_secret=…&timestamp=…&SHA256(body)&)
    // -----------------------------------------------------------------------
    private static function gdmsBuildTaskSignature(
        string $accessToken,
        string $clientId,
        string $clientSecret,
        int    $timestamp,
        string $body
    ): string {
        $bodyHash = hash('sha256', $body);
        $toSign   = '&access_token='  . $accessToken
                  . '&client_id='     . $clientId
                  . '&client_secret=' . $clientSecret
                  . '&timestamp='     . $timestamp
                  . '&' . $bodyHash . '&';
        $sig = hash('sha256', $toSign);
        return $sig;
    }

    // -----------------------------------------------------------------------
    // GDMS — Create upgrade task for UC devices (UCM/GCC/GRP/GXP/WP/HT etc.)
    // POST /oapi/v1.0.0/task/add
    // $mac        — colon-format MAC e.g. "C0:74:AD:DE:E2:8E"
    // $version    — target firmware e.g. "1.0.31.7"
    // $scheduleMs — 0 = ASAP, >0 = epoch ms scheduled time
    // -----------------------------------------------------------------------
    public static function gdmsCreateUpgradeTask(
        array  $config,
        string $mac,
        string $version,
        int    $scheduleMs = 0,
        string $downloadUrl = ''
    ): array {
        $tokenData = self::gdmsGetToken($config);
        if (!$tokenData) return ['error' => 'Cannot obtain GDMS token'];

        $token    = $tokenData['access_token'] ?? '';
        $clientId = $tokenData['client_id']    ?? ($config['client_id'] ?? '');
        $secret   = $config['client_secret'] ?? '';

        $glpiVer = 'GLPI' . ((int) explode('.', GLPI_VERSION)[0]);
        $ts0     = (int)(microtime(true) * 1000);
        $payload = [
            'taskName'  => $glpiVer . '_UPG_' . strtoupper(str_replace(':', '', $mac)) . '_' . $ts0,
            'taskType'  => 3,
            'macList'   => [$mac],
            'execType'  => $scheduleMs > 0 ? 2 : 1,
            'fwVersion' => $version,
        ];
        if ($downloadUrl !== '') {
            $payload['firmwareDownloadUrl'] = $downloadUrl;
        }
        if ($scheduleMs > 0) {
            $payload['startTimestamp'] = $scheduleMs;
            $payload['endTimestamp']   = $scheduleMs + 7 * 24 * 3600 * 1000;
        }

        $body = json_encode($payload);
        $ts   = $ts0;
        $sig  = self::gdmsBuildTaskSignature($token, $clientId, $secret, $ts, $body);

        $url = self::GDMS_BASE . '/v1.0.0/task/add'
             . '?access_token=' . urlencode($token)
             . '&signature='    . $sig
             . "&timestamp={$ts}";

        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || ($data['retCode'] ?? -1) != 0) {
            $err = $data['msg'] ?? json_encode($data);
            PluginGdmsintegrationUtils::log("GDMS task/add UPGRADE error for {$mac}: {$err}");
            return ['error' => $err];
        }
        $mode = $scheduleMs > 0 ? "scheduled @{$scheduleMs}" : "ASAP";
        PluginGdmsintegrationUtils::log("GDMS upgrade task created ({$mode}) for {$mac} v{$version}");
        return ['success' => true, 'mac' => $mac, 'version' => $version];
    }

    // GDMS — Reboot task for UC devices
    // POST /oapi/v1.0.0/task/add  taskType=1
    // -----------------------------------------------------------------------
    public static function gdmsCreateRebootTask(array $config, string $mac): array {
        $tokenData = self::gdmsGetToken($config);
        if (!$tokenData) return ['error' => 'Cannot obtain GDMS token'];

        $token    = $tokenData['access_token'] ?? '';
        $clientId = $tokenData['client_id']    ?? ($config['client_id'] ?? '');
        $secret   = $config['client_secret'] ?? '';

        $glpiVer = 'GLPI' . ((int) explode('.', GLPI_VERSION)[0]);
        $ts_r    = (int)(microtime(true) * 1000);
        $payload = [
            'taskName' => $glpiVer . '_RBT_' . strtoupper(str_replace(':', '', $mac)) . '_' . $ts_r,
            'taskType' => 1,
            'macList'  => [$mac],
            'execType' => 1,
        ];

        $body = json_encode($payload);
        $ts   = $ts_r;
        $sig  = self::gdmsBuildTaskSignature($token, $clientId, $secret, $ts, $body);

        $url = self::GDMS_BASE . '/v1.0.0/task/add'
             . '?access_token=' . urlencode($token)
             . '&signature='    . $sig
             . "&timestamp={$ts}";

        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || ($data['retCode'] ?? -1) != 0) {
            $err = $data['msg'] ?? json_encode($data);
            PluginGdmsintegrationUtils::log("GDMS task/add REBOOT error for {$mac}: {$err}");
            return ['error' => $err];
        }
        PluginGdmsintegrationUtils::log("GDMS reboot task created for {$mac}");
        return ['success' => true, 'mac' => $mac];
    }

    // -----------------------------------------------------------------------
    // Grandstream firmware page scraper
    // Returns ['official' => 'x.x.x.x'|null, 'officialUrl' => '...'|null]
    // $slug — URL slug e.g. 'ucm6300', 'grp260x'
    // -----------------------------------------------------------------------
    public static function scrapeFirmwareVersions(string $slug): array {
        $base   = 'https://www.grandstream.com/support/firmware/';
        $result = ['official' => null, 'officialUrl' => null];

        $html = self::curlGet($base . $slug . '-official-firmware');
        if ($html && preg_match('/(\d+\.\d+\.\d+\.\d+)/', $html, $m)) {
            $result['official'] = $m[1];
            if (preg_match('/href=["\']([^"\']+\.(?:zip|bin))["\']/', $html, $um)) {
                $fn = preg_replace('/fw[\d.]+\.bin$/i', 'fw.bin', basename($um[1]));
                $result['officialUrl'] = 'https://firmware.grandstream.com/' . $fn;
            }
        }

        return $result;
    }

    // Simple GET-only curl helper for public pages (no auth required)
    private static function curlGet(string $url, int $timeout = 8): string|false {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; GLPIPlugin/1.0)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($body !== false && $code === 200) ? $body : false;
    }


        public static function testConnections(array $config): array {
        $result = ['gwn' => null, 'gdms' => null];

        // Test GWN
        if (!empty($config['gwn_client_id']) && !empty($config['gwn_client_secret'])) {
            $tok = self::gwnGetToken($config);
            $result['gwn'] = ($tok !== false) ? true : 'Token request failed — check API ID and Secret Key';
        }

        // Test GDMS
        if (!empty($config['client_id']) && !empty($config['client_secret'])
            && !empty($config['username']) && !empty($config['password'])) {
            $tok = self::gdmsGetToken($config);
            if ($tok === false) {
                $result['gdms'] = 'Token request failed — check username, password, API ID and Secret Key';
            } else {
                $result['gdms'] = true;
            }
        }

        return $result;
    }
}
