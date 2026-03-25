<?php
/**
 * GDMS Integration — PluginGdmsintegrationAPI
 *
 * Implements the official GDMS Open API v1.0.0
 * Docs: https://doc.grandstream.dev/GDMS-API/EN/
 *
 * Authentication flow:
 *   1. GET /oapi/oauth/token  (password grant, password=SHA256(MD5(plaintext)))
 *      → access_token  (use in every request)
 *      → refresh_token (renew when access_token expires, ~1 h)
 *
 * Every subsequent request needs three extra query-string params:
 *   access_token, timestamp (ms), signature (SHA256 of sorted params)
 *
 * Signature calculation:
 *   params = all URL query params
 *           + access_token + timestamp
 *           + client_id + client_secret      ← added for signing ONLY
 *   Sort keys ascending (same letter: uppercase before lowercase)
 *   Join as  key=value&key=value…
 *   SHA256 the joined string  → signature
 *
 * Device list response fields (same endpoint for ALL device types):
 *   deviceName   string   Name set in GDMS portal
 *   deviceType   string   Model string (GWN7630, GRP2612, UCM6202, …)
 *   mac          string   MAC address
 *   sn           string   Serial number
 *   status       int      1=online, 0=offline, -1=abnormal
 *   publicIp     string
 *   privateip    string
 *   firmwareVersion string
 *   siteId / siteName
 */
class PluginGdmsintegrationAPI {

    private const BASE_URL   = 'https://www.gdms.cloud/oapi';
    private const VERSION    = 'v1.0.0';
    private const TIMEOUT    = 20;
    private const MAX_PAGES  = 50;

    // -----------------------------------------------------------------------
    // Device type classification → GLPI itemtype
    // -----------------------------------------------------------------------

    /** Model prefixes that map to GLPI Phone */
    private const PHONE_PREFIXES = [
        'GRP', 'GXP', 'GXV', 'GXW', 'WP', 'HT', 'DP',
        'GHP', 'GVC', 'GSC', 'GDS',
    ];

    /** Model prefixes that map to GLPI NetworkEquipment (PBX/appliance) */
    private const PBX_PREFIXES = [
        'UCM', 'GCC', 'CLOUDUCM', 'SOFTWAREUCM',
    ];

    /**
     * Classify a GDMS model string into a GLPI itemtype.
     * Returns 'Phone', 'NetworkEquipment', or null (unknown / networking GWN).
     */
    public static function classifyModel(string $model): ?string {
        $upper = strtoupper(trim($model));
        foreach (self::PHONE_PREFIXES as $p) {
            if (str_starts_with($upper, $p)) {
                return 'Phone';
            }
        }
        foreach (self::PBX_PREFIXES as $p) {
            if (str_starts_with($upper, $p)) {
                return 'NetworkEquipment';
            }
        }
        return null; // GWN series → NetworkEquipment by default
    }

    // -----------------------------------------------------------------------
    // Internal HTTP helper
    // -----------------------------------------------------------------------
    private static function curl(
        string $url,
        array  $headers = [],
        ?array $post    = null,
        bool   $form    = false
    ): array|false {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($form) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
            }
        }

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err || $code >= 400) {
            PluginGdmsintegrationUtils::log(
                "GDMS API ERROR — URL: {$url} | cURL: {$err} | HTTP: {$code}"
            );
            return false;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            PluginGdmsintegrationUtils::log("GDMS API — invalid JSON from {$url}");
            return false;
        }

        return $decoded;
    }

    // -----------------------------------------------------------------------
    // Signature calculation
    // All URL query params + access_token + timestamp + client_id + client_secret
    // sorted ascending, joined as key=value&…, then SHA256.
    // -----------------------------------------------------------------------
    private static function buildSignature(
        array  $queryParams,   // params that will appear in the URL
        string $accessToken,
        int    $timestamp,
        string $clientId,
        string $clientSecret
    ): string {
        $all = array_merge($queryParams, [
            'access_token'  => $accessToken,
            'timestamp'     => (string) $timestamp,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
        ]);

        // Sort ascending — PHP ksort is case-sensitive; normalise to lowercase
        // so uppercase keys sort before same-letter lowercase (ASCII order).
        ksort($all);

        $parts = [];
        foreach ($all as $k => $v) {
            $parts[] = "{$k}={$v}";
        }

        return hash('sha256', implode('&', $parts));
    }

    // -----------------------------------------------------------------------
    // Build a signed versioned URL
    // -----------------------------------------------------------------------
    private static function signedUrl(
        string $path,          // e.g. '/device/list'
        array  $config,        // must contain client_id, client_secret, access_token
        array  $extra = []     // additional query params (pageNum, pageSize, …)
    ): string {
        $ts        = (int) (microtime(true) * 1000);
        $params    = array_merge($extra, ['access_token' => $config['access_token']]);
        $signature = self::buildSignature(
            $params,
            $config['access_token'],
            $ts,
            $config['client_id'],
            $config['client_secret']
        );

        $params['timestamp'] = $ts;
        $params['signature'] = $signature;

        return self::BASE_URL . '/' . self::VERSION . $path . '?' . http_build_query($params);
    }

    // -----------------------------------------------------------------------
    // Public: obtain access token
    // Password must be hashed: SHA256( MD5(plaintext) )
    // -----------------------------------------------------------------------
    public static function getToken(array $config): array|false {
        // password = SHA256(MD5(plaintext))
        $hashedPw = hash('sha256', md5($config['password']));

        $data = self::curl(
            self::BASE_URL . '/oauth/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            [
                'username'      => $config['username'],
                'password'      => $hashedPw,
                'grant_type'    => 'password',
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ],
            true  // form-urlencoded
        );

        if ($data === false || empty($data['access_token'])) {
            return false;
        }

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token']  ?? '',
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
        ];
    }

    // -----------------------------------------------------------------------
    // Public: refresh an existing token (call before it expires, ~1 h)
    // -----------------------------------------------------------------------
    public static function refreshToken(array $config): array|false {
        $data = self::curl(
            self::BASE_URL . '/oauth/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $config['refresh_token'],
                'client_id'     => $config['client_id'],
                'client_secret' => $config['client_secret'],
            ],
            true
        );

        if ($data === false || empty($data['access_token'])) {
            return false;
        }

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token']  ?? '',
            'expires_in'    => (int) ($data['expires_in'] ?? 3600),
        ];
    }

    // -----------------------------------------------------------------------
    // Public: retrieve all devices (single endpoint for ALL types)
    // Returns flat array; each element has at minimum:
    //   deviceName, deviceType, mac, sn, status (1/0/-1)
    // -----------------------------------------------------------------------
    public static function getDevices(array $config): array {
        $page = 1;
        $all  = [];

        do {
            $url  = self::signedUrl('/device/list', $config, [
                'pageNum'  => $page,
                'pageSize' => 100,
            ]);
            $data = self::curl($url, ['Content-Type: application/json']);

            if ($data === false) {
                break;
            }

            if ((int) ($data['retCode'] ?? -1) !== 0) {
                PluginGdmsintegrationUtils::log(
                    "GDMS API: /device/list retCode={$data['retCode']} msg={$data['msg']}"
                );
                break;
            }

            $batch = $data['data']['result'] ?? [];
            $all   = array_merge($all, $batch);
            $page++;
        } while (!empty($batch) && $page <= self::MAX_PAGES);

        return $all;
    }
}
