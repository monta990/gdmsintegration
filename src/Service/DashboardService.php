<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class DashboardService
{
    public static function render(Request $request): Response
    {
        ob_start();
            global $CFG_GLPI;

            \Session::checkLoginUser();
            if (!\Session::haveRight('config', READ) && !\Session::haveRight('networking', READ)) {
                throw new \Glpi\Exception\Http\AccessDeniedHttpException();
            }

            \Html::requireJs('charts');    // ECharts 5 - lib/echarts.js
            \Html::requireJs('flatpickr'); // Flatpickr - lib/flatpickr.js

            \Html::header(
                'GDMS — ' . __('Dashboard', 'gdmsintegration'),
                '',
                'tools',
                '\GlpiPlugin\Gdmsintegration\Menu'
            );

            $entities_id = (int) (\Session::getActiveEntity() ?? 0);
            if (!\Session::haveAccessToEntity($entities_id)) { throw new \Glpi\Exception\Http\AccessDeniedHttpException(); }
            // AJAX URL built inline in JS

            // Guard: no config
            $config_obj       = new \GlpiPlugin\Gdmsintegration\Config();
            $config           = $config_obj->getConfigByEntity($entities_id);
            $is_configured    = !empty($config['client_id']) || !empty($config['gwn_client_id']);
            $refresh_interval = max(30, (int)($config['refresh_interval'] ?? 300));
            $last_sync_at     = $config['last_sync_at'] ?? null;
            $last_sync_label  = '';
            if ($last_sync_at) {
                // last_sync_at is stored as UTC (gmdate). Append ' UTC' so strtotime() parses it correctly
                // regardless of the server's local timezone setting.
                $diff = max(0, time() - strtotime($last_sync_at . ' UTC'));
                if ($diff < 60)         $last_sync_label = __('Last sync: just now', 'gdmsintegration');
                elseif ($diff < 3600)   $last_sync_label = sprintf(__('Last sync: %d min ago', 'gdmsintegration'), intdiv($diff, 60));
                elseif ($diff < 86400)  $last_sync_label = sprintf(__('Last sync: %dh ago', 'gdmsintegration'), intdiv($diff, 3600));
                else                    $last_sync_label = sprintf(__('Last sync: %dd ago', 'gdmsintegration'), intdiv($diff, 86400));
            }
            $chart_days       = max(7, min(365, (int)($config['chart_days'] ?? 60)));
            $show_topology    = (int)($config['show_topology'] ?? 1);
            $ip_version       = in_array($config['ip_version'] ?? '', ['ipv4', 'ipv6'], true) ? $config['ip_version'] : 'ipv4';

            // Flatpickr locale --- extract language and region from GLPI session
            $_fp_lang   = 'en';
            $_fp_region = '';
            if (!empty(\Session::getLanguage())) {
                $_fp_parts  = explode('_', \Session::getLanguage() ?? '');
                $_fp_lang   = strtolower($_fp_parts[0] ?? 'en');
                $_fp_region = strtoupper($_fp_parts[1] ?? '');
            }

            $_plugin_web = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration';

            if (!$is_configured) {
                $json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
            $js_json = [
                'strings'        => json_encode($js_strings, $json_flags),
                'nodes'          => json_encode(array_values($nodes), $json_flags),
                'edges'          => json_encode(array_values($edges), $json_flags),
                'chart_labels'   => json_encode($chart_labels, $json_flags),
                'chart_datasets' => json_encode($chart_datasets, $json_flags),
                'urls'           => array_map(static fn(string $url): string => json_encode($url, $json_flags), $urls),
            ];

            echo \GlpiPlugin\Gdmsintegration\Twig::get()->render('dashboard.html.twig', [
                    'is_configured' => false,
                    'config_url'    => $_plugin_web . '/config',
                ]);
                \Html::footer();
                return new \Symfony\Component\HttpFoundation\Response(ob_get_clean());
            }

            // Load GDMS-managed devices from the plugin's own device-state table.
            // This is the authoritative list of what GDMS manages --- NOT all GLPI assets.
            $online  = 0;
            $offline = 0;
            $rows    = [];
            $root    = rtrim($CFG_GLPI['root_doc'] ?? '', '/');

            $state_obj  = new \GlpiPlugin\Gdmsintegration\Device();

            $all_states = $state_obj->find(); // every MAC the plugin currently manages in the cloud

            // Batch-load uptime for all MACs in one query instead of one query per device
            $_all_macs    = array_filter(array_column($all_states, 'mac'));
            $_uptime_map  = \GlpiPlugin\Gdmsintegration\Sync::calculateUptimeBatch($_all_macs);

            // Build MAC --- GLPI asset map across ALL entities (entity 0 vs active entity mismatch)
            $mac_to_asset = [];
            foreach (['NetworkEquipment', 'Phone'] as $itemtype) {
                $obj     = new $itemtype();
                $rows_db = $obj->find(); // no entity filter — plugin manages devices globally
                foreach ($rows_db as $d) {
                    if (!empty($d['uuid'])) {
                        $mac_to_asset[strtolower(trim($d['uuid']))] = array_merge($d, ['_itemtype' => $itemtype]);
                    }
                }
            }

            foreach ($all_states as $state) {
                $mac      = strtolower(trim($state['mac'] ?? ''));
                if (empty($mac)) continue;

                $isOnline    = ($state['status'] ?? '') === 'online';
                $uptime      = $_uptime_map[$mac] ?? 0.0;
                $sla         = \GlpiPlugin\Gdmsintegration\Sync::slaLabel($uptime);
                $net_name    = (string)($state['network_name'] ?? '');
                $ip          = (string)($state['ip'] ?? '');
                $firmware         = (string)($state['firmware'] ?? '');
                $sip_status       = $state['sip_status']      ?? '';
                $sip_extension    = $state['sip_extension']  ?? '';
                $dnd              = (int)($state['dnd']            ?? 0);
                $is_synchronized  = (int)($state['is_synchronized'] ?? 0);
                $sync_failure_msg = $state['sync_failure_msg'] ?? '';
                $scheduled_task   = (int)($state['scheduled_task']  ?? 0);
                $ipv6             = (string)($state['ipv6'] ?? '');
                $private_ip       = (string)($state['private_ip'] ?? '');
                $location         = (string)($state['location'] ?? '');
                $uptime_sec       = (int)($state['uptime_sec']                   ?? 0);
                $sn_cloud         = (string)($state['sn_cloud'] ?? '');

                // Find the GLPI asset if it exists
                $glpi     = $mac_to_asset[$mac] ?? null;
                // Name priority: 1) GLPI asset name  2) cloud name stored during sync  3) MAC
                $cloud_name_raw = $state['cloud_name'] ?? '';
                if ($glpi) {
                    $name = (string)$glpi['name'];
                } elseif ($cloud_name_raw !== '') {
                    $name = (string)$cloud_name_raw;
                } else {
                    $name = (string)$mac;
                }
                $serial   = $glpi ? (string)($glpi['serial'] ?? '') : '';
                $itemtype = $glpi ? $glpi['_itemtype'] : 'NetworkEquipment';
                $glpi_id  = $glpi ? (int)$glpi['id'] : 0;

                if ($glpi_id > 0) {
                    $asset_url = $root . ($itemtype === 'Phone' ? '/front/phone.form.php?id=' : '/front/networkequipment.form.php?id=') . $glpi_id;
                } else {
                    $asset_url = '';
                }

                $isOnline ? $online++ : $offline++;
                // Resolve model: GLPI catalog first, then apType stored by sync (e.g. "GWN7001")
                $model_name = '';
                if ($glpi) {
                    $model_id = (int)($glpi['networkequipmentmodels_id'] ?? $glpi['phonemodels_id'] ?? 0);
                    if ($model_id > 0) {
                        $model_class = $itemtype === 'Phone' ? 'PhoneModel' : 'NetworkEquipmentModel';
                        $model_obj   = new $model_class();
                        if ($model_obj->getFromDB($model_id)) {
                            $model_name = $model_obj->getName();
                        }
                    }
                }
                // Fallback: apType from GWN/GDMS cloud stored during sync (e.g. "GWN7001", "GRP2601")
                if (!$model_name) {
                    $model_name = $state['model'] ?? '';
                }

                $rows[] = [
                    'name'         => $name,
                    'asset_url'    => $asset_url,
                    'type'         => $itemtype,
                    'model'        => (string)$model_name,
                    'mac'          => (string)$mac,
                    'serial'       => $sn_cloud ?: $serial,
                    'network_name' => $net_name,
                    'network_id'   => (int)($state['network_id'] ?? 0),
                    'ip'           => $ip,
                    'firmware'     => $firmware,
                    'uptime_sec'   => $uptime_sec,
                    'online'       => $isOnline,
                    'uptime'       => $uptime,
                    'sla'          => (string)$sla,
                    'raw_model'      => $state['model'] ?? '',
                    'clients'        => (int)($state['clients']        ?? 0),
                    'upload_bytes'   => (int)($state['upload_bytes']   ?? 0),
                    'download_bytes' => (int)($state['download_bytes'] ?? 0),
                    // WAN aggregate: sum txBytes/rxBytes from all WAN ports (more accurate than ap/list usage field)
                    'wan_tx_bytes'   => (function() use ($state): int {
                        $ports = json_decode($state['wan_ports_json'] ?? '', true) ?? [];
                        $tx = 0; foreach ($ports as $p) {
                            // wan_ports_json contains normalized WAN and LAN records.
                            // connectStatus/wanType/gateway are present on LAN records too
                            // (with sentinel values), so they must never be used to classify
                            // a stored port as WAN.
                            if ((int)($p['role'] ?? 0) === 1) {
                                $tx += (int)($p['txBytes'] ?? 0);
                            }
                        } return $tx;
                    })(),
                    'wan_rx_bytes'   => (function() use ($state): int {
                        $ports = json_decode($state['wan_ports_json'] ?? '', true) ?? [];
                        $rx = 0; foreach ($ports as $p) {
                            if ((int)($p['role'] ?? 0) === 1) {
                                $rx += (int)($p['rxBytes'] ?? 0);
                            }
                        } return $rx;
                    })(),
                    // Parsed WAN ports are also exposed to the operational summary. Previously
                    // only byte aggregates were built, so the WAN card always saw an empty array.
                    'wan_ports'      => json_decode($state['wan_ports_json'] ?? '', true) ?: [],
                    'channel_2g'      => (int)($state['channel_2g']     ?? 0),
                    'channel_5g'      => (int)($state['channel_5g']     ?? 0),
                    'last_seen'       => $state['last_seen']  ?? '',
                    'first_seen'      => $state['first_seen'] ?? '',
                    'mgmt_ip'         => (string)($state['mgmt_ip'] ?? ''),
                    'sip_status'       => $sip_status,
                    'sip_extension'    => $sip_extension,
                    'dnd'              => $dnd,
                    'is_synchronized'  => $is_synchronized,
                    'sync_failure_msg' => $sync_failure_msg,
                    'scheduled_task'   => $scheduled_task,
                    'ipv6'             => $ipv6,
                    'private_ip'       => $private_ip,
                    'location'         => $location,
                    'sla_rank'         => ($uptime === 0.0 && $isOnline ? 4 : ($uptime >= 99.9 ? 0 : ($uptime >= 99.0 ? 1 : ($uptime >= 95.0 ? 2 : 3)))),
                ];
            }

            // Default order: network devices first, then phones; within each group sort by name
            usort($rows, function(array $a, array $b): int {
                $ta = ($a['type'] === 'Phone') ? 1 : 0;
                $tb = ($b['type'] === 'Phone') ? 1 : 0;
                if ($ta !== $tb) return $ta - $tb;
                return strnatcasecmp($a['name'], $b['name']);
            });

            // Helper: true if model prefix matches any phone/ATA prefix (same list as api.class.php)
            $isPhoneModel = static function(string $m): bool {
                $u = strtoupper(trim($m));
                foreach (['GRP','GXP','GXV','GXW','WP','HT','DP','GHP','GVC','GSC','GDS'] as $p) {
                    if (str_starts_with($u, $p)) return true;
                }
                return false;
            };

            // Build network --- first UCM/GCC device map for phone modal PBX info
            $pbx_by_network = [];
            foreach ($rows as $_r) {
                if (!preg_match('/^UCM|^GCC|^CLOUDUCM|^SOFTWAREUCM/i', $_r['raw_model'] ?? '')) continue;
                $_net = strtolower($_r['network_name'] ?? '');
                if ($_net !== '' && !isset($pbx_by_network[$_net])) {
                    $pbx_by_network[$_net] = ['name' => $_r['name'], 'ip' => $_r['private_ip'] ?: $_r['ip'], 'url' => $_r['asset_url']];
                }
            }

            // Build per-network device stats for tooltip (router/switch/AP/clients counts)
            // Device classification: GWN7001/7002/7003 prefix -- router; GWN7800/GSS -- switch; GWN76xx -- AP; UCM/GCC/GRP/GXP etc -- phone/pbx
            $net_stats = []; // network_name -- [router_on, router_off, switch_on, switch_off, ap_on, ap_off, clients_wired, clients_wireless]
            foreach ($rows as $r) {
                $nname = $r['network_name'];
                if ($nname === '') continue;
                if (!isset($net_stats[$nname])) {
                    $net_stats[$nname] = ['router_on'=>0,'router_off'=>0,'switch_on'=>0,'switch_off'=>0,'ap_on'=>0,'ap_off'=>0,'phone_on'=>0,'phone_off'=>0,'clients'=>0,'upload_bytes'=>0,'download_bytes'=>0];
                }
                $m   = strtoupper($r['raw_model'] ?? '');
                $on  = $r['online'];
                $isPhone  = ($r['type'] === 'Phone') || $isPhoneModel($r['raw_model'] ?? '');
                $isPbxDev = preg_match('/^UCM|^GCC|^CLOUDUCM|^SOFTWAREUCM/', $m);
                if (!$isPhone && !$isPbxDev) {
                    if (preg_match('/^GWN700[0-9]/', $m)) {
                        $on ? $net_stats[$nname]['router_on']++ : $net_stats[$nname]['router_off']++;
                    } elseif (preg_match('/^GWN78|^GSS/', $m)) {
                        $on ? $net_stats[$nname]['switch_on']++ : $net_stats[$nname]['switch_off']++;
                    } elseif (preg_match('/^GWN/', $m)) {
                        $on ? $net_stats[$nname]['ap_on']++ : $net_stats[$nname]['ap_off']++;
                    }
                }
                if ($isPhone || $isPbxDev) {
                    $on ? $net_stats[$nname]['phone_on']++ : $net_stats[$nname]['phone_off']++;
                }
                $net_stats[$nname]['clients']        += $r['clients'];
                $net_stats[$nname]['upload_bytes']   += $r['upload_bytes'];
                $net_stats[$nname]['download_bytes'] += $r['download_bytes'];
            }

            // Uptime history -- last N days per device per day (configurable via chart_days)
            $history_obj = new \GlpiPlugin\Gdmsintegration\History();
            $history_ago  = gmdate('Y-m-d H:i:s', strtotime("-{$chart_days} days"));
            $hist_rows    = $history_obj->find(['date' => ['>', $history_ago]], ['date DESC']);

            // Build: mac -- (name) and date -- per-mac status
            $mac_to_name = [];
            foreach ($all_states as $st) {
                $m = strtolower(trim($st['mac'] ?? ''));
                if ($m) {
                    $glpi = $mac_to_asset[$m] ?? null;
                    $mac_to_name[$m] = $glpi ? ($glpi['name'] ?? $m) : $m;
                }
            }

            // per_device[mac][day] = [online, total]
            $per_device = [];
            $all_days   = [];
            foreach ($hist_rows as $h) {
                $day    = substr($h['date'] ?? '', 0, 10);
                $mac    = strtolower(trim($h['mac'] ?? ''));
                $status = $h['status'] ?? '';
                if (!$day || !$mac) continue;
                $all_days[$day] = true;
                if (!isset($per_device[$mac][$day])) $per_device[$mac][$day] = ['online' => 0, 'total' => 0];
                $per_device[$mac][$day]['total']++;
                if ($status === 'online') $per_device[$mac][$day]['online']++;
            }
            ksort($all_days);
            $chart_labels = array_keys($all_days);

            // Build dataset per device -- only include MACs that are currently managed
            // (present in the devices table). Removed devices are excluded even if
            // their history rows survived the last cleanup cycle.
            $chart_datasets = [];
            $palette = ['#28a745','#007bff','#fd7e14','#dc3545','#6f42c1','#20c997','#ffc107','#e83e8c','#17a2b8','#6c757d'];
            $pi = 0;
            foreach ($per_device as $mac => $days) {
                if (!isset($mac_to_name[$mac])) continue; // device removed from cloud — skip
                $label  = $mac_to_name[$mac];
                $color  = $palette[$pi % count($palette)];
                $pi++;
                $vals = [];
                foreach ($chart_labels as $day) {
                    $d    = $days[$day] ?? ['online' => 0, 'total' => 0];
                    $vals[] = $d['total'] > 0 ? round($d['online'] / $d['total'] * 100) : null;
                }
                $chart_datasets[] = [
                    'label'           => $label,
                    'data'            => $vals,
                    'borderColor'     => $color,
                    'backgroundColor' => $color . '22',
                    'borderWidth'     => 2,
                    'fill'            => false,
                    'tension'         => 0.3,
                    'pointRadius'     => 2,
                    'spanGaps'        => true,
                ];
            }

            // Topology -- only build if show_topology is enabled (saves DB query when disabled)
            $nodes = [];
            $edges = [];
            if ($show_topology) {
                $entity_macs = array_keys($mac_to_asset);
                $link        = new \GlpiPlugin\Gdmsintegration\Link();
                $links_raw   = empty($entity_macs) ? [] : $link->find(['source_mac' => $entity_macs]);

                foreach ($rows as $r) {
                    $nodes[] = [
                        'id'    => $r['mac'],
                        'label' => $r['name'],
                        'color' => ['background' => $r['online'] ? '#28a745' : '#dc3545', 'border' => '#aaa'],
                        'font'  => ['color' => '#ffffff'],
                        'title' => $r['name'] . ' — ' . ($r['online'] ? __('Online', 'gdmsintegration') : __('Offline', 'gdmsintegration')),
                    ];
                }
                foreach ($links_raw as $l) {
                    if (!empty($l['source_mac']) && !empty($l['target_mac'])) {
                        $edges[] = ['from' => $l['source_mac'], 'to' => $l['target_mac']];
                    }
                }
                // Phone -- PBX edges: match each registered phone to its UCM by /24 subnet of private_ip.
                // Phones and their UCM share the same LAN segment, so subnet is a reliable heuristic
                // even when multiple UCMs share the same GDMS network_name (e.g. one GDMS account).
                // Fallback: network_name match (for phones without private_ip).
                $_subnet = static function(string $ip): string {
                    $p = explode('.', $ip);
                    return count($p) === 4 ? $p[0] . '.' . $p[1] . '.' . $p[2] : '';
                };
                $_pbx_by_subnet  = []; // /24 â†’ mac
                $_pbx_by_netname = []; // network_name â†’ mac  (fallback, first-UCM-wins)
                foreach ($rows as $_r) {
                    if (!preg_match('/^UCM|^GCC|^CLOUDUCM/i', $_r['raw_model'] ?? '') || empty($_r['mac'])) continue;
                    $_sn = $_subnet($_r['private_ip'] ?: $_r['ip']);
                    if ($_sn !== '' && !isset($_pbx_by_subnet[$_sn]))  $_pbx_by_subnet[$_sn] = $_r['mac'];
                    $_nk = strtolower(html_entity_decode($_r['network_name']));
                    if ($_nk !== '' && !isset($_pbx_by_netname[$_nk])) $_pbx_by_netname[$_nk] = $_r['mac'];
                }
                foreach ($rows as $_r) {
                    if (empty($_r['sip_status']) || empty($_r['mac'])) continue;
                    $_phone_sn  = $_subnet($_r['private_ip'] ?: $_r['ip']);
                    $_pbx_mac   = ($_phone_sn !== '' ? ($_pbx_by_subnet[$_phone_sn] ?? null) : null)
                               ?? ($_pbx_by_netname[strtolower(html_entity_decode($_r['network_name']))] ?? null);
                    if ($_pbx_mac && $_pbx_mac !== $_r['mac']) {
                        $edges[] = ['from' => $_r['mac'], 'to' => $_pbx_mac];
                    }
                }
            } // end show_topology block

            // Build global summary totals from net_stats + rows
            $total_networks = count($net_stats);

            $summary = [
                'router_on'  => 0, 'router_off' => 0,
                'switch_on'  => 0, 'switch_off' => 0,
                'ap_on'      => 0, 'ap_off'     => 0,
                'clients'    => 0,
                'phone_on'   => 0, 'phone_off'  => 0,
            ];
            foreach ($net_stats as $ns) {
                $summary['router_on']  += $ns['router_on'];
                $summary['router_off'] += $ns['router_off'];
                $summary['switch_on']  += $ns['switch_on'];
                $summary['switch_off'] += $ns['switch_off'];
                $summary['ap_on']      += $ns['ap_on'];
                $summary['ap_off']     += $ns['ap_off'];
                $summary['phone_on']   += $ns['phone_on'];
                $summary['phone_off']  += $ns['phone_off'];
                $summary['clients']    += $ns['clients'];
            }

            // ---- Row pre-processing -----
            $fmtB = function(int $b): string {
                if ($b >= 1073741824) return round($b / 1073741824, 1) . ' GB';
                if ($b >= 1048576)    return round($b / 1048576, 1) . ' MB';
                if ($b >= 1024)       return round($b / 1024, 0) . ' KB';
                return $b . ' B';
            };
            $ipHref = function(string $ip): string {
                return 'http://' . (strpos($ip, ':') !== false ? '[' . $ip . ']' : urlencode($ip));
            };

            foreach ($rows as &$r) {
                $us = (int)($r['uptime_sec'] ?? 0);
                $ud = intdiv($us, 86400);
                $uh = intdiv($us % 86400, 3600);
                $um = intdiv($us % 3600, 60);
                $r['_uptime_str']     = $ud > 0 ? "{$ud}d {$uh}h {$um}m" : ($uh > 0 ? "{$uh}h {$um}m" : "{$um}m");
                $r['_uptime_display'] = $us > 0 ? $r['_uptime_str'] : '—';
                $r['_is_phone']       = ($r['type'] === 'Phone') || $isPhoneModel($r['raw_model'] ?? '');

                $r['_pref_ip'] = $ip_version === 'ipv6'
                    ? (!empty($r['ipv6'])       ? $r['ipv6']       : $r['private_ip'])
                    : (!empty($r['private_ip']) ? $r['private_ip'] : $r['ipv6']);
                $r['_sec_ip']  = $ip_version === 'ipv6'
                    ? (!empty($r['ipv6']) && !empty($r['private_ip']) ? $r['private_ip'] : '')
                    : (!empty($r['private_ip']) && !empty($r['ipv6']) ? $r['ipv6'] : '');
                $r['_pref_ip_href']   = !empty($r['_pref_ip']) ? $ipHref($r['_pref_ip']) : '';
                $r['_sec_ip_href']    = !empty($r['_sec_ip'])  ? $ipHref($r['_sec_ip'])  : '';
                $r['_sec_ip_is_ipv6'] = strpos((string)($r['_sec_ip'] ?? ''), ':') !== false;

                $ul        = (int)($r['upload_bytes']   ?? 0);
                $dl        = (int)($r['download_bytes'] ?? 0);
                $ch2       = (int)($r['channel_2g'] ?? 0);
                $ch5       = (int)($r['channel_5g'] ?? 0);
                $ls        = $r['last_seen'] ?? '';
                $tip_lines = [];
                if ($ul > 0 || $dl > 0) {
                    $tip_lines[] = __('Network usage', 'gdmsintegration');
                    $tip_lines[] = __('Upload', 'gdmsintegration') . ': ↑ ' . $fmtB($ul) . '  ' . __('Download', 'gdmsintegration') . ': ↓ ' . $fmtB($dl);
                }
                if ($ch2 > 0 || $ch5 > 0) {
                    $ch_parts = [];
                    if ($ch2 > 0) $ch_parts[] = '2.4GHz ch' . $ch2;
                    if ($ch5 > 0) $ch_parts[] = '5GHz ch' . $ch5;
                    $tip_lines[] = implode('  ', $ch_parts);
                }
                if (!empty($r['location']))   $tip_lines[] = __('Location',   'gdmsintegration') . ': ' . html_entity_decode($r['location']);
                if (!empty($r['first_seen'])) $tip_lines[] = __('First seen', 'gdmsintegration') . ': ' . substr($r['first_seen'], 0, 16);
                if ($ls)                      $tip_lines[] = __('Last seen',  'gdmsintegration') . ': ' . substr($ls, 0, 16);
                $r['_uptime_tip'] = implode("\n", $tip_lines);

                $wan_tx           = (int)($r['wan_tx_bytes'] ?? 0);
                $wan_rx           = (int)($r['wan_rx_bytes'] ?? 0);
                $r['_ul_val']     = $wan_tx > 0 ? $wan_tx : $ul;
                $r['_dl_val']     = $wan_rx > 0 ? $wan_rx : $dl;
                $r['_ul_fmt']     = $fmtB($r['_ul_val']);
                $r['_dl_fmt']     = $fmtB($r['_dl_val']);
                $traffic_src      = $wan_tx > 0
                    ? __('WAN port aggregate (sum of all WAN ports since last router reboot)', 'gdmsintegration')
                    : __('Device-reported traffic (wireless client usage)', 'gdmsintegration');
                $fs_raw           = $r['first_seen'] ?? '';
                $r['_traffic_tip'] = $fs_raw
                    ? sprintf(__('%s — since first seen in cloud (%s, ~%d days)', 'gdmsintegration'), $traffic_src, substr($fs_raw, 0, 10), max(1, (int)((time() - strtotime($fs_raw)) / 86400)))
                    : $traffic_src;

                $rnet            = $r['network_name'] ?? '';
                $rstat           = $net_stats[$rnet] ?? null;
                $r['_net_data']  = ($rnet !== '' && $rstat !== null)
                    ? array_merge(['name' => html_entity_decode($rnet)], $rstat)
                    : null;

                // Firmware update badges are controlled exclusively by the live official-firmware check.
                $r['_fw_badge_shown'] = false;

                $_pbx_net    = strtolower(html_entity_decode($r['network_name'] ?? ''));
                $r['_pbx']   = $pbx_by_network[$_pbx_net] ?? null;
                $_sip_tip    = '';
                if (!empty($r['sip_status'])) {
                    $_sip_tip  = $r['sip_status'] === 'registered' ? __('SIP Registered', 'gdmsintegration') : __('SIP Unregistered', 'gdmsintegration');
                    if (!empty($r['sip_extension'])) $_sip_tip .= ' Â· ' . __('Ext', 'gdmsintegration') . ': ' . $r['sip_extension'];
                    if (!empty($r['dnd']))           $_sip_tip .= ' Â· ' . __('Do Not Disturb', 'gdmsintegration');
                }
                $r['_sip_tip']   = $_sip_tip;
                $r['_sip_color'] = ($r['sip_status'] ?? '') === 'registered' ? '#28a745' : '#dc3545';
            }
            unset($r);

            // --- Summary cards ----
            $total = $online + $offline;
            $pct   = $total > 0 ? round($online / $total * 100) : 0;
            $cards = [
                ['icon' => 'ti-network', 'label' => __('Networks',     'gdmsintegration'), 'vals' => [['v' => $total_networks,       'lbl' => __('Total',     'gdmsintegration'), 'cls' => '']]],
                ['icon' => 'ti-sitemap', 'label' => __('Router',       'gdmsintegration'), 'vals' => [['v' => $summary['router_on'], 'lbl' => __('Online',    'gdmsintegration'), 'cls' => 'text-success'], ['v' => $summary['router_off'],  'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger']]],
                ['icon' => 'ti-server',  'label' => __('Switch',       'gdmsintegration'), 'vals' => [['v' => $summary['switch_on'], 'lbl' => __('Online',    'gdmsintegration'), 'cls' => 'text-success'], ['v' => $summary['switch_off'],  'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger']]],
                ['icon' => 'ti-wifi',    'label' => 'AP',                                  'vals' => [['v' => $summary['ap_on'],     'lbl' => __('Online',    'gdmsintegration'), 'cls' => 'text-success'], ['v' => $summary['ap_off'],      'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger']]],
                ['icon' => 'ti-phone',   'label' => __('Phones & PBX', 'gdmsintegration'), 'vals' => [['v' => $summary['phone_on'],  'lbl' => __('Online',    'gdmsintegration'), 'cls' => 'text-success'], ['v' => $summary['phone_off'],  'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger']]],
                ['icon' => 'ti-users',   'label' => __('Clients',      'gdmsintegration'), 'vals' => [['v' => $summary['clients'],   'lbl' => __('Connected', 'gdmsintegration'), 'cls' => 'text-info']]],
            ];

            $critical_devices = array_values(array_filter($rows, fn($r) => $r['sla'] === __('Critical', 'gdmsintegration') && !$r['online']));

            // Operational intelligence: infrastructure health and WAN overview.
            $health_sum = 0; $wan_up = 0; $wan_down = 0; $wan_degraded = 0;
            foreach ($rows as &$rr) {
                $score = 100;
                if (!$rr['online']) $score -= 55;
                if (($rr['uptime'] ?? 100) < 99) $score -= 15;
                if (($rr['uptime'] ?? 100) < 95) $score -= 15;
                if (!empty($rr['sync_failure_msg'])) $score -= 10;
                $rr['health'] = max(0, $score); $health_sum += $rr['health'];
                foreach (($rr['wan_ports'] ?? []) as $wp) {
                    // Use the normalized role written by Sync. LAN records also carry
                    // connectStatus/wanType/gateway keys, so those fields are not WAN markers.
                    if ((int)($wp['role'] ?? 0) !== 1) continue;
                    $link = (int)($wp['link'] ?? 0); $conn = (int)($wp['connectStatus'] ?? 0);
                    $ip = trim((string)($wp['ip'] ?? $wp['ipv4'] ?? ''));
                    if ($link !== 1) $wan_down++;
                    elseif ($conn !== 1 || $ip === '') $wan_degraded++;
                    else $wan_up++;
                }
            }
            unset($rr);
            $health_score = $total > 0 ? round($health_sum / $total, 1) : 100.0;
            // Operational WAN state is based on Internet availability, not only the
            // physical Ethernet link. A port with link up but connectStatus=0 is
            // operationally DOWN (no Internet), while wan_degraded keeps the
            // useful clarification that the physical port itself remains active.
            $wan_down_total = $wan_down + $wan_degraded;
            $wan_active = $wan_up;
            $ops_summary = ['health'=>$health_score,'wan_up'=>$wan_up,'wan_active'=>$wan_active,'wan_down'=>$wan_down_total,'wan_degraded'=>$wan_degraded];

            // ---- URL map ----
            $_base = $_plugin_web . '/';
            $_eid  = 'entities_id=' . $entities_id;
            $urls  = [
                'sync'            => $_base . 'sync?'                         . $_eid,
                'fw_check'        => $_base . 'firmware?action=check&'        . $_eid,
                'fw_upgrade'      => $_base . 'firmware?action=upgrade&'      . $_eid,
                'fw_check_all'    => $_base . 'firmware?action=check_all&'    . $_eid,
                'fw_upgrade_gdms' => $_base . 'firmware?action=upgrade_gdms&' . $_eid,
                'reboot_gdms'        => $_base . 'firmware?action=reboot_gdms&'        . $_eid,
                'factory_reset_gdms' => $_base . 'firmware?action=factory_reset_gdms&' . $_eid,
                'ports'           => $_base . 'ports?action=status&'          . $_eid,
                'clients'         => $_base . 'clients?'                      . $_eid,
                'alerts'          => $_base . 'alerts?'                       . $_eid,
                'history_export'  => $_base . 'history/export?'                    . $_eid,
            ];

            // --- JS strings ----
            $js_strings = [
                'syncing'         => __('Syncing…',                                                              'gdmsintegration'),
                'rebootWarning'   => __('The device will reboot during the update. Schedule during a maintenance window.', 'gdmsintegration'),
                'reqFailed'       => __('Request failed. Check connection.',                                     'gdmsintegration'),
                'schedOk'         => __('Update scheduled successfully. The device will update shortly.',        'gdmsintegration'),
                'applyAsap'       => __('Apply now (ASAP)',                                                      'gdmsintegration'),
                'schedUpdate'     => __('Schedule update',                                                       'gdmsintegration'),
                'scheduling'      => __('Scheduling…',                                                           'gdmsintegration'),
                'linkDown'        => __('Link down',                                                             'gdmsintegration'),
                'linkUp'          => __('Link up',                                                               'gdmsintegration'),
                'online'          => __('Online',                                                                'gdmsintegration'),
                'noInternet'      => __('No internet',                                                           'gdmsintegration'),
                'noPorts'         => __('No ports found',                                                        'gdmsintegration'),
                'netUsage'        => __('Network usage',                                                         'gdmsintegration'),
                'netTraffic'      => __('Network traffic',                                                       'gdmsintegration'),
                'firstSeen'       => __('First seen',                                                            'gdmsintegration'),
                'lastSeen'        => __('Last seen',                                                             'gdmsintegration'),
                'wanOnline'       => __('WAN online',                                                            'gdmsintegration'),
                'wanNoInet'       => __('WAN up, no internet',                                                   'gdmsintegration'),
                'wanUnknown'      => __('WAN up, unknown',                                                       'gdmsintegration'),
                'lanUp'           => __('LAN up',                                                                'gdmsintegration'),
                'connection'      => __('Connection',                                                            'gdmsintegration'),
                'ipAddress'       => __('IP Address',                                                            'gdmsintegration'),
                'wanTypeLbl'      => __('Type',                                                                  'gdmsintegration'),
                'connectedFor'    => __('Connected for',                                                         'gdmsintegration'),
                'statusLbl'       => __('Status',                                                                'gdmsintegration'),
                'portType'        => __('Port type',                                                             'gdmsintegration'),
                'negotiatedSpeed' => __('Negotiated speed',                                                      'gdmsintegration'),
                'portLabel'       => __('Port label',                                                            'gdmsintegration'),
                'unknown'         => __('Unknown',                                                               'gdmsintegration'),
                'officialFw'      => __('Official firmware',                                                     'gdmsintegration'),
                'latestAvail'     => __('Latest available',                                                      'gdmsintegration'),
                'gdmsManaged'     => __('GDMS managed',                                                          'gdmsintegration'),
                'gdmsVersionNote' => __('GDMS applies the latest firmware available in its repository. The selected version is informational only.', 'gdmsintegration'),
                'selectVersion'   => __('Select version',                                                        'gdmsintegration'),
                'curFw'           => __('Current firmware',                                                      'gdmsintegration'),
                'deviceMac'       => __('Device MAC',                                                            'gdmsintegration'),
                'hdx'             => __('HDX',                                                                   'gdmsintegration'),
                'fdx'             => __('FDX',                                                                   'gdmsintegration'),
                'disconnected'    => __('Disconnected',                                                          'gdmsintegration'),
                'lbl_online'      => __('online',                                                                'gdmsintegration'),
                'lbl_offline'     => __('offline',                                                               'gdmsintegration'),
                'lbl_total'       => __('Total',                                                                 'gdmsintegration'),
                'lbl_router'      => __('Router',                                                                'gdmsintegration'),
                'lbl_switch'      => __('Switch',                                                                'gdmsintegration'),
                'lbl_ap'          => __('AP',                                                                    'gdmsintegration'),
                'lbl_phones'      => __('Phones & PBX',                                                          'gdmsintegration'),
                'lbl_clients'     => __('Clients',                                                               'gdmsintegration'),
                'noClients'       => __('No clients connected',                                                  'gdmsintegration'),
                'noAlerts'        => __('No recent alerts',                                                      'gdmsintegration'),
                'alertsError'     => __('Could not load alerts',                                                 'gdmsintegration'),
                'alertTime'       => __('Time',                                                                  'gdmsintegration'),
                'alertSev'        => __('Severity',                                                              'gdmsintegration'),
                'alertDevice'     => __('Device',                                                                'gdmsintegration'),
                'alertMsg'        => __('Alert',                                                                 'gdmsintegration'),
                'alertDismiss'    => __('Dismiss',                                                               'gdmsintegration'),
                'hostname'        => __('Hostname',                                                              'gdmsintegration'),
                'band'            => __('Band',                                                                  'gdmsintegration'),
                'signal'          => __('Signal',                                                                'gdmsintegration'),
                'txrx'            => __('TX / RX',                                                               'gdmsintegration'),
                'alertCategory'   => __('Category',                                                              'gdmsintegration'),
                'alertReason'     => __('Reason',                                                                'gdmsintegration'),
                'wanDhcp'         => __('DHCP',                                                                  'gdmsintegration'),
                'wanStatic'       => __('Static',                                                                'gdmsintegration'),
                'wanPppoe'        => __('PPPoE',                                                                 'gdmsintegration'),
                'wanPptp'         => __('PPTP',                                                                  'gdmsintegration'),
                'wanL2tp'         => __('L2TP',                                                                  'gdmsintegration'),
                'upgrading'       => __('Updating…',                                                             'gdmsintegration'),
                'deviceName'      => __('Device',                                                                'gdmsintegration'),
                'fwPrivateIp'     => __('Private IP',                                                            'gdmsintegration'),
                'fwMacCopied'     => __('Copied!',                                                               'gdmsintegration'),
                'fwPageLbl'       => __('Firmware downloads',                                                    'gdmsintegration'),
                'fwUpdateTitle'   => __('Firmware Update Available',                                             'gdmsintegration'),
                'fwChecking'      => __('Checking firmware…',                                                    'gdmsintegration'),
                'fwScheduleFor'   => __('Schedule for',                                                          'gdmsintegration'),
                'fwShowPicker'    => __('Show date picker',                                                      'gdmsintegration'),
                'fwLeaveEmpty'    => __('Leave empty to apply as soon as possible',                              'gdmsintegration'),
                'close'           => __('Close',                                                                 'gdmsintegration'),
                'portStatus'      => __('Port Status',                                                           'gdmsintegration'),
                'upload'          => __('Upload',                                                                'gdmsintegration'),
                'download'        => __('Download',                                                              'gdmsintegration'),
                'gateway'         => __('Gateway',                                                               'gdmsintegration'),
                'dns'             => __('DNS',                                                                   'gdmsintegration'),
                'wanMac'          => __('WAN MAC',                                                               'gdmsintegration'),
                'txrxPkts'        => __('TX / RX packets',                                                       'gdmsintegration'),
                'linkSpeed'       => __('Link speed',                                                            'gdmsintegration'),
                'description'     => __('Description',                                                           'gdmsintegration'),
                'connectedClients'=> __('Connected Clients',                                                     'gdmsintegration'),
                'sipRegistered'   => __('Registered',                                                            'gdmsintegration'),
                'sipUnregistered' => __('Unregistered',                                                          'gdmsintegration'),
                'sipStatusLbl'    => __('SIP Status',                                                            'gdmsintegration'),
                'extension'       => __('Extension',                                                             'gdmsintegration'),
                'site'            => __('Site',                                                                  'gdmsintegration'),
                'macLbl'          => __('MAC',                                                                   'gdmsintegration'),
                'copyMac'         => __('Copy MAC',                                                              'gdmsintegration'),
                'publicIpLbl'     => __('Public IP',                                                             'gdmsintegration'),
                'lastSeenCap'     => __('Last Seen',                                                             'gdmsintegration'),
                'pbxUcm'          => __('PBX / UCM',                                                             'gdmsintegration'),
                'dndLbl'          => __('Do Not Disturb',                                                        'gdmsintegration'),
                'active'          => __('Active',                                                                'gdmsintegration'),
                'off'             => __('Off',                                                                   'gdmsintegration'),
                'synchronized'    => __('Synchronized',                                                          'gdmsintegration'),
                'yes'             => __('Yes',                                                                   'gdmsintegration'),
                'no'              => __('No',                                                                    'gdmsintegration'),
                'syncError'       => __('Sync Error',                                                            'gdmsintegration'),
                'scheduledTask'   => __('Scheduled Task',                                                        'gdmsintegration'),
                'pending'         => __('Pending',                                                               'gdmsintegration'),
                'none'            => __('None',                                                                  'gdmsintegration'),
                'factoryResetBtn'        => __('Factory Reset',                                                          'gdmsintegration'),
                'factoryResetWarning'    => __('WARNING: Factory reset will permanently erase ALL device configuration, SIP accounts, contacts, and customizations. The device will restart and return to factory defaults. This action cannot be undone.', 'gdmsintegration'),
                'factoryResetConfirmYes' => __('I understand — Reset now',                                               'gdmsintegration'),
                'factoryResetOk'         => __('Factory reset task created. Device will restart and restore factory defaults.', 'gdmsintegration'),
                'factoryResetting'       => __('Resetting…',                                                              'gdmsintegration'),
                'rebootBtn'       => __('Reboot',                                                                 'gdmsintegration'),
                'rebootConfirm'   => __('Reboot this device?',                                                    'gdmsintegration'),
                'rebootConfirmYes'=> __('Yes, reboot now',                                                        'gdmsintegration'),
                'rebootOk'        => __('Reboot task created. Device will restart shortly.',                      'gdmsintegration'),
                'rebooting'       => __('Rebooting…',                                                             'gdmsintegration'),
                'confirm'         => __('Confirm',                                                                'gdmsintegration'),
                'cancel'          => __('Cancel',                                                                 'gdmsintegration'),
            ];

            $json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
            $js_json = [
                'strings'        => json_encode($js_strings, $json_flags),
                'nodes'          => json_encode(array_values($nodes), $json_flags),
                'edges'          => json_encode(array_values($edges), $json_flags),
                'chart_labels'   => json_encode($chart_labels, $json_flags),
                'chart_datasets' => json_encode($chart_datasets, $json_flags),
                'urls'           => array_map(static fn(string $url): string => json_encode($url, $json_flags), $urls),
            ];

            echo \GlpiPlugin\Gdmsintegration\Twig::get()->render('dashboard.html.twig', [
                'is_configured'    => true,
                'entities_id'      => $entities_id,
                'rows'             => $rows,
                'cards'            => $cards,
                'summary'          => $summary,
                'net_stats'        => $net_stats,
                'online'           => $online,
                'offline'          => $offline,
                'total'            => $total,
                'pct'              => $pct,
                'total_networks'   => $total_networks,
                'critical_devices' => $critical_devices,
                'ops_summary'      => $ops_summary,
                'chart_datasets'   => $chart_datasets,
                'chart_labels'     => $chart_labels,
                'nodes'            => array_values($nodes),
                'edges'            => array_values($edges),
                'show_topology'    => $show_topology,
                'chart_days'       => $chart_days,
                'has_gwn_config'   => !empty($config['gwn_client_id']),
                'js_strings'       => $js_strings,
                'urls'             => $urls,
                'js_json'          => $js_json,
                'fp_lang'          => $_fp_lang,
                'fp_region'        => $_fp_region,
                'refresh_interval' => $refresh_interval,
                'last_sync_label'  => $last_sync_label,
                'last_sync_at'     => $last_sync_at ?? '',
                'visnetwork_url'   => $_plugin_web . '/js/vis-network.min.js?v=10.1.1',
                'config_url'       => $_plugin_web . '/config',
            ]);

            \Html::footer();
            return new \Symfony\Component\HttpFoundation\Response(ob_get_clean());
    }
}
