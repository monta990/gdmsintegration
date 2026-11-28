<?php

namespace GlpiPlugin\Gdmsintegration\Service;

/**
 * Resolve GDMS phones/ATAs to a PBX using explicit SIP identity first and
 * same-private-subnet evidence second. WAN/public-IP equality and GDMS site
 * labels are diagnostic evidence only: neither proves that a phone is
 * registered to a particular UCM.
 */
final class PbxAssociationResolver
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>|null> keyed by normalized device MAC
     */
    public static function resolve(array $rows): array
    {
        return self::resolveDetailed($rows)['associations'];
    }

    /**
     * Return both the association result and a safe explanation for every
     * device. The diagnostics intentionally contain no SIP credentials.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array{
     *   associations:array<string, array<string, mixed>|null>,
     *   diagnostics:array<string, array<string, mixed>>,
     *   pbx_inventory:array<int, array<string, string>>
     * }
     */
    public static function resolveDetailed(array $rows): array
    {
        $pbxByMac = [];
        $pbxByPrivateIp = [];
        $pbxBySubnet = [];
        $pbxByPublicIp = [];
        $pbxByNetwork = [];
        $pbxInventory = [];

        foreach ($rows as $row) {
            if (!self::isPbx((string) ($row['raw_model'] ?? ''))) {
                continue;
            }

            $mac = self::normalizeMac((string) ($row['mac'] ?? ''));
            if ($mac === '') {
                continue;
            }

            $privateIp = self::normalizeIp((string) ($row['private_ip'] ?? ''));
            $publicIp = self::normalizeIp((string) ($row['ip'] ?? ''));
            $networkKey = self::normalizeKey((string) ($row['network_name'] ?? ''));
            $pbx = [
                'mac' => $mac,
                'name' => (string) ($row['name'] ?? ''),
                'ip' => $privateIp,
                'ip_url' => self::managementUrl($privateIp),
                'public_ip' => $publicIp,
                'sip_server' => self::safeSipServer((string) ($row['sip_server'] ?? '')),
                'url' => (string) ($row['asset_url'] ?? ''),
                'network_key' => $networkKey,
            ];

            $pbxByMac[$mac] = $pbx;
            $pbxInventory[] = [
                'name' => $pbx['name'],
                'mac' => $mac,
                'private_ip' => $privateIp,
                'public_ip' => $publicIp,
                'network_name' => (string) ($row['network_name'] ?? ''),
                'sip_server' => $pbx['sip_server'],
            ];

            $subnet = self::ipv4Subnet($privateIp);
            if ($privateIp !== '') {
                $pbxByPrivateIp[$privateIp][] = $pbx;
            }
            if ($subnet !== '') {
                $pbxBySubnet[$subnet][] = $pbx;
            }
            if ($publicIp !== '') {
                $pbxByPublicIp[$publicIp][] = $pbx;
            }
            if ($networkKey !== '') {
                $pbxByNetwork[$networkKey][] = $pbx;
            }
        }

        $associations = [];
        $diagnostics = [];

        foreach ($rows as $row) {
            $mac = self::normalizeMac((string) ($row['mac'] ?? ''));
            if ($mac === '') {
                continue;
            }

            $name = (string) ($row['name'] ?? '');
            $privateIp = self::normalizeIp((string) ($row['private_ip'] ?? ''));
            $publicIp = self::normalizeIp((string) ($row['ip'] ?? ''));
            $subnet = self::ipv4Subnet($privateIp);
            $networkKey = self::normalizeKey((string) ($row['network_name'] ?? ''));
            $sipServer = self::safeSipServer((string) ($row['sip_server'] ?? ''));
            $sipExtensionPresent = trim((string) ($row['sip_extension'] ?? '')) !== '';
            $sipServerCount = max(0, (int) ($row['sip_server_count'] ?? 0));

            $detail = [
                'device' => $name,
                'mac' => $mac,
                'private_ip' => $privateIp,
                'private_subnet' => $subnet,
                'public_ip' => $publicIp,
                'sip_server' => $sipServer,
                'sip_extension_present' => $sipExtensionPresent,
                'sip_server_count' => $sipServerCount,
                'explicit_candidates' => [],
                'sip_server_ip_candidates' => [],
                'private_subnet_candidates' => [],
                'public_ip_candidates' => [],
                'network_name_candidates' => [],
                'method' => 'none',
                'reason' => '',
            ];

            if (isset($pbxByMac[$mac])) {
                $associations[$mac] = $pbxByMac[$mac] + ['match_method' => 'self'];
                $detail['method'] = 'self';
                $detail['reason'] = 'device_is_pbx';
                $diagnostics[$mac] = $detail;
                continue;
            }

            // Multiple distinct SIP server hosts cannot be represented by a
            // single device-level PBX edge. The SIP modal resolves each line.
            if ($sipServerCount > 1) {
                $detail['reason'] = 'multiple_sip_account_servers_on_device';
                $diagnostics[$mac] = $detail;
                continue;
            }

            // A literal SIP server IP is the strongest mapping when it equals
            // a managed UCM private address.
            $sipServerIp = self::sipServerIp($sipServer);
            $sipIpCandidates = $sipServerIp !== ''
                ? self::uniqueCandidates($pbxByPrivateIp[$sipServerIp] ?? [])
                : [];
            $detail['sip_server_ip_candidates'] = array_map(self::candidateLabel(...), $sipIpCandidates);
            if (count($sipIpCandidates) === 1) {
                $chosenPbx = $sipIpCandidates[0];
                $associations[$mac] = $chosenPbx + ['match_method' => 'sip-server-ip'];
                $detail['explicit_candidates'][] = self::candidateLabel($chosenPbx);
                $detail['method'] = 'sip-server-ip';
                $detail['reason'] = 'device_account_info_sip_server_matches_managed_pbx_private_ip';
                $diagnostics[$mac] = $detail;
                continue;
            }
            $sipServerIpAmbiguous = count($sipIpCandidates) > 1;

            $explicitPbxMac = self::normalizeMac((string) ($row['pbx_mac'] ?? ''));
            if ($explicitPbxMac === '' && $sipServer !== '') {
                $explicitPbxMac = self::sipServerPbxMac($sipServer);
            }

            $explicitReferenceMissing = $sipServerCount > 1 && $sipServerIp === '' && $explicitPbxMac === '';
            if ($explicitPbxMac !== '') {
                $detail['explicit_pbx_mac'] = $explicitPbxMac;
                if (isset($pbxByMac[$explicitPbxMac])) {
                    $associations[$mac] = $pbxByMac[$explicitPbxMac] + ['match_method' => 'explicit-pbx-mac'];
                    $detail['explicit_candidates'][] = self::candidateLabel($pbxByMac[$explicitPbxMac]);
                    $detail['method'] = 'explicit-pbx-mac';
                    $detail['reason'] = 'sip_server_or_explicit_field_identifies_managed_pbx';
                    $diagnostics[$mac] = $detail;
                    continue;
                }
                $explicitReferenceMissing = true;
                $detail['reason'] = 'explicit_sip_pbx_reference_not_found_in_synced_pbx_inventory';
            } elseif ($sipServerCount > 1 && $sipServerIp === '') {
                $explicitReferenceMissing = true;
                $detail['reason'] = 'multiple_sip_account_servers_on_device';
            } elseif ($sipServer !== '' && $sipServerIp === '') {
                // A configured server hostname that is not a recognized GDMS
                // RemoteConnect/MAC reference is still authoritative evidence;
                // do not silently replace it with a same-subnet UCM guess.
                $explicitReferenceMissing = true;
                $detail['reason'] = 'sip_server_hostname_not_matched_to_managed_pbx';
            } elseif ($sipServerIp !== '' && count($sipIpCandidates) === 0) {
                $explicitReferenceMissing = true;
                $detail['reason'] = 'sip_server_ip_not_found_in_synced_pbx_inventory';
            }

            $subnetCandidates = $subnet !== ''
                ? self::uniqueCandidates($pbxBySubnet[$subnet] ?? [])
                : [];
            $detail['private_subnet_candidates'] = array_map(self::candidateLabel(...), $subnetCandidates);

            // A private subnet is useful local-network evidence. If exactly one
            // managed PBX shares it, use it as a fallback association.
            if (!$explicitReferenceMissing && !$sipServerIpAmbiguous && count($subnetCandidates) === 1) {
                $chosen = $subnetCandidates[0];
                $associations[$mac] = $chosen + ['match_method' => 'private-subnet'];
                $detail['method'] = 'private-subnet';
                $detail['reason'] = 'single_managed_pbx_on_same_private_ipv4_24';
                $diagnostics[$mac] = $detail;
                continue;
            }

            $publicCandidates = $publicIp !== ''
                ? self::uniqueCandidates($pbxByPublicIp[$publicIp] ?? [])
                : [];
            $detail['public_ip_candidates'] = array_map(self::candidateLabel(...), $publicCandidates);

            $networkCandidates = $networkKey !== ''
                ? self::uniqueCandidates($pbxByNetwork[$networkKey] ?? [])
                : [];
            $detail['network_name_candidates'] = array_map(self::candidateLabel(...), $networkCandidates);

            // Public IPs and site labels are deliberately not promoted to an
            // association. They can be shared by several PBXs, NAT devices or
            // branches and therefore cannot prove SIP registration.
            $associations[$mac] = null;
            if ($sipServerIpAmbiguous) {
                $detail['reason'] = 'sip_server_ip_matches_multiple_managed_pbxs';
            } elseif ($explicitPbxMac !== '') {
                $detail['reason'] = 'explicit_sip_pbx_reference_not_in_synced_inventory';
            } elseif ($explicitReferenceMissing) {
                $detail['reason'] = $detail['reason'] !== '' ? $detail['reason'] : 'explicit_sip_pbx_reference_not_in_synced_inventory';
            } elseif ($subnet !== '' && count($subnetCandidates) > 1) {
                $detail['reason'] = 'multiple_managed_pbxs_share_private_subnet';
            } elseif ($subnet === '' && count($publicCandidates) > 0) {
                $detail['reason'] = 'public_ip_matches_managed_pbx_but_is_not_authoritative_and_private_ip_is_missing';
            } elseif ($subnet !== '' && count($publicCandidates) > 0) {
                $detail['reason'] = 'private_subnet_has_no_pbx_match_public_ip_is_not_authoritative';
            } elseif (count($networkCandidates) > 1) {
                $detail['reason'] = 'gdms_network_name_is_shared_by_multiple_pbxs';
            } elseif ($subnet !== '') {
                $detail['reason'] = 'no_managed_pbx_found_on_device_private_subnet';
            } elseif (count($pbxInventory) === 0) {
                $detail['reason'] = 'no_pbx_devices_in_synced_inventory';
            } else {
                $detail['reason'] = 'missing_private_ip_and_no_explicit_sip_server_to_pbx_reference';
            }

            $diagnostics[$mac] = $detail;
        }

        return [
            'associations' => $associations,
            'diagnostics' => $diagnostics,
            'pbx_inventory' => $pbxInventory,
        ];
    }

    public static function normalizeMac(string $mac): string
    {
        $hex = preg_replace('/[^0-9a-f]/i', '', strtolower(trim($mac))) ?? '';
        if (strlen($hex) !== 12) {
            return '';
        }
        return implode(':', str_split($hex, 2));
    }

    /** Return a canonical host[:port] only; strip SIP URI user-info and URI parameters. */
    public static function safeSipServer(string $server): string
    {
        $server = trim($server);
        if ($server === '') {
            return '';
        }
        $server = preg_replace('/^(?:sip|sips|https?|tls|tcp|udp):/i', '', $server) ?? '';
        $server = preg_replace('/^\/\//', '', $server) ?? '';
        if (str_contains($server, '@')) {
            $server = substr($server, strrpos($server, '@') + 1);
        }
        $server = preg_split('/[;?\s]/', $server, 2)[0] ?? '';
        if ($server === '' || strlen($server) > 255) {
            return '';
        }

        // IPv6 with a port must be bracketed to be unambiguous.
        if (str_starts_with($server, '[')) {
            if (preg_match('/^\[([0-9a-f:.]+)\](?::([0-9]{1,5}))?$/i', $server, $match) !== 1
                || filter_var($match[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return '';
            }
            $packed = inet_pton($match[1]);
            $host = $packed === false ? false : inet_ntop($packed);
            if ($host === false) {
                return '';
            }
            $port = $match[2] ?? '';
            if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
                return '';
            }
            return '[' . strtolower($host) . ']' . ($port !== '' ? ':' . (int) $port : '');
        }

        if (filter_var($server, FILTER_VALIDATE_IP) !== false) {
            $packed = inet_pton($server);
            $host = $packed === false ? false : inet_ntop($packed);
            return $host === false ? '' : strtolower($host);
        }

        $host = $server;
        $port = '';
        if (substr_count($server, ':') === 1) {
            [$host, $port] = explode(':', $server, 2);
            if ($port === '' || preg_match('/^[0-9]{1,5}$/', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
                return '';
            }
        } elseif (str_contains($server, ':')) {
            return '';
        }
        if (strlen($host) > 253
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/i', $host) !== 1) {
            return '';
        }
        return strtolower($host) . ($port !== '' ? ':' . (int) $port : '');
    }

    private static function isPbx(string $model): bool
    {
        return preg_match('/^(?:UCM|GCC|CLOUDUCM|SOFTWAREUCM)/i', trim($model)) === 1;
    }

    public static function normalizeIp(string $ip): string
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $packed = inet_pton($ip);
        if ($packed === false) {
            return '';
        }
        $canonical = inet_ntop($packed);
        return $canonical === false ? '' : strtolower($canonical);
    }

    /** @param array<int, array<string, mixed>> $candidates
     *  @return array<int, array<string, mixed>>
     */
    private static function uniqueCandidates(array $candidates): array
    {
        $byMac = [];
        foreach ($candidates as $candidate) {
            $candidateMac = (string) ($candidate['mac'] ?? '');
            if ($candidateMac !== '') {
                $byMac[$candidateMac] = $candidate;
            }
        }
        return array_values($byMac);
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array{name: string, mac: string, private_ip: string, public_ip: string}
     */
    private static function candidateLabel(array $candidate): array
    {
        return [
            'name' => (string) ($candidate['name'] ?? ''),
            'mac' => (string) ($candidate['mac'] ?? ''),
            'private_ip' => (string) ($candidate['ip'] ?? ''),
            'public_ip' => (string) ($candidate['public_ip'] ?? ''),
        ];
    }

    private static function normalizeKey(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return function_exists('mb_strtolower')
            ? mb_strtolower(trim($value), 'UTF-8')
            : strtolower(trim($value));
    }

    /**
     * Resolve a SIP server address to the UCM MAC encoded by a GDMS
     * RemoteConnect hostname, when present.
     */
    public static function sipServerPbxMac(string $server): string
    {
        $server = self::safeSipServer($server);
        if ($server === '' || preg_match('/^([0-9a-f]{12})\.a\.gdms\.cloud(?::([0-9]{1,5}))?$/i', $server, $match) !== 1) {
            return '';
        }
        $port = $match[2] ?? '';
        if ($port !== '' && ((int) $port < 1 || (int) $port > 65535)) {
            return '';
        }
        return self::normalizeMac($match[1]);
    }

    /**
     * Resolve a SIP server address to its literal IP when it is an IP-based
     * server reference. Hostnames and RemoteConnect MAC references are not
     * treated as IPs.
     */
    public static function sipServerIp(string $server): string
    {
        $server = self::safeSipServer($server);
        if ($server === '') {
            return '';
        }

        $host = $server;
        if (str_starts_with($host, '[') && str_contains($host, ']')) {
            $host = substr($host, 1, strpos($host, ']') - 1);
        } elseif (substr_count($host, ':') === 1) {
            [$candidateHost] = explode(':', $host, 2);
            $host = $candidateHost;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $packed = inet_pton($host);
        if ($packed === false) {
            return '';
        }
        $normalized = inet_ntop($packed);
        return $normalized === false ? '' : strtolower($normalized);
    }

    private static function ipv4Subnet(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return '';
        }
        $parts = array_map('intval', explode('.', $ip));
        $first = $parts[0] ?? 0;
        $second = $parts[1] ?? 0;
        $isPrivate = $first === 10
            || ($first === 172 && $second >= 16 && $second <= 31)
            || ($first === 192 && $second === 168);
        if (!$isPrivate) {
            return '';
        }
        return $first . '.' . $second . '.' . ($parts[2] ?? 0);
    }

    private static function managementUrl(string $ip): string
    {
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $host = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $ip . ']' : $ip;
        return 'http://' . $host;
    }
}
