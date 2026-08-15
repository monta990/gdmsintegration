<?php

namespace GlpiPlugin\Gdmsintegration;
/**
 * GDMS Integration — \GlpiPlugin\Gdmsintegration\Device
 * Tracks last-known status per MAC to detect state transitions.
 */
class Device extends \GlpiPlugin\Gdmsintegration\BaseTM {

    // Override to match exact table name created in setup.php
    public static function getTable($classname = null): string {
        return 'glpi_plugin_gdmsintegration_devices';
    }

    public static function getTypeName($nb = 0): string {
        return 'GDMS Device State';
    }

    // In-process cache: mac → row. Populated by primeCache() once per sync cycle.
    private static array $stateCache = [];

    public static function primeCache(): void {
        self::$stateCache = [];
        $obj = new self();
        foreach ($obj->find() as $row) {
            self::$stateCache[strtolower($row['mac'])] = $row;
        }
    }

    public function getState(string $mac): ?string {
        if (isset(self::$stateCache[$mac])) {
            return self::$stateCache[$mac]['status'] ?? null;
        }
        $fresh = new self();
        $rows  = $fresh->find(['mac' => $mac]);
        if (empty($rows)) {
            return null;
        }
        return reset($rows)['status'] ?? null;
    }

    public function saveStateWithNetwork(
        string $mac,
        string $status,
        string $network_name,
        string $ip             = '',
        string $firmware       = '',
        int    $uptime_sec     = 0,
        string $sn_cloud       = '',
        int    $network_id     = 0,
        string $wan_ports_json = '',
        string $model          = '',
        string $cloud_name     = '',
        int    $clients        = 0,
        int    $usage_bytes    = 0,
        int    $upload_bytes   = 0,
        int    $download_bytes = 0,
        int    $channel_2g     = 0,
        int    $channel_5g     = 0,
        ?string $first_seen    = null,
        ?string $last_seen     = null,
        string $mgmt_ip         = '',
        ?string $firmware_latest = '',
        string $sip_status      = '',
        int    $entities_id     = 0,
        string $ipv6            = '',
        string $private_ip      = '',
        string $sip_extension   = '',
        string $location        = '',
        int    $dnd             = 0,
        int    $is_synchronized = 0,
        string $sync_failure_msg = '',
        int    $scheduled_task  = 0
    ): bool {
        // Use in-process cache to skip a DB round-trip when primeCache() was called
        if (isset(self::$stateCache[$mac])) {
            $cached = self::$stateCache[$mac];
            $rows   = [$cached['id'] => $cached];
        } else {
            $rows = $this->find(['mac' => $mac]);
        }
        $data = [
            'status'          => $status,
            'network_name'    => $network_name,
            'network_id'      => $network_id,
            'ip'              => $ip,
            'firmware'        => $firmware,
            'uptime_sec'      => $uptime_sec,
            'sn_cloud'        => $sn_cloud,
            'wan_ports_json'  => $wan_ports_json,
            'model'           => $model,
            'cloud_name'      => $cloud_name,
            'clients'         => $clients,
            'usage_bytes'     => $usage_bytes,
            'upload_bytes'    => $upload_bytes,
            'download_bytes'  => $download_bytes,
            'channel_2g'      => $channel_2g,
            'channel_5g'      => $channel_5g,
            'mgmt_ip'         => $mgmt_ip,
            'sip_status'      => $sip_status,
            'ipv6'            => $ipv6,
            'private_ip'      => $private_ip,
            'sip_extension'   => $sip_extension,
            'location'        => $location,
            'dnd'             => $dnd,
            'is_synchronized' => $is_synchronized,
            'sync_failure_msg'=> $sync_failure_msg,
            'scheduled_task'  => $scheduled_task,
        ];
        if ($firmware_latest !== null) $data['firmware_latest'] = $firmware_latest;
        if ($first_seen !== null) $data['first_seen'] = $first_seen;
        if ($last_seen  !== null) $data['last_seen']  = $last_seen;
        // Only set entities_id when caller knows it (>0) — never overwrite entity set by cron/ajax sync.
        if ($entities_id > 0) $data['entities_id'] = $entities_id;
        if (!empty($rows)) {
            $id  = array_key_first($rows);
            $ok  = (bool) $this->update(array_merge(['id' => $id], $data));
            self::$stateCache[$mac] = array_merge($rows[$id], $data, ['id' => $id]);
            return $ok;
        }
        $new_id = (int) $this->add(array_merge(['mac' => $mac, 'entities_id' => $entities_id], $data));
        if ($new_id > 0) {
            self::$stateCache[$mac] = array_merge(['mac' => $mac, 'id' => $new_id, 'entities_id' => $entities_id], $data);
        }
        return $new_id > 0;
    }

