<?php

namespace GlpiPlugin\Gdmsintegration;
/**
 * GDMS Integration — \GlpiPlugin\Gdmsintegration\API
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
class API {

    private const GDMS_BASE_US = 'https://www.gdms.cloud/oapi';
    private const GDMS_BASE_EU = 'https://eu.gdms.cloud/oapi';
    private const GWN_BASE  = 'https://www.gwn.cloud';
    private const VERSION   = 'v1.0.0';
    private const TIMEOUT   = 25;
    private const MAX_PAGES = 50;
    private const INFO_BATCH_SIZE = 40;

    private static function gdmsBase(array $config): string {
        return strtolower((string)($config['gdms_region'] ?? 'us')) === 'eu'
            ? self::GDMS_BASE_EU
            : self::GDMS_BASE_US;
    }

    private static function redactUrl(string $url): string {
        return preg_replace('/([?&](?:access_token|refresh_token|token|Authorization|client_secret|secretKey|password)=)[^&\s"\']+/i', '$1[REDACTED]', $url) ?? $url;
    }

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
        array $headers = [],
        ?string $body = null,
        bool $retrySafe = false
    ): array|false {
        $attempts = $retrySafe ? 3 : 1;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => self::TIMEOUT,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADER         => true,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $response = curl_exec($ch);
            $err      = curl_error($ch);
            $code     = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSz = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $raw      = is_string($response) ? substr($response, $headerSz) : '';
            $rawHdr   = is_string($response) ? substr($response, 0, $headerSz) : '';
            curl_close($ch);

            $retryable = $retrySafe && ($err !== '' || in_array($code, [429, 502, 503, 504], true));
            if ($retryable && $attempt < $attempts) {
                $waitUs = 250000 * (2 ** ($attempt - 1));
                if ($code === 429 && preg_match('/^Retry-After:\s*(\d+)/mi', $rawHdr, $m)) {
                    $waitUs = min(5000000, max($waitUs, (int)$m[1] * 1000000));
                }
                $waitUs += random_int(0, 150000);
                \GlpiPlugin\Gdmsintegration\Utils::debug('API transient error — retry ' . ($attempt + 1) . "/{$attempts} | HTTP:{$code}");
                usleep($waitUs);
                continue;
            }
            if ($err || $code >= 400) {
                self::logStructuredApiError(str_contains($url,'/oapi')?'GDMS':'GWN',$url,$code,$err,$raw,$retryable);
                return false;
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                \GlpiPlugin\Gdmsintegration\Utils::debug('API — non-JSON response from ' . self::redactUrl($url) . " | HTTP:{$code} | raw:" . substr($raw, 0, 300));
                return false;
            }
            return $decoded;
        }
        return false;
    }

    
    private static function logStructuredApiError(string $provider,string $endpoint,int $httpCode,string $curlError,string $raw,bool $retryable): void {
        $decoded=json_decode($raw,true);
        $retCode=is_array($decoded)?($decoded['retCode']??'N/A'):'N/A';
        $retMsg=is_array($decoded)?($decoded['retMsg']??($decoded['msg']??'N/A')):'N/A';
        $cat='UNKNOWN';
        if($httpCode===401){$cat='AUTHENTICATION';}
        elseif($httpCode===403){$cat='PERMISSION';}
        elseif($httpCode===404){$cat='NOT_FOUND';}
        elseif(in_array($httpCode,[429],true)){$cat='RATE_LIMIT';}
        elseif(in_array($httpCode,[500,502,503,504],true)){$cat='SERVER_ERROR';}
        elseif($curlError!==''){$cat='NETWORK_ERROR';}
        \GlpiPlugin\Gdmsintegration\Utils::log(
            "==== {$provider} API ERROR ====".
            "\nCategory      : {$cat}".
            "\nEndpoint      : {$endpoint}".
            "\nHTTP          : {$httpCode}".
            "\nretCode       : {$retCode}".
            "\nretMsg        : {$retMsg}".
            "\nRetryable     : ".($retryable?'YES':'NO').
            "\nURL           : ".self::redactUrl($endpoint).
            ($curlError!==''?"\ncURL          : {$curlError}":"")
        );
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

    private static function persistGdmsToken(array $config, array $tokenData): void {
        global $DB;
        $entity = (int)($config['entities_id'] ?? 0);
        if ($entity < 0) return;
        $expiresAt = time() + max(60, (int)($tokenData['expires_in'] ?? 3600));
        try {
            $DB->update(\GlpiPlugin\Gdmsintegration\Config::getTable(), [
                'gdms_access_token'     => \GlpiPlugin\Gdmsintegration\Utils::encrypt((string)$tokenData['access_token']),
                'gdms_refresh_token'    => !empty($tokenData['refresh_token']) ? \GlpiPlugin\Gdmsintegration\Utils::encrypt((string)$tokenData['refresh_token']) : '',
                'gdms_token_expires_at' => $expiresAt,
            ], ['entities_id' => $entity]);
        } catch (\Throwable $e) {
            \GlpiPlugin\Gdmsintegration\Utils::debug('GDMS token cache persistence unavailable: ' . $e->getMessage());
        }
    }

    public static function gdmsGetToken(array $config, bool $forceRefresh = false): array|false {
        $cacheKey = strtolower((string)($config['gdms_region'] ?? 'us')) . '|' . ($config['username'] ?? '') . '|' . ($config['client_id'] ?? '');
        $now = time();
        if (!$forceRefresh && isset(self::$gdmsTokenCache[$cacheKey])) {
            [$cachedData, $expiresAt] = self::$gdmsTokenCache[$cacheKey];
            if ($now < $expiresAt) return $cachedData;
        }

        // Reuse the encrypted persistent token across PHP/cron processes. Keep a 60s
        // safety window so a token is not selected when it is about to expire.
        if (!$forceRefresh && !empty($config['gdms_access_token'])
            && (int)($config['gdms_token_expires_at'] ?? 0) > $now + 60) {
            $ttl = (int)$config['gdms_token_expires_at'] - $now;
            $tokenData = [
                'access_token' => (string)$config['gdms_access_token'],
                'refresh_token' => (string)($config['gdms_refresh_token'] ?? ''),
                'expires_in' => $ttl,
            ];
            self::$gdmsTokenCache[$cacheKey] = [$tokenData, $now + $ttl - 30];
            return $tokenData;
        }

        $data = false;
        // Prefer the API-issued refresh token. Password credentials are only used when
        // no refresh token is available or the refresh was rejected.
        if (!empty($config['gdms_refresh_token'])) {
            $data = self::curl(
                self::gdmsBase($config) . '/oauth/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                http_build_query([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $config['gdms_refresh_token'],
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                ]),
                true
            );
            if ($data === false || empty($data['access_token'])) {
                \GlpiPlugin\Gdmsintegration\Utils::debug('GDMS refresh token rejected/unavailable — falling back to password grant.');
                $data = false;
            }
        }

        if ($data === false) {
            $hashedPw = hash('sha256', md5($config['password']));
            $data = self::curl(
                self::gdmsBase($config) . '/oauth/token',
                ['Content-Type: application/x-www-form-urlencoded'],
                http_build_query([
                    'username' => $config['username'],
                    'password' => $hashedPw,
                    'grant_type' => 'password',
                    'client_id' => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                ]),
                true
            );
        }
        if ($data === false || empty($data['access_token'])) {
            \GlpiPlugin\Gdmsintegration\Utils::log('GDMS token ERROR — region:' . ($config['gdms_region'] ?? 'us') . ' username:' . ($config['username'] ?? ''));
            return false;
        }
        $expiresIn = max(60, (int)($data['expires_in'] ?? 3600));
        $tokenData = [
            'access_token' => $data['access_token'],
            // Some OAuth servers rotate refresh tokens; preserve the previous one when omitted.
            'refresh_token' => $data['refresh_token'] ?? ($config['gdms_refresh_token'] ?? ''),
            'expires_in' => $expiresIn,
        ];
        self::$gdmsTokenCache[$cacheKey] = [$tokenData, $now + $expiresIn - 30];
        self::persistGdmsToken($config, $tokenData);
        \GlpiPlugin\Gdmsintegration\Utils::debug("GDMS token OK — region:" . ($config['gdms_region'] ?? 'us') . " expires:{$expiresIn}s");
        return $tokenData;
    }

    // -----------------------------------------------------------------------
    // GDMS — device list (POST + JSON body + signature)
    // URL query: access_token, timestamp, signature
    // Body: pageNum, pageSize, orgId (optional)
    // -----------------------------------------------------------------------
    public static function gdmsGetDevices(array $config, ?bool &$complete = null): array {
        // Backward-compatible return type: callers still receive the device array.
        // $complete tells sync callers whether the list is authoritative enough to
        // infer removals. Any transport/API error or pagination ceiling makes it false.
        $complete = false;
        $page = 1;
        $all  = [];
        $lastBatch = [];
        do {
            $ts   = (int) (microtime(true) * 1000);
            $body = json_encode(['pageNum' => $page, 'pageSize' => 100]);

            // URL query params (only access_token goes in URL for this endpoint)
            $urlParams = ['access_token' => $config['access_token']];
            $sig = self::gdmsBuildSignature($urlParams, $config, $ts, $body);

            $url = self::gdmsBase($config) . '/' . self::VERSION . '/device/list?'
                 . http_build_query([
                     'access_token' => $config['access_token'],
                     'timestamp'    => $ts,
                     'signature'    => $sig,
                 ]);

            $data = self::curl(
                $url,
                ['Content-Type: application/json'],
                $body,
                true
            );

            if ($data === false) {
                // A rejected/expired token is indistinguishable from another HTTP error at
                // this layer. Transient 429/5xx have already been retried by curl(), so do
                // one controlled token renewal and repeat this safe list request once.
                $renewed = self::gdmsGetToken($config, true);
                if ($renewed !== false) {
                    $config['access_token'] = $renewed['access_token'];
                    $ts = (int)(microtime(true) * 1000);
                    $urlParams = ['access_token' => $config['access_token']];
                    $sig = self::gdmsBuildSignature($urlParams, $config, $ts, $body);
                    $url = self::gdmsBase($config) . '/' . self::VERSION . '/device/list?' . http_build_query([
                        'access_token' => $config['access_token'], 'timestamp' => $ts, 'signature' => $sig,
                    ]);
                    $data = self::curl($url, ['Content-Type: application/json'], $body, true);
                }
                if ($data === false) {
                    \GlpiPlugin\Gdmsintegration\Utils::log("GDMS device/list incomplete — API error on page {$page}; removal detection disabled for this cycle.");
                    return $all;
                }
            }
            if ((int)($data['retCode'] ?? -1) !== 0) {
                \GlpiPlugin\Gdmsintegration\Utils::log("GDMS device/list error on page {$page}: " . ($data['msg'] ?? json_encode($data)) . "; removal detection disabled for this cycle.");
                return $all;
            }
            $batch = $data['data']['result'] ?? [];
            $batch = is_array($batch) ? $batch : [];
            \GlpiPlugin\Gdmsintegration\Utils::log("GDMS page {$page}: " . count($batch) . " device(s)");
            if ($page === 1 && !empty($batch)) {
                \GlpiPlugin\Gdmsintegration\Utils::debug("GDMS device/list fields (first device): " . json_encode(array_keys($batch[0])));
                \GlpiPlugin\Gdmsintegration\Utils::debug("GDMS device/list sample: " . json_encode($batch[0]));
            }
            $all       = array_merge($all, $batch);
            $lastBatch = $batch;

            // An empty/short page is an authoritative end of pagination.
            if (count($batch) < 100) {
                $complete = true;
                return $all;
            }
            $page++;
        } while ($page <= self::MAX_PAGES);

        // Reaching MAX_PAGES with a full final page is ambiguous: there may be
        // additional devices. Keep the partial data for normal updates, but never
        // use it to infer that unseen devices were removed.
        if (count($lastBatch) >= 100) {
            \GlpiPlugin\Gdmsintegration\Utils::log(
                'GDMS WARNING: device list reached the pagination safety ceiling at page ' . self::MAX_PAGES
                . ' (~' . (self::MAX_PAGES * 100) . ' devices). Partial data will be synchronized, but removal detection is disabled for this cycle.'
            );
            return $all;
        }

        $complete = true;
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

    public static function gwnGetToken(array $config, bool $forceRefresh = false): string|false {
        $cacheKey = ($config['gwn_client_id'] ?? '') . '|' . ($config['gwn_client_secret'] ?? '');
        $now      = time();
        if (!$forceRefresh && isset(self::$gwnTokenCache[$cacheKey])) {
            [$cachedToken, $expiresAt] = self::$gwnTokenCache[$cacheKey];
            if ($now < $expiresAt) {
                \GlpiPlugin\Gdmsintegration\Utils::debug("GWN token OK (cached) — appID:{$config['gwn_client_id']}");
                return $cachedToken;
            }
        }
        // Reuse the encrypted persistent token across PHP requests. The 60-second
        // margin avoids signing a request with a token that expires in flight.
        if (!$forceRefresh && !empty($config['gwn_access_token'])
            && (int)($config['gwn_token_expires_at'] ?? 0) > $now + 60) {
            $expiresAt = (int)$config['gwn_token_expires_at'];
            self::$gwnTokenCache[$cacheKey] = [(string)$config['gwn_access_token'], $expiresAt - 30];
            \GlpiPlugin\Gdmsintegration\Utils::debug("GWN token OK (persistent cache) — appID:{$config['gwn_client_id']}");
            return (string)$config['gwn_access_token'];
        }
        $url = self::GWN_BASE . '/oauth/token?' . http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $config['gwn_client_id'],
            'client_secret' => $config['gwn_client_secret'],
        ]);
        \GlpiPlugin\Gdmsintegration\Utils::debug("GWN token request — appID:{$config['gwn_client_id']} [credentials redacted]");
        $data = self::curl($url, [], null, true);
        \GlpiPlugin\Gdmsintegration\Utils::debug("GWN token response — expires_in:" . ($data['expires_in'] ?? '?'));
        if ($data === false || empty($data['access_token'])) {
            \GlpiPlugin\Gdmsintegration\Utils::log("GWN token ERROR — appID:{$config['gwn_client_id']}");
            return false;
        }
        $expiresIn = (int)($data['expires_in'] ?? 3599);
        $expiresAt = $now + max(60, $expiresIn);
        self::$gwnTokenCache[$cacheKey] = [$data['access_token'], $expiresAt - 30];
        // Persist encrypted just like GDMS OAuth so dashboard/AJAX/cron requests do
        // not each consume a new client_credentials token.
        if (!empty($config['entities_id'])) {
            global $DB;
            $DB->update(\GlpiPlugin\Gdmsintegration\Config::getTable(), [
                'gwn_access_token'     => \GlpiPlugin\Gdmsintegration\Utils::encrypt((string)$data['access_token']),
                'gwn_token_expires_at' => $expiresAt,
            ], ['entities_id' => (int)$config['entities_id']]);
        } elseif (array_key_exists('entities_id', $config)) {
            global $DB;
            $DB->update(\GlpiPlugin\Gdmsintegration\Config::getTable(), [
                'gwn_access_token'     => \GlpiPlugin\Gdmsintegration\Utils::encrypt((string)$data['access_token']),
                'gwn_token_expires_at' => $expiresAt,
            ], ['entities_id' => 0]);
        }
        \GlpiPlugin\Gdmsintegration\Utils::debug("GWN token OK — appID:{$config['gwn_client_id']}");
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
    private static function gwnGetNetworkIds(array $config, ?bool &$complete = null): array|false {
        $complete = false;
        $map = [];
        $pageSize = 200;
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = json_encode(['pageNum' => $page, 'pageSize' => $pageSize]);
            $url  = self::gwnSignedUrl('/network/list', $config, $body);
            $data = self::curl($url, ['Content-Type: application/json'], $body, true);
            if ($data === false) {
                $newToken = self::gwnGetToken($config, true);
                if ($newToken !== false) {
                    $config['gwn_access_token'] = $newToken;
                    $url = self::gwnSignedUrl('/network/list', $config, $body);
                    $data = self::curl($url, ['Content-Type: application/json'], $body, true);
                }
            }
            if ($data === false || (int)($data['retCode'] ?? -1) !== 0) {
                \GlpiPlugin\Gdmsintegration\Utils::log("GWN network/list error page {$page}: " . ($data['msg'] ?? 'transport/API error'));
                return $map === [] ? false : $map;
            }
            $networks = $data['data']['result'] ?? [];
            if (!is_array($networks)) return false;
            foreach ($networks as $nw) {
                $id = (string)($nw['id'] ?? '');
                if ($id !== '') $map[$id] = $nw['networkName'] ?? '';
            }
            $meta = $data['data'] ?? [];
            $totalPage = (int)($meta['totalPage'] ?? 0);
            $currentPage = (int)($meta['currentPage'] ?? $page);
            if (($totalPage > 0 && $currentPage >= $totalPage) || count($networks) < $pageSize) {
                $complete = true;
                return $map;
            }
        }
        \GlpiPlugin\Gdmsintegration\Utils::log('GWN network/list reached pagination safety ceiling; list is partial.');
        return $map;
    }

    // -----------------------------------------------------------------------
    // GWN — device/info for a single device (returns sn, ip, model, etc.)
    // -----------------------------------------------------------------------
    private static function gwnGetDeviceInfo(array $config, string $mac, string $networkId): ?array {
        $body = json_encode(['mac' => $mac, 'networkId' => (int)$networkId]);
        $url  = self::gwnSignedUrl('/device/info', $config, $body);
        \GlpiPlugin\Gdmsintegration\Utils::debug("GWN device/info {$mac} URL: {$url}");
        \GlpiPlugin\Gdmsintegration\Utils::debug("GWN device/info {$mac} body: {$body}");
        $data = self::curl($url, ['Content-Type: application/json'], $body, true);
        \GlpiPlugin\Gdmsintegration\Utils::debug("GWN device/info {$mac} response: " . substr(json_encode($data), 0, 500));
        if ($data === false || (int)($data['retCode'] ?? -1) !== 0) {
            \GlpiPlugin\Gdmsintegration\Utils::debug("GWN device/info {$mac} error: " . ($data['msg'] ?? json_encode($data)));
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
        \GlpiPlugin\Gdmsintegration\Utils::debug("GWN device/info {$mac} SN:'{$sn}'");
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
    public static function gwnGetDevices(array $config, ?bool &$complete = null): array|false {
        $complete = false;
        $networkListComplete = false;
        $networkMap = self::gwnGetNetworkIds($config, $networkListComplete); // id => name, or false on error
        if ($networkMap === false) {
            \GlpiPlugin\Gdmsintegration\Utils::log("GWN: network/list failed — aborting device fetch");
            return false;
        }
        if (empty($networkMap)) {
            $complete = $networkListComplete;
            \GlpiPlugin\Gdmsintegration\Utils::debug("GWN: no networks configured — skipping devices");
            return [];
        }
        // Pre-fetch token once — reuse for all page requests and info batches
        $token = self::gwnGetToken($config) ?: '';

        // Pre-load SN cache for all managed MACs — avoids one DB query per device below.
        $_snCache = [];
        foreach ((new \GlpiPlugin\Gdmsintegration\Device())->find(['entities_id' => (int)($config['entities_id'] ?? 0)]) as $_row) {
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
                \GlpiPlugin\Gdmsintegration\Utils::debug("GWN ap/list URL: {$url}");
                \GlpiPlugin\Gdmsintegration\Utils::debug("GWN ap/list body: {$body}");
                $data = self::curl($url, ['Content-Type: application/json'], $body, true);
                if ($data === false) {
                    $newToken = self::gwnGetToken($config, true);
                    if ($newToken !== false) {
                        $config['gwn_access_token'] = $newToken;
                        $token = $newToken;
                        $url = self::gwnSignedUrl('/ap/list', $config, $body);
                        $data = self::curl($url, ['Content-Type: application/json'], $body, true);
                    }
                }
                \GlpiPlugin\Gdmsintegration\Utils::debug("GWN ap/list network {$networkId} page {$page} response: " . json_encode($data));
                if ($data === false || (int)($data['retCode'] ?? -1) !== 0) {
                    \GlpiPlugin\Gdmsintegration\Utils::log("GWN ap/list error network {$networkId}: " . ($data['msg'] ?? ''));
                    $hadNetworkError = true;
                    break;
                }
                $raw   = $data['data']['result'] ?? $data['data'] ?? [];
                $batch = is_array($raw) ? $raw : [];
                \GlpiPlugin\Gdmsintegration\Utils::log("GWN network {$networkId} page {$page}: " . count($batch) . " device(s)");
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
                                \GlpiPlugin\Gdmsintegration\Utils::debug("GWN SN cached for {$devMac}: {$_snCache[$devMac]}");
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
                $meta = $data['data'] ?? [];
                $totalPage = (int)($meta['totalPage'] ?? 0);
                $currentPage = (int)($meta['currentPage'] ?? $page);
                $isLastPage = ($totalPage > 0 && $currentPage >= $totalPage) || count($batch) < 100;
                $page++;
            } while (!$isLastPage && !empty($batch) && $page <= self::MAX_PAGES);
            if (!$isLastPage && !empty($batch) && count($batch) >= 100 && $page > self::MAX_PAGES) {
                \GlpiPlugin\Gdmsintegration\Utils::log("GWN ap/list network {$networkId} reached pagination safety ceiling; list is partial.");
                $hadNetworkError = true;
            }
        }
        if ($hadNetworkError) {
            $complete = false;
            return empty($all) ? false : $all;
        }
        $complete = $networkListComplete;
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
        if (count($devices) > self::INFO_BATCH_SIZE) {
            $merged = [];
            foreach (array_chunk($devices, self::INFO_BATCH_SIZE, true) as $chunk) {
                $merged += self::gwnGetDeviceInfoBatch($config, $chunk, $networkId, $cachedToken);
            }
            return $merged;
        }
        $token   = $cachedToken ?: self::gwnGetToken($config);
        if (!$token) return [];
        $appId   = $config['gwn_client_id']     ?? '';
        $secret  = $config['gwn_client_secret'] ?? '';
        $baseUrl = self::GWN_BASE . '/oapi/' . self::VERSION . '/device/info';

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
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            $data = $raw ? json_decode($raw, true) : null;
            $info = null;
            if ($curlErr !== '' || $httpCode >= 400 || !is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
                // Fall back through the hardened single-request path. It provides
                // bounded retry/backoff and keeps this enrichment non-destructive.
                $mac = (string)($devices[$idx]['mac'] ?? '');
                $info = $mac !== '' ? self::gwnGetDeviceInfo($config, $mac, (string)$networkId) : null;
                $data = null;
            }
            if ($info === null && is_array($data)) {
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
            \GlpiPlugin\Gdmsintegration\Utils::log(
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
    public static function gwnGetClientList(array $config, int $networkId, string $mac = '', int $pageSize = 200, ?bool &$complete = null): array {
        $complete = false;
        $token = self::gwnGetToken($config);
        if (!$token) return [];
        $config['gwn_access_token'] = $token;
        $pageSize = max(1, min(200, $pageSize));
        $all = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = ['networkId' => $networkId, 'pageNum' => $page, 'pageSize' => $pageSize];
            if ($mac !== '') $body['mac'] = $mac;
            $bodyStr = json_encode($body);
            $url = self::gwnSignedUrl('/client/list', $config, $bodyStr);
            $data = self::curl($url, ['Content-Type: application/json'], $bodyStr, true);
            if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
                \GlpiPlugin\Gdmsintegration\Utils::debug("GWN client/list error network {$networkId} page {$page}: " . ($data['msg'] ?? 'transport/API error'));
                break;
            }
            $raw = $data['data']['result'] ?? $data['data'] ?? [];
            $batch = is_array($raw) ? $raw : [];
            $all = array_merge($all, $batch);
            if (count($batch) < $pageSize) {
                $complete = true;
                break;
            }
            if ($page === self::MAX_PAGES) {
                \GlpiPlugin\Gdmsintegration\Utils::log("GWN client/list truncated at safety limit for network {$networkId}; result is partial.");
            }
        }
        return $all;
    }

    /**
     * Get recent cloud alerts for a network.
     * POST /oapi/v1.0.0/alert/list {networkId, pageNum, pageSize}
     * Returns normalized: [{id, alertType, severity, deviceMac, deviceName, description, createTime}]
     */
    public static function gwnGetAlerts(array $config, int $networkId, int $pageSize = 50, ?bool &$complete = null): array {
        $complete = false;
        $token = self::gwnGetToken($config);
        if (!$token) return [];
        $config['gwn_access_token'] = $token;
        $pageSize = max(1, min(200, $pageSize));
        $raw = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = json_encode(['networkId' => $networkId, 'pageNum' => $page, 'pageSize' => $pageSize]);
            $url = self::gwnSignedUrl('/alert/list', $config, $body);
            $data = self::curl($url, ['Content-Type: application/json'], $body, true);
            if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
                \GlpiPlugin\Gdmsintegration\Utils::log("GWN alert/list error network {$networkId} page {$page}: " . ($data['msg'] ?? 'transport/API error'));
                break;
            }
            $part = $data['data']['result'] ?? $data['data']['list'] ?? $data['data'] ?? [];
            $batch = is_array($part) ? $part : [];
            $raw = array_merge($raw, $batch);
            if (count($batch) < $pageSize) {
                $complete = true;
                break;
            }
            if ($page === self::MAX_PAGES) {
                \GlpiPlugin\Gdmsintegration\Utils::log("GWN alert/list truncated at safety limit for network {$networkId}; result is partial.");
            }
        }
        // Log raw structure once per call to help identify field names
        if (!empty($raw)) {
            \GlpiPlugin\Gdmsintegration\Utils::debug("GWN alert sample (network {$networkId}): " . json_encode($raw[0]));
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

        $data = self::curl($url, ['Content-Type: application/json'], $body, true);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            \GlpiPlugin\Gdmsintegration\Utils::log("GWN switch/portInfo error for {$mac}: " . ($data['msg'] ?? json_encode($data)));
            return [];
        }
        $raw = $data['data']['result'] ?? [];
        \GlpiPlugin\Gdmsintegration\Utils::debug("GWN switch/portInfo {$mac}: " . json_encode($raw));
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
        $url  = self::GWN_BASE . '/oapi/' . self::VERSION . '/device/info'
                . '?access_token=' . urlencode($token)
                . '&appID='        . urlencode($appId)
                . "&timestamp={$ts}&signature={$sig}";

        $data = self::curl($url, ['Content-Type: application/json'], $body, true);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            \GlpiPlugin\Gdmsintegration\Utils::log("GWN device/info port error for {$mac}: " . ($data['msg'] ?? json_encode($data)));
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

        \GlpiPlugin\Gdmsintegration\Utils::debug("GWN portInfo for {$mac}: " . count(is_array($info['portInfo'] ?? null) ? $info['portInfo'] : []) . " port(s); WAN details parsed from embedded ipv4Info");
        return [
            'portInfo'  => $info['portInfo']  ?? [],
            'ipv4Info'  => $info['ipv4Info']  ?? [],
            'raw_info'  => $info,
        ];
    }

        /**
     * Determines whether a GWN firmware row/version is a pre-release.
     *
     * Grandstream may expose more than one channel in upgrade/version. The
     * plugin must never treat beta/RC/alpha/dev/test firmware as the official
     * firmware used for compliance or the actionable upgrade badge.
     */
    private static function gwnFirmwareIsPreRelease(array $row, string $version): bool {
        foreach (['isBeta', 'beta', 'isRC', 'isRc', 'isAlpha', 'isDev', 'isPreview', 'isTest'] as $key) {
            if (array_key_exists($key, $row)) {
                $value = $row[$key];
                if ($value === true || $value === 1 || $value === '1' || (is_string($value) && preg_match('/^(yes|true|beta|rc|alpha|dev|preview|test)$/i', trim($value)))) {
                    return true;
                }
            }
        }

        foreach (['releaseType', 'versionType', 'channel', 'firmwareType', 'type', 'status', 'releaseChannel'] as $key) {
            if (isset($row[$key]) && is_scalar($row[$key]) && preg_match('/beta|rc|alpha|dev|preview|test/i', (string)$row[$key])) {
                return true;
            }
        }

        return $version !== '' && preg_match('/(?:^|[._\- ])(?:beta|rc|alpha|dev|preview|test)(?:$|[._\- ]|[0-9])/i', $version) === 1;
    }

    /**
     * Extracts the firmware version exposed by an upgrade/version row.
     */
    private static function gwnFirmwareVersion(array $row): string {
        foreach (['lastVersion', 'version', 'firmwareVersion', 'versionFirmware'] as $key) {
            if (isset($row[$key]) && is_scalar($row[$key])) {
                $value = trim((string)$row[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return '';
    }

    /**
     * Adds stable/beta metadata without changing the original API response fields.
     * stableVersion is the highest official/non-pre-release version for the device.
     * Pre-release channels are intentionally not exposed as actionable firmware.
     */
    private static function gwnAnnotateFirmwareRows(array $rows): array {
        $byMac = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $mac = strtolower(str_replace(['-', ' '], ':', trim((string)($row['mac'] ?? ''))));
            if ($mac === '') {
                continue;
            }
            $version = self::gwnFirmwareVersion($row);
            if ($version === '') {
                continue;
            }
            // Beta/RC/alpha/dev/test rows are intentionally ignored.
            // This endpoint is used only to determine the official/actionable firmware.
            // Grandstream may return multiple release channels in the same response;
            // pre-release rows must never become a compliance baseline or update badge.
            if (self::gwnFirmwareIsPreRelease($row, $version)) {
                continue;
            }
            if (!isset($byMac[$mac])) {
                $byMac[$mac] = ['stable' => ''];
            }
            if ($byMac[$mac]['stable'] === '' || version_compare($version, $byMac[$mac]['stable'], '>')) {
                $byMac[$mac]['stable'] = $version;
            }
        }

        foreach ($rows as &$row) {
            $mac = strtolower(str_replace(['-', ' '], ':', trim((string)($row['mac'] ?? ''))));
            $row['stableVersion'] = $byMac[$mac]['stable'] ?? '';
            $row['betaVersion'] = ''; // pre-release firmware is never exposed as actionable
        }
        unset($row);
        return $rows;
    }

    /**
     * Parallel firmware version fetch for multiple networks via curl_multi.
     * Returns [networkId => [firmware rows]]
     */
    private static array $lastFirmwareErrors = [];

    public static function gwnGetFirmwareVersionsBatch(array $config, array $networkIds): array {
        self::$lastFirmwareErrors = [];
        $token = self::gwnGetToken($config);
        if (!$token || empty($networkIds)) return [];
        $config['gwn_access_token'] = $token;
        $results = [];
        foreach (array_values(array_unique(array_map('intval', $networkIds))) as $nid) {
            $body = json_encode(['networkId' => $nid]);
            $url = self::gwnSignedUrl('/upgrade/version', $config, $body);
            $data = self::curl($url, ['Content-Type: application/json'], $body, true);
            if ($data === false) {
                $newToken = self::gwnGetToken($config, true);
                if ($newToken !== false) {
                    $config['gwn_access_token'] = $newToken;
                    $url = self::gwnSignedUrl('/upgrade/version', $config, $body);
                    $data = self::curl($url, ['Content-Type: application/json'], $body, true);
                }
            }
            if (is_array($data) && (int)($data['retCode'] ?? -1) === 0) {
                $r = $data['data']['result'] ?? [];
                $results[$nid] = is_array($r) ? self::gwnAnnotateFirmwareRows($r) : [];
            } else {
                $msg = (string)($data['msg'] ?? 'transport/API error');
                self::$lastFirmwareErrors[$nid] = $msg;
                \GlpiPlugin\Gdmsintegration\Utils::log("GWN upgrade/version error network {$nid}: {$msg}");
                // Preserve the public return shape for compatibility. Callers that need
                // to distinguish API failure from an empty version list can inspect
                // gwnGetLastFirmwareErrors().
                $results[$nid] = [];
            }
        }
        return $results;
    }

    public static function gwnGetLastFirmwareErrors(): array {
        return self::$lastFirmwareErrors;
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
        $url = self::GWN_BASE . '/oapi/' . self::VERSION . '/upgrade/add'
               . '?access_token=' . urlencode($token)
               . '&appID='        . urlencode($appId)
               . "&timestamp={$ts}&signature={$sig}";

        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || (int)($data['retCode'] ?? -1) !== 0) {
            $err = $data['msg'] ?? json_encode($data);
            \GlpiPlugin\Gdmsintegration\Utils::log("GWN upgrade/add error: {$err}");
            return ['error' => $err];
        }
        $ok = $data['data']['success_upgrade_macs'] ?? [];
        $mode = $scheduleTimeMs > 0 ? "scheduled @{$scheduleTimeMs}" : "ASAP";
        \GlpiPlugin\Gdmsintegration\Utils::log("GWN upgrade ({$mode}) for MACs: " . implode(', ', (array)$ok));
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

        $url = self::gdmsBase($config) . '/v1.0.0/task/add'
             . '?access_token=' . urlencode($token)
             . '&signature='    . $sig
             . "&timestamp={$ts}";

        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || ($data['retCode'] ?? -1) != 0) {
            $err = $data['msg'] ?? json_encode($data);
            \GlpiPlugin\Gdmsintegration\Utils::log("GDMS task/add UPGRADE error for {$mac}: {$err}");
            return ['error' => $err];
        }
        $mode = $scheduleMs > 0 ? "scheduled @{$scheduleMs}" : "ASAP";
        \GlpiPlugin\Gdmsintegration\Utils::log("GDMS upgrade task created ({$mode}) for {$mac} v{$version}");
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

        $url = self::gdmsBase($config) . '/v1.0.0/task/add'
             . '?access_token=' . urlencode($token)
             . '&signature='    . $sig
             . "&timestamp={$ts}";

        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || ($data['retCode'] ?? -1) != 0) {
            $err = $data['msg'] ?? json_encode($data);
            \GlpiPlugin\Gdmsintegration\Utils::log("GDMS task/add REBOOT error for {$mac}: {$err}");
            return ['error' => $err];
        }
        \GlpiPlugin\Gdmsintegration\Utils::log("GDMS reboot task created for {$mac}");
        return ['success' => true, 'mac' => $mac];
    }

    // GDMS — Factory reset task for UC devices
    // POST /oapi/v1.0.0/task/add  taskType=2
    // -----------------------------------------------------------------------
    public static function gdmsCreateFactoryResetTask(array $config, string $mac): array {
        $tokenData = self::gdmsGetToken($config);
        if (!$tokenData) return ['error' => 'Cannot obtain GDMS token'];

        $token    = $tokenData['access_token'] ?? '';
        $clientId = $tokenData['client_id']    ?? ($config['client_id'] ?? '');
        $secret   = $config['client_secret'] ?? '';

        $glpiVer = 'GLPI' . ((int) explode('.', GLPI_VERSION)[0]);
        $ts_r    = (int)(microtime(true) * 1000);
        $payload = [
            'taskName' => $glpiVer . '_RST_' . strtoupper(str_replace(':', '', $mac)) . '_' . $ts_r,
            'taskType' => 2,
            'macList'  => [$mac],
            'execType' => 1,
        ];

        $body = json_encode($payload);
        $ts   = $ts_r;
        $sig  = self::gdmsBuildTaskSignature($token, $clientId, $secret, $ts, $body);

        $url = self::gdmsBase($config) . '/v1.0.0/task/add'
             . '?access_token=' . urlencode($token)
             . '&signature='    . $sig
             . "&timestamp={$ts}";

        $data = self::curl($url, ['Content-Type: application/json'], $body);
        if (!is_array($data) || ($data['retCode'] ?? -1) != 0) {
            $err = $data['msg'] ?? json_encode($data);
            \GlpiPlugin\Gdmsintegration\Utils::log("GDMS task/add FACTORY RESET error for {$mac}: {$err}");
            return ['error' => $err];
        }
        \GlpiPlugin\Gdmsintegration\Utils::log("GDMS factory reset task created for {$mac}");
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
            if (preg_match('/href=["\']([^"\']+\.bin)["\']/', $html, $um)) {
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
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; GDMSintegration/1.0)',
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
