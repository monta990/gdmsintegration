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
    private const PHONE_PREFIXES = ['GRP','GXP','GXV','GXW','WP','HT','DP','GHP','GVC','GSC','GDS'];
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

        PluginGdmsintegrationUtils::debug("GDMS sign input: {$toSign}");
        $sig = hash('sha256', $toSign);
        PluginGdmsintegrationUtils::debug("GDMS signature:  {$sig}");
        return $sig;
    }

    // -----------------------------------------------------------------------
    // GDMS — get token
    // -----------------------------------------------------------------------
    public static function gdmsGetToken(array $config): array|false {
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
        PluginGdmsintegrationUtils::log("GDMS token OK — username:{$config['username']} | API ID:{$config['client_id']} | expires:{$data['expires_in']}s");
        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? '',
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
        ];
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
            $all  = array_merge($all, $batch);
            $page++;
        } while (!empty($batch) && $page <= self::MAX_PAGES);
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

        PluginGdmsintegrationUtils::debug("GWN sign input:  {$toSign}");
        $sig = hash('sha256', $toSign);
        PluginGdmsintegrationUtils::debug("GWN signature:   {$sig}");
        return $sig;
    }

    // -----------------------------------------------------------------------
    // GWN — get token (client_credentials grant)
    // -----------------------------------------------------------------------
    public static function gwnGetToken(array $config): string|false {
        $url = self::GWN_BASE . '/oauth/token?' . http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $config['gwn_client_id'],
            'client_secret' => $config['gwn_client_secret'],
        ]);
        PluginGdmsintegrationUtils::debug("GWN token request URL: {$url}");
        $data = self::curl($url);
        PluginGdmsintegrationUtils::debug("GWN token response: " . json_encode($data));
        if ($data === false || empty($data['access_token'])) {
            PluginGdmsintegrationUtils::log("GWN token ERROR — appID:{$config['gwn_client_id']}");
            return false;
        }
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
    /** @return array<string,string> networkId => networkName */
    private static function gwnGetNetworkIds(array $config): array {
        $body = json_encode(['pageNum' => 1, 'pageSize' => 200]);
        $url  = self::gwnSignedUrl('/network/list', $config, $body);
        PluginGdmsintegrationUtils::debug("GWN network/list URL: {$url}");
        PluginGdmsintegrationUtils::debug("GWN network/list body: {$body}");
        $data = self::curl($url, ['Content-Type: application/json'], $body);
        PluginGdmsintegrationUtils::debug("GWN network/list response: " . json_encode($data));
        if ($data === false || (int)($data['retCode'] ?? -1) !== 0) {
            PluginGdmsintegrationUtils::log("GWN network/list error: " . ($data['msg'] ?? json_encode($data)));
            return [];
        }
        $networks = $data['data']['result'] ?? [];
        if (!is_array($networks)) {
            PluginGdmsintegrationUtils::log("GWN network/list: unexpected data structure: " . json_encode($data['data'] ?? null));
            return [];
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
    public static function gwnGetDevices(array $config): array {
        $networkMap = self::gwnGetNetworkIds($config); // id => name
        if (empty($networkMap)) {
            PluginGdmsintegrationUtils::debug("GWN: no networks — skipping devices");
            return [];
        }
        // Pre-fetch token once — reuse for all page requests and info batches
        $token = self::gwnGetToken($config) ?: '';
        $all = [];
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
                        ]);
                    }, $batch);
                    // Enrich SN via device/info — parallel curl_multi for speed
                    $deviceStateObj = new PluginGdmsintegrationDevice();

                    // Separate devices that need info from those that don't
                    $needsInfo = [];
                    foreach ($batch as $idx => $dev) {
                        $devMac = strtolower(trim($dev['mac'] ?? ''));
                        if (empty($dev['sn']) && !empty($devMac)) {
                            $cached = $deviceStateObj->find(['mac' => $devMac]);
                            $cachedSn = !empty($cached) ? (reset($cached)['sn_cloud'] ?? '') : '';
                            if (!empty($cachedSn)) {
                                $batch[$idx]['sn'] = $cachedSn;
                                PluginGdmsintegrationUtils::debug("GWN SN cached for {$devMac}: {$cachedSn}");
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
     * Get firmware versions for all devices in a network.
     * POST /oapi/v1.0.0/upgrade/version {networkId}
     * Returns: [{mac, type, currentVersion, lastVersion}]
     */
    public static function gwnGetFirmwareVersions(array $config, int $networkId): array {
        $token  = self::gwnGetToken($config);
        if (!$token) return [];
        $appId  = $config['gwn_client_id']     ?? '';
        $secret = $config['gwn_client_secret'] ?? '';

        $body   = json_encode(['networkId' => $networkId]);
        $ts  = (int)(microtime(true) * 1000);
        $sig = self::gwnBuildSignature($token, $appId, $secret, $ts, $body);
        $url = self::GWN_BASE . '/oapi/v1.0.0/upgrade/version'
               . '?access_token=' . urlencode($token)
               . '&appID='        . urlencode($appId)
               . "&timestamp={$ts}&signature={$sig}";

        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            PluginGdmsintegrationUtils::log("GWN upgrade/version error network {$networkId}: " . ($data['msg'] ?? json_encode($data)));
            return [];
        }
        $result = $data['data']['result'] ?? [];
        PluginGdmsintegrationUtils::debug("GWN upgrade/version network {$networkId} raw: " . substr(json_encode($data['data'] ?? []), 0, 500));
        return is_array($result) ? $result : [];
    }

    /**
     * Schedule firmware upgrade for given MACs.
     * POST /oapi/v1.0.0/upgrade/add {macs: [...]}
     * Returns: {success_upgrade_macs: [...]}
     */
    public static function gwnScheduleUpgrade(array $config, array $macs): array {
        $token  = self::gwnGetToken($config);
        if (!$token) return ['error' => 'Cannot obtain token'];
        $appId  = $config['gwn_client_id']     ?? '';
        $secret = $config['gwn_client_secret'] ?? '';

        $body   = json_encode(['macs' => $macs]);
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
        PluginGdmsintegrationUtils::log("GWN upgrade scheduled for MACs: " . implode(', ', (array)$ok));
        return ['success' => $ok];
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