    public function getWanPortsJson(string $mac): string {
        $fresh = new self();
        $rows  = $fresh->find(['mac' => $mac]);
        return empty($rows) ? '' : (reset($rows)['wan_ports_json'] ?? '');
    }


    /**
     * 1.6.0: read-only Grandstream diagnostics tab on GLPI assets.
     *
     * The tab is only exposed when the asset is actually linked to a
     * Grandstream device state record for the current entity. This prevents
     * the tab from appearing on unrelated phones/network equipment.
     */
    private static function translateStatus(string $status): string {
        $status = strtolower(trim($status));
        return match ($status) {
            'online' => __('Online', 'gdmsintegration'),
            'offline' => __('Offline', 'gdmsintegration'),
            default => $status,
        };
    }

    private static function getLinkedState(\CommonGLPI $item): ?array {
        if (!in_array($item::class, ['NetworkEquipment', 'Phone'], true)) {
            return null;
        }

        $mac = strtolower(trim((string)($item->fields['uuid'] ?? '')));
        $entity = (int)($item->fields['entities_id'] ?? 0);
        if ($mac === '' || !\Session::haveAccessToEntity($entity)) {
            return null;
        }

        $o = new self();
        $found = $o->find([
            'mac' => $mac,
            'entities_id' => $entity,
        ]);

        return empty($found) ? null : reset($found);
    }

    public function getTabNameForItem(\CommonGLPI $item, $withtemplate = 0): string {
        if ($withtemplate || self::getLinkedState($item) === null) {
            return '';
        }

        // Explicit icon: the tab provider is not the same itemtype as the
        // form, so relying on the automatic item icon would be misleading.
        return self::createTabEntry(
            __('Grandstream diagnostics', 'gdmsintegration'),
            0,
            $item::class,
            'ti ti-device-desktop-analytics'
        );
    }

    public static function displayTabContentForItem(\CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool {
        $d = self::getLinkedState($item);
        if ($d === null) {
            echo '<div class="alert alert-info"><i class="ti ti-info-circle me-2"></i>'.__('No Grandstream cloud data linked to this asset.', 'gdmsintegration').'</div>';
            return true;
        }

        $wan = json_decode((string)($d['wan_ports_json'] ?? ''), true) ?: [];
        $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $fw = trim((string)($d['firmware'] ?? ''));

        // Prefer the stored private/local address. For devices where GDMS/GWN
        // exposes the management IPv4 separately, use that as the fallback.
        // Deliberately do not fall back to the public IP here: this tab is a
        // local asset diagnostic view.
        $localIp = trim((string)($d['private_ip'] ?? ''));
        if ($localIp === '') {
            $localIp = trim((string)($d['mgmt_ip'] ?? ''));
        }

        // Reuse the same availability/SLA calculation used by the NOC dashboard.
        // The previous 0-100 "Health" score was a local heuristic unrelated to
        // the dashboard SLA tiers, which made the diagnostic value ambiguous.
        $availability = \GlpiPlugin\Gdmsintegration\Sync::calculateUptime((string)($d['mac'] ?? ''));
        $sla = \GlpiPlugin\Gdmsintegration\Sync::slaLabel($availability);

        // Only valid IP literals become links. IPv6 addresses are bracketed in URLs.
        // The destination is intentionally HTTPS and opens in a new tab/window;
        // invalid/empty values remain plain text.
        $ipLink = static function ($ip) use ($esc): string {
            $ip = trim((string)$ip);
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return $esc($ip);
            }
            $host = str_contains($ip, ':') ? '['.$ip.']' : $ip;
            $url = 'https://'.$host;
            return '<a href="'.$esc($url).'" target="_blank" rel="noopener noreferrer" class="text-decoration-none">'.$esc($ip).' <i class="ti ti-external-link ms-1" aria-hidden="true"></i></a>';
        };

        $fields = [
            ['ti ti-activity', __('Status', 'gdmsintegration'), self::translateStatus($d['status'] ?? '')],
            ['ti ti-shield-check', __('SLA', 'gdmsintegration'), $sla],
            ['ti ti-network', __('Network', 'gdmsintegration'), $d['network_name'] ?? ''],
            ['ti ti-network', __('Private IP', 'gdmsintegration'), $localIp, true],
            ['ti ti-device-desktop', __('Firmware', 'gdmsintegration'), $fw],
            ['ti ti-users', __('Clients', 'gdmsintegration'), $d['clients'] ?? 0],
            ['ti ti-chart-line', __('Availability %', 'gdmsintegration'), number_format($availability, 2, '.', '').'%'],
            ['ti ti-calendar-check', __('Last seen', 'gdmsintegration'), $d['last_seen'] ?? ''],
        ];

        echo '<div class="card">';
        echo '<div class="card-header"><strong><i class="ti ti-device-desktop-analytics me-2"></i>'.__('Grandstream cloud snapshot', 'gdmsintegration').'</strong></div>';
        echo '<div class="card-body"><div class="row g-3">';
        foreach ($fields as $field) {
            [$icon, $label, $value] = $field;
            $linkIp = !empty($field[3]);
            $value = $value ?: '—';
            echo '<div class="col-md-3">';
            echo '<div class="text-muted small"><i class="'. $esc($icon) .' me-1"></i>'.$esc($label).'</div>';
            echo '<div class="fw-semibold">'.($linkIp && $value !== '—' ? $ipLink($value) : $esc($value)).'</div>';
            echo '</div>';
        }
        echo '</div>';

        if ($wan) {
            echo '<hr><h5><i class="ti ti-world me-2"></i>'.__('WAN diagnostics', 'gdmsintegration').'</h5>';
            echo '<div class="table-responsive"><table class="table table-sm"><thead><tr>';
            echo '<th><i class="ti ti-network me-1"></i>'.__('WAN', 'gdmsintegration').'</th>';
            echo '<th><i class="ti ti-link me-1"></i>'.__('Link', 'gdmsintegration').'</th>';
            echo '<th><i class="ti ti-world me-1"></i>IP</th>';
            echo '<th><i class="ti ti-router me-1"></i>Gateway</th>';
            echo '<th><i class="ti ti-dns me-1"></i>DNS</th>';
            echo '</tr></thead><tbody>';
            foreach ($wan as $w) {
                if ((int)($w['role'] ?? 0) !== 1) {
                    continue;
                }
                // Keep WAN state semantics identical to the dashboard/port modal:
                // physical link and Internet connectivity are separate states.
                // A WAN can therefore be physically up while having no Internet.
                $linkUp = (int)($w['link'] ?? 0) === 1;
                $connectStatus = isset($w['connectStatus']) ? (int)$w['connectStatus'] : -1;
                if (!$linkUp) {
                    $wanState = __('Link down', 'gdmsintegration');
                    $wanStateClass = 'text-muted';
                } elseif ($connectStatus === 1) {
                    $wanState = __('Online', 'gdmsintegration');
                    $wanStateClass = 'text-success';
                } elseif ($connectStatus === 0) {
                    $wanState = __('WAN up, no internet', 'gdmsintegration');
                    $wanStateClass = 'text-warning';
                } else {
                    $wanState = __('WAN up, unknown', 'gdmsintegration');
                    $wanStateClass = 'text-warning';
                }
                echo '<tr>';
                echo '<td>'.$esc($w['wanName'] ?? $w['silk'] ?? $w['id'] ?? 'WAN').'</td>';
                echo '<td><span class="'.$wanStateClass.' fw-semibold"><i class="ti '.($linkUp ? ($connectStatus === 1 ? 'ti-circle-check' : 'ti-alert-circle') : 'ti-circle-x').' me-1" aria-hidden="true"></i>'.$esc($wanState).'</span></td>';
                $wanIp = trim((string)($w['ip'] ?? ''));
                echo '<td>'.($wanIp !== '' ? $ipLink($wanIp) : '—').'</td>';
                echo '<td>'.$esc($w['gateway'] ?? '').'</td>';
                echo '<td>'.$esc(trim(($w['firstDns'] ?? '').' '.($w['secondDns'] ?? ''))).'</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }

        echo '<div class="text-muted small mt-2"><i class="ti ti-info-circle me-1"></i>'.__('Read-only diagnostic snapshot from the last successful cloud synchronization. Destructive diagnostics are intentionally not exposed here.', 'gdmsintegration').'</div>';
        echo '</div></div>';

        return true;
    }
}
