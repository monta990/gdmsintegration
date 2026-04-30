<?php
/**
 * GDMS Integration — NOC Dashboard
 */
global $CFG_GLPI;

Session::checkLoginUser();
if (!Session::haveRight('config', READ) && !Session::haveRight('networking', READ)) {
    Html::forbidden();
    return;
}

Html::requireJs('charts');    // ECharts 5 — lib/echarts.js
Html::requireJs('flatpickr'); // Flatpickr — lib/flatpickr.js

Html::header(
    'GDMS — ' . __('Dashboard', 'gdmsintegration'),
    '',
    'tools',
    'PluginGdmsintegrationMenu'
);

$entities_id = (int) ($_SESSION['glpiactive_entity'] ?? 0);
// AJAX URL built inline in JS

// Guard: no config
$config_obj       = new PluginGdmsintegrationConfig();
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

// Flatpickr locale — extract language and region from GLPI session
$_fp_lang   = 'en';
$_fp_region = '';
if (!empty($_SESSION['glpilanguage'])) {
    $_fp_parts  = explode('_', $_SESSION['glpilanguage']);
    $_fp_lang   = strtolower($_fp_parts[0] ?? 'en');
    $_fp_region = strtoupper($_fp_parts[1] ?? '');
}

if (!$is_configured) {
    $config_url = '/plugins/gdmsintegration/front/config.form.php';
    echo '<div class="container-xl mt-5">';
    echo '   <div class="card border-0 shadow-sm mx-auto" style="max-width:540px;">';
    echo '      <div class="card-body text-center py-5 px-4">';
    echo '         <i class="ti ti-plug text-secondary mb-3" style="font-size:3rem;"></i>';
    echo '         <h4 class="fw-bold mb-2">' . __('GDMS not configured yet', 'gdmsintegration') . '</h4>';
    echo '         <p class="text-muted mb-4">' . __('To start syncing your Grandstream devices, you need to connect your GDMS Cloud account. It only takes a moment.', 'gdmsintegration') . '</p>';
    echo '         <a href="' . htmlspecialchars($config_url, ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary px-4">';
    echo '            <i class="ti ti-settings me-2"></i>' . __('Set up GDMS', 'gdmsintegration');
    echo '         </a>';
    echo '      </div>';
    echo '   </div>';
    echo '</div>';
    Html::footer();
    return;
}

// Load GDMS-managed devices from the plugin's own device-state table.
// This is the authoritative list of what GDMS manages — NOT all GLPI assets.
$online  = 0;
$offline = 0;
$rows    = [];
$root    = rtrim($CFG_GLPI['root_doc'] ?? '', '/');

$state_obj  = new PluginGdmsintegrationDevice();
// Self-healing for FTP-only deployments — single source of truth in Utils::ensureSchema()
PluginGdmsintegrationUtils::ensureSchema();

$all_states = $state_obj->find(); // every MAC the plugin currently manages in the cloud

// Batch-load uptime for all MACs in one query instead of one query per device
$_all_macs    = array_filter(array_column($all_states, 'mac'));
$_uptime_map  = PluginGdmsintegrationSync::calculateUptimeBatch($_all_macs);

// Build MAC → GLPI asset map across ALL entities (entity 0 vs active entity mismatch)
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
    $sla         = PluginGdmsintegrationSync::slaLabel($uptime);
    $net_name    = htmlspecialchars($state['network_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $ip          = htmlspecialchars($state['ip']           ?? '', ENT_QUOTES, 'UTF-8');
    $firmware         = htmlspecialchars($state['firmware']          ?? '', ENT_QUOTES, 'UTF-8');
    $firmware_latest  = htmlspecialchars($state['firmware_latest']   ?? '', ENT_QUOTES, 'UTF-8');
    $sip_status       = $state['sip_status']     ?? '';
    $sip_extension    = $state['sip_extension'] ?? '';
    $ipv6             = htmlspecialchars($state['ipv6']       ?? '', ENT_QUOTES, 'UTF-8');
    $private_ip       = htmlspecialchars($state['private_ip'] ?? '', ENT_QUOTES, 'UTF-8');
    $location         = htmlspecialchars($state['location']   ?? '', ENT_QUOTES, 'UTF-8');
    $uptime_sec       = (int)($state['uptime_sec']                   ?? 0);
    $sn_cloud         = htmlspecialchars($state['sn_cloud']          ?? '', ENT_QUOTES, 'UTF-8');

    // Find the GLPI asset if it exists
    $glpi     = $mac_to_asset[$mac] ?? null;
    // Name priority: 1) GLPI asset name  2) cloud name stored during sync  3) MAC
    $cloud_name_raw = $state['cloud_name'] ?? '';
    if ($glpi) {
        $name = htmlspecialchars($glpi['name'], ENT_QUOTES, 'UTF-8');
    } elseif ($cloud_name_raw !== '') {
        $name = htmlspecialchars($cloud_name_raw, ENT_QUOTES, 'UTF-8');
    } else {
        $name = htmlspecialchars($mac, ENT_QUOTES, 'UTF-8');
    }
    $serial   = $glpi ? htmlspecialchars($glpi['serial']   ?? '', ENT_QUOTES, 'UTF-8') : '';
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
        'asset_url'    => htmlspecialchars($asset_url, ENT_QUOTES, 'UTF-8'),
        'type'         => $itemtype,
        'model'        => htmlspecialchars($model_name, ENT_QUOTES, 'UTF-8'),
        'mac'          => htmlspecialchars($mac, ENT_QUOTES, 'UTF-8'),
        'serial'       => $sn_cloud ?: $serial,
        'network_name' => $net_name,
        'network_id'   => (int)($state['network_id'] ?? 0),
        'ip'           => $ip,
        'firmware'     => $firmware,
        'uptime_sec'   => $uptime_sec,
        'online'       => $isOnline,
        'uptime'       => $uptime,
        'sla'          => htmlspecialchars($sla, ENT_QUOTES, 'UTF-8'),
        'raw_model'      => $state['model'] ?? '',
        'clients'        => (int)($state['clients']        ?? 0),
        'upload_bytes'   => (int)($state['upload_bytes']   ?? 0),
        'download_bytes' => (int)($state['download_bytes'] ?? 0),
        // WAN aggregate: sum txBytes/rxBytes from all WAN ports (more accurate than ap/list usage field)
        'wan_tx_bytes'   => (function() use ($state): int {
            $ports = json_decode($state['wan_ports_json'] ?? '', true) ?? [];
            $tx = 0; foreach ($ports as $p) { if (($p['role'] ?? 0) == 1) $tx += (int)($p['txBytes'] ?? 0); } return $tx;
        })(),
        'wan_rx_bytes'   => (function() use ($state): int {
            $ports = json_decode($state['wan_ports_json'] ?? '', true) ?? [];
            $rx = 0; foreach ($ports as $p) { if (($p['role'] ?? 0) == 1) $rx += (int)($p['rxBytes'] ?? 0); } return $rx;
        })(),
        'channel_2g'      => (int)($state['channel_2g']     ?? 0),
        'channel_5g'      => (int)($state['channel_5g']     ?? 0),
        'last_seen'       => $state['last_seen']  ?? '',
        'first_seen'      => $state['first_seen'] ?? '',
        'mgmt_ip'         => htmlspecialchars($state['mgmt_ip'] ?? '', ENT_QUOTES, 'UTF-8'),
        'firmware_latest' => $firmware_latest,
        'sip_status'      => $sip_status,
        'sip_extension'   => $sip_extension,
        'ipv6'            => $ipv6,
        'private_ip'      => $private_ip,
        'location'        => $location,
        'sla_rank'        => ($uptime === 0.0 && $isOnline ? 4 : ($uptime >= 99.9 ? 0 : ($uptime >= 99.0 ? 1 : ($uptime >= 95.0 ? 2 : 3)))),
    ];
}

// Default order: network devices first, then phones; within each group sort by name
usort($rows, function(array $a, array $b): int {
    $ta = ($a['type'] === 'Phone') ? 1 : 0;
    $tb = ($b['type'] === 'Phone') ? 1 : 0;
    if ($ta !== $tb) return $ta - $tb;
    return strnatcasecmp($a['name'], $b['name']);
});

// Build per-network device stats for tooltip (router/switch/AP/clients counts)
// Device classification: GWN7001/7002/7003 prefix → router; GWN7800/GSS → switch; GWN76xx → AP; UCM/GCC/GRP/GXP etc → phone/pbx
$net_stats = []; // network_name → [router_on, router_off, switch_on, switch_off, ap_on, ap_off, clients_wired, clients_wireless]
foreach ($rows as $r) {
    $nname = $r['network_name'];
    if ($nname === '') continue;
    if (!isset($net_stats[$nname])) {
        $net_stats[$nname] = ['router_on'=>0,'router_off'=>0,'switch_on'=>0,'switch_off'=>0,'ap_on'=>0,'ap_off'=>0,'phone_on'=>0,'phone_off'=>0,'clients'=>0,'upload_bytes'=>0,'download_bytes'=>0];
    }
    $m   = strtoupper($r['raw_model'] ?? '');
    $on  = $r['online'];
    $isPhone  = ($r['type'] === 'Phone');
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

// Uptime history — last N days per device per day (configurable via chart_days)
$history_obj = new PluginGdmsintegrationHistory();
$history_ago  = gmdate('Y-m-d H:i:s', strtotime("-{$chart_days} days"));
$hist_rows    = $history_obj->find(['date' => ['>', $history_ago]], ['date DESC']);

// Build: mac → (name) and date → per-mac status
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

// Build dataset per device — only include MACs that are currently managed
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

// Topology — only build if show_topology is enabled (saves DB query when disabled)
$nodes = [];
$edges = [];
if ($show_topology) {
    $entity_macs = array_keys($mac_to_asset);
    $link        = new PluginGdmsintegrationLink();
    $links_raw   = empty($entity_macs) ? [] : $link->find(['source_mac' => $entity_macs]);

    foreach ($rows as $r) {
        $nodes[] = [
            'id'    => $r['mac'],
            'label' => $r['name'],
            'color' => ['background' => $r['online'] ? '#28a745' : '#dc3545', 'border' => '#aaa'],
            'font'  => ['color' => '#ffffff'],
            'title' => $r['name'] . ' — ' . ($r['online'] ? 'Online' : 'Offline'),
        ];
    }
    foreach ($links_raw as $l) {
        if (!empty($l['source_mac']) && !empty($l['target_mac'])) {
            $edges[] = ['from' => $l['source_mac'], 'to' => $l['target_mac']];
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
?>
<div class="container-fluid px-4 mt-3">

   <?php // Header card ?>
   <div class="card mb-4">
      <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
         <div class="d-flex align-items-center gap-3">
            <i class="ti ti-antenna text-primary"></i>
            <div>
               <h4 class="mb-0 fw-bold"><?= 'GDMS — ' . __('Dashboard', 'gdmsintegration') ?></h4>
               <small class="text-muted"><?= __('Live view of your Grandstream cloud devices', 'gdmsintegration') ?></small>
            </div>
         </div>
         <div class="d-flex align-items-center gap-2">
            <?php if ($last_sync_label): ?>
            <small class="text-muted" id="gdms-last-sync" title="<?= htmlspecialchars($last_sync_at ?? '', ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($last_sync_label, ENT_QUOTES, 'UTF-8') ?></small>
            <?php endif; ?>
            <small class="text-muted" id="gdms-refresh-countdown"></small>
            <button type="button" id="gdms-refresh-btn" class="btn btn-sm btn-outline-primary">
               <i class="ti ti-refresh me-1" id="gdms-refresh-icon"></i><?= __('Sync now', 'gdmsintegration') ?>
            </button>
            <a href="/plugins/gdmsintegration/front/config.form.php" class="btn btn-sm btn-outline-secondary">
               <i class="ti ti-settings me-1"></i><?= __('Settings', 'gdmsintegration') ?>
            </a>
         </div>
      </div>
   </div>

   <?php // Summary stat cards — Grandstream Cloud style ?>
   <?php
   $total = $online + $offline;
   $pct   = $total > 0 ? round($online / $total * 100) : 0;
   $cards = [
       [
           'icon'    => 'ti-network',
           'label'   => __('Networks', 'gdmsintegration'),
           'vals'    => [['v' => $total_networks, 'lbl' => __('Total', 'gdmsintegration'), 'cls' => '']],
       ],
       [
           'icon'    => 'ti-sitemap',
           'label'   => __('Router', 'gdmsintegration'),
           'vals'    => [
               ['v' => $summary['router_on'],  'lbl' => __('Online', 'gdmsintegration'),  'cls' => 'text-success'],
               ['v' => $summary['router_off'], 'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger'],
           ],
       ],
       [
           'icon'    => 'ti-server',
           'label'   => __('Switch', 'gdmsintegration'),
           'vals'    => [
               ['v' => $summary['switch_on'],  'lbl' => __('Online', 'gdmsintegration'),  'cls' => 'text-success'],
               ['v' => $summary['switch_off'], 'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger'],
           ],
       ],
       [
           'icon'    => 'ti-wifi',
           'label'   => 'AP',
           'vals'    => [
               ['v' => $summary['ap_on'],  'lbl' => __('Online', 'gdmsintegration'),  'cls' => 'text-success'],
               ['v' => $summary['ap_off'], 'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger'],
           ],
       ],
       [
           'icon'    => 'ti-phone',
           'label'   => __('Phones & PBX', 'gdmsintegration'),
           'vals'    => [
               ['v' => $summary['phone_on'],  'lbl' => __('Online', 'gdmsintegration'),  'cls' => 'text-success'],
               ['v' => $summary['phone_off'], 'lbl' => __('Offline', 'gdmsintegration'), 'cls' => 'text-danger'],
           ],
       ],
       [
           'icon'    => 'ti-users',
           'label'   => __('Clients', 'gdmsintegration'),
           'vals'    => [['v' => $summary['clients'], 'lbl' => __('Connected', 'gdmsintegration'), 'cls' => 'text-info']],
       ],
   ];
   ?>

   <div class="row g-2 mb-3">
      <?php foreach ($cards as $card): ?>
      <div class="col-6 col-sm-4 col-md-2">
         <div class="card h-100 text-center py-3 px-2">
            <div class="mb-1">
               <i class="ti <?= $card['icon'] ?> text-primary" style="font-size:2rem;"></i>
            </div>
            <div class="small fw-semibold text-muted mb-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.03em;">
               <?= $card['label'] ?>
            </div>
            <div class="d-flex justify-content-center gap-3">
               <?php foreach ($card['vals'] as $v): ?>
               <div>
                  <div class="fw-bold fs-2 lh-1 <?= $v['cls'] ?>"><?= $v['v'] ?></div>
                  <div class="text-muted" style="font-size:.78rem;"><?= $v['lbl'] ?></div>
               </div>
               <?php endforeach; ?>
            </div>
         </div>
      </div>
      <?php endforeach; ?>
   </div>

   <?php if (!empty($config['gwn_client_id'])): ?>
   <div class="card mb-4" id="gdms-alerts-card">
      <div class="card-header d-flex align-items-center gap-2" role="button"
           data-bs-toggle="collapse" data-bs-target="#gdms-alerts-collapse"
           aria-expanded="false" aria-controls="gdms-alerts-collapse"
           style="cursor:pointer;">
         <i class="ti ti-bell text-warning"></i>
         <h5 class="mb-0 me-auto"><?= __('Cloud Alerts', 'gdmsintegration') ?></h5>
         <small class="text-muted me-2" id="gdms-alerts-meta"></small>
         <i class="ti ti-chevron-down gdms-alerts-chevron" style="transition:transform .2s;"></i>
      </div>
      <div id="gdms-alerts-collapse" class="collapse">
         <div id="gdms-alerts-body" class="card-body py-2">
            <div class="text-center py-2 text-muted small">
               <div class="spinner-border spinner-border-sm me-2"></div>
               <?= __('Loading alerts…', 'gdmsintegration') ?>
            </div>
         </div>
      </div>
   </div>
   <?php endif; ?>

   <?php // Availability bar ?>
   <div class="card mb-3 px-3 py-2">
      <div class="d-flex align-items-center gap-3 flex-wrap">
         <span class="small fw-semibold text-nowrap"><?= __('Overall availability', 'gdmsintegration') ?> — <?= __('all devices', 'gdmsintegration') ?></span>
         <div class="progress flex-grow-1" style="height:10px;min-width:120px;">
            <div class="progress-bar <?= $pct >= 90 ? 'bg-success' : ($pct >= 70 ? 'bg-warning' : 'bg-danger') ?>"
                 style="width:<?= $pct ?>%"></div>
         </div>
         <span class="small fw-bold <?= $pct >= 90 ? 'text-success' : ($pct >= 70 ? 'text-warning' : 'text-danger') ?>"><?= $pct ?>%</span>
         <span class="text-muted small"><?= $online ?> / <?= $total ?> <?= __('online', 'gdmsintegration') ?></span>
      </div>
   </div>

   <?php
   // Critical SLA banner — shown when any device has been persistently offline (Critical SLA tier)
   $critical_devices = array_filter($rows, fn($r) => $r['sla'] === __('Critical', 'gdmsintegration') && !$r['online']);
   if (!empty($critical_devices)):
   ?>
   <div class="alert border-danger mb-3 d-flex align-items-start gap-3" role="alert"
        style="border-left:4px solid var(--bs-danger) !important; background:rgba(220,53,69,.08);">
      <i class="ti ti-alert-triangle text-danger mt-1" style="font-size:1.4rem;flex-shrink:0;"></i>
      <div>
         <strong class="text-danger"><?= __('Critical SLA — devices offline', 'gdmsintegration') ?></strong>
         <div class="mt-1 small">
         <?php foreach ($critical_devices as $cr): ?>
            <span class="me-3">
               <i class="ti ti-circle-x text-danger me-1"></i>
               <?php if (!empty($cr['asset_url'])): ?>
               <a href="<?= $cr['asset_url'] ?>" target="_blank" rel="noopener" class="text-danger fw-semibold text-decoration-none"><?= $cr['name'] ?></a>
               <?php else: ?>
               <span class="fw-semibold"><?= $cr['name'] ?></span>
               <?php endif; ?>
               <span class="text-muted">(<?= $cr['uptime'] ?>% <?= __('uptime', 'gdmsintegration') ?>)</span>
            </span>
         <?php endforeach; ?>
         </div>
      </div>
   </div>
   <?php endif; ?>

   <?php // Device table ?>
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="ti ti-list"></i>
         <h5 class="mb-0 me-auto"><?= __('Devices', 'gdmsintegration') ?></h5>
         <button type="button" id="gdms-sort-reset" class="btn btn-sm btn-outline-secondary" style="display:none;"
                 title="<?= htmlspecialchars(__('Reset sort', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>">
            <i class="ti ti-arrows-sort"></i> <?= __('Reset sort', 'gdmsintegration') ?>
         </button>
      </div>
      <div class="table-responsive">
         <table id="gdms-device-table" class="table table-hover table-sm mb-0">
            <thead>
               <tr>
                  <th class="ps-3 gdms-sortable" data-col="name" style="cursor:pointer;user-select:none;" title="<?= htmlspecialchars(__('Click to sort', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Device Name', 'gdmsintegration') ?> <span class="gdms-sort-icon opacity-40">⇅</span></th>
                  <th class="gdms-sortable" data-col="type" style="cursor:pointer;user-select:none;" title="<?= htmlspecialchars(__('Click to sort', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Type', 'gdmsintegration') ?> <span class="gdms-sort-icon opacity-40">⇅</span></th>
                  <th class="gdms-sortable" data-col="model" style="cursor:pointer;user-select:none;" title="<?= htmlspecialchars(__('Click to sort', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Model', 'gdmsintegration') ?> <span class="gdms-sort-icon opacity-40">⇅</span></th>
                  <th class="gdms-sortable" data-col="network" style="cursor:pointer;user-select:none;" title="<?= htmlspecialchars(__('Click to sort', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Network', 'gdmsintegration') ?> <span class="gdms-sort-icon opacity-40">⇅</span></th>
                  <th><?= __('IP', 'gdmsintegration') ?></th>
                  <th><?= __('MAC Address', 'gdmsintegration') ?></th>
                  <th><?= __('Serial', 'gdmsintegration') ?></th>
                  <th><?= __('Firmware', 'gdmsintegration') ?></th>
                  <th><?= __('Ports', 'gdmsintegration') ?></th>
                  <th><?= __('Uptime', 'gdmsintegration') ?></th>
                  <th class="gdms-sortable" data-col="status" style="cursor:pointer;user-select:none;" title="<?= htmlspecialchars(__('Click to sort', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Status', 'gdmsintegration') ?> <span class="gdms-sort-icon opacity-40">⇅</span></th>
                  <th title="<?= htmlspecialchars(__('Cumulative upload / download since device first registered in cloud. Hover a cell for the exact period.', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>" style="cursor:default;"><?= __('Traffic ↑↓', 'gdmsintegration') ?> <i class="ti ti-info-circle opacity-50" style="font-size:.75em;"></i></th>
                  <th class="gdms-sortable" data-col="clients" style="cursor:pointer;user-select:none;" title="<?= htmlspecialchars(__('Click to sort', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Clients', 'gdmsintegration') ?> <span class="gdms-sort-icon opacity-40">⇅</span></th>
                  <th class="gdms-sortable" data-col="avail" style="cursor:pointer;user-select:none;" title="<?= htmlspecialchars(__('Click to sort', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= __('Avail. %', 'gdmsintegration') ?> <span class="gdms-sort-icon opacity-40">⇅</span></th>
                  <th class="gdms-sortable" data-col="sla" style="cursor:pointer;user-select:none;" title="<?= htmlspecialchars(__('Click to sort', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= __('SLA', 'gdmsintegration') ?> <span class="gdms-sort-icon opacity-40">⇅</span></th>
               </tr>
            </thead>
            <tbody>
               <?php $row_idx = 0; foreach ($rows as $r): $row_idx++; ?>
               <?php
               // Convert uptime seconds → d h m
               $us = (int)($r['uptime_sec'] ?? 0);
               $ud = intdiv($us, 86400);
               $uh = intdiv($us % 86400, 3600);
               $um = intdiv($us % 3600, 60);
               $uptime_str = $ud > 0 ? "{$ud}d {$uh}h {$um}m" : ($uh > 0 ? "{$uh}h {$um}m" : "{$um}m");
               ?>
               <tr data-original-index="<?= $row_idx ?>"
                   data-name="<?= htmlspecialchars(strtolower($r['name']), ENT_QUOTES, 'UTF-8') ?>"
                   data-type="<?= $r['type'] === 'Phone' ? 1 : 0 ?>"
                   data-model="<?= htmlspecialchars(strtolower($r['model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                   data-network="<?= htmlspecialchars(strtolower($r['network_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                   data-status="<?= $r['online'] ? 0 : 1 ?>"
                   data-clients="<?= (int)($r['clients'] ?? 0) ?>"
                   data-avail="<?= (float)($r['uptime'] ?? 0) ?>"
                   data-sla="<?= (int)($r['sla_rank'] ?? 3) ?>">
                  <td class="ps-3">
                     <?php if (!empty($r['asset_url'])): ?>
                     <a href="<?= $r['asset_url'] ?>" target="_blank" rel="noopener" class="fw-semibold text-decoration-none">
                        <?= $r['name'] ?>
                        <i class="ti ti-external-link ms-1 small opacity-50"></i>
                     </a>
                     <?php else: ?>
                     <span class="fw-semibold"><?= $r['name'] ?></span>
                     <?php endif; ?>
                  </td>
                  <td>
                     <?php if ($r['type'] === 'Phone'): ?>
                     <span class="badge border border-primary text-primary"><?= __('Phone', 'gdmsintegration') ?></span>
                     <?php else: ?>
                     <span class="badge border border-secondary" style="color:inherit"><?= __('Network', 'gdmsintegration') ?></span>
                     <?php endif; ?>
                  </td>
                  <td><small class="text-muted font-monospace gdms-copy" style="cursor:pointer;" data-copy="<?= htmlspecialchars($r['model'] ?? '', ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(__('Copy model', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($r['model'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></small></td>
                  <td>
                  <?php
                  $rnet  = $r['network_name'] ?: '';
                  $rstat = $net_stats[$rnet] ?? null;
                  if ($rnet !== '' && $rstat !== null):
                      $netData = json_encode([
                          'name'       => $rnet,
                          'router_on'  => $rstat['router_on'],
                          'router_off' => $rstat['router_off'],
                          'switch_on'  => $rstat['switch_on'],
                          'switch_off' => $rstat['switch_off'],
                          'ap_on'      => $rstat['ap_on'],
                          'ap_off'     => $rstat['ap_off'],
                          'phone_on'   => $rstat['phone_on'],
                          'phone_off'  => $rstat['phone_off'],
                          'clients'    => $rstat['clients'],
                          'upload_bytes'   => $rstat['upload_bytes'],
                          'download_bytes' => $rstat['download_bytes'],
                      ]);
                  ?>
                  <small class="gdms-net-link"
                      data-net="<?= htmlspecialchars($netData, ENT_QUOTES, 'UTF-8') ?>"
                      style="cursor:pointer;color:inherit;border-bottom:1px dashed rgba(128,128,128,.5);"
                      title="<?= htmlspecialchars(__('Click for network details', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>">
                      <?= htmlspecialchars($rnet, ENT_QUOTES, 'UTF-8') ?>
                      <i class="ti ti-info-circle ms-1 opacity-50" style="font-size:.7em;"></i>
                  </small>
                  <?php else: ?>
                  <small><?= $rnet ?: '—' ?></small>
                  <?php endif; ?>
                  </td>
                  <td><small class="font-monospace">
                     <?php if (!empty($r['ip'])): ?>
                     <a href="https://www.whois.com/whois/<?= urlencode($r['ip']) ?>" target="_blank" rel="noopener" class="text-decoration-none" title="<?= htmlspecialchars(__('Public IP', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>">
                        <?= $r['ip'] ?> <i class="ti ti-external-link opacity-50" style="font-size:.65em;"></i>
                     </a>
                     <?php else: ?>—<?php endif; ?>
                     <?php if (!empty($r['private_ip'])): ?>
                     <br><a href="http://<?= urlencode($r['private_ip']) ?>" target="_blank" rel="noopener" class="text-muted text-decoration-none" style="font-size:.85em;" title="<?= htmlspecialchars(__('Private IP', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= $r['private_ip'] ?> <i class="ti ti-external-link opacity-50" style="font-size:.65em;"></i></a>
                     <?php endif; ?>
                     <?php if (!empty($r['ipv6'])): ?>
                     <br><span class="text-muted" style="font-size:.75em;word-break:break-all;" title="IPv6"><?= $r['ipv6'] ?></span>
                     <?php endif; ?>
                  </small></td>
                  <td><code class="small gdms-copy" style="cursor:pointer;" data-copy="<?= htmlspecialchars($r['mac'], ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(__('Copy MAC', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= $r['mac'] ?></code></td>
                  <td><small class="text-muted gdms-copy" style="cursor:pointer;" data-copy="<?= htmlspecialchars($r['serial'] ?? '', ENT_QUOTES, 'UTF-8') ?>" title="<?= htmlspecialchars(__('Copy serial', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"><?= $r['serial'] ?: '—' ?></small></td>
                  <td class="text-nowrap">
                     <?php if (!empty($r['firmware'])): ?>
                     <?php
                     // Show badge immediately when stored firmware_latest differs (GWN sync data).
                     // Also kept hidden for the async Grandstream.com scraper check (UC/phones).
                     $fw_badge_shown = !empty($r['firmware_latest']) && $r['firmware_latest'] !== $r['firmware'];
                     ?>
                     <small class="font-monospace text-muted gdms-fw-badge" style="cursor:pointer;"
                            data-mac="<?= htmlspecialchars(strtolower($r['mac'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-current="<?= htmlspecialchars($r['firmware'], ENT_QUOTES, 'UTF-8') ?>"
                            data-model="<?= htmlspecialchars($r['raw_model'] ?? $r['model'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                            title="<?= __('Click to check firmware', 'gdmsintegration') ?>"><?= $r['firmware'] ?></small>
                     <span class="gdms-fw-badge" style="<?= $fw_badge_shown ? '' : 'display:none; ' ?>cursor:pointer;"
                           data-mac="<?= htmlspecialchars(strtolower($r['mac'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           data-current="<?= htmlspecialchars($r['firmware'], ENT_QUOTES, 'UTF-8') ?>"
                           data-model="<?= htmlspecialchars($r['raw_model'] ?? $r['model'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           title="<?= __('Firmware update available', 'gdmsintegration') ?>">
                        <i class="ti ti-arrow-up-circle text-warning ms-1"></i>
                     </span>
                     <?php else: ?>
                     <small class="font-monospace text-muted">—</small>
                     <?php endif; ?>
                  </td>
                  <td>
                     <?php if ($r['type'] !== 'Phone' && !empty($r['mac'])): ?>
                     <span class="gdms-wan-ports d-flex gap-1 align-items-center flex-nowrap"
                           data-mac="<?= htmlspecialchars(strtolower($r['mac']), ENT_QUOTES, 'UTF-8') ?>"
                           data-upload="<?= (int)($r['upload_bytes']   ?? 0) ?>"
                           data-download="<?= (int)($r['download_bytes'] ?? 0) ?>"
                           data-first-seen="<?= htmlspecialchars($r['first_seen'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           data-last-seen="<?= htmlspecialchars($r['last_seen']  ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <span class="text-muted small">—</span>
                     </span>
                     <?php endif; ?>
                  </td>
                  <td>
                  <?php
                  $uptime_display = $us > 0 ? $uptime_str : '—';
                  $ul  = $r['upload_bytes']   ?? 0;
                  $dl  = $r['download_bytes'] ?? 0;
                  $ch2 = (int)($r['channel_2g'] ?? 0);
                  $ch5 = (int)($r['channel_5g'] ?? 0);
                  $ls  = $r['last_seen'] ?? '';
                  $fmt = function(int $b): string {
                      if ($b >= 1073741824) return round($b/1073741824,1).' GB';
                      if ($b >= 1048576)    return round($b/1048576,1).' MB';
                      return round($b/1024,0).' KB';
                  };
                  $tip_lines = [];
                  if ($ul > 0 || $dl > 0) {
                      $tip_lines[] = __('Network usage','gdmsintegration');
                      $tip_lines[] = __('Upload','gdmsintegration').': ↑ '.$fmt($ul).'  '.__('Download','gdmsintegration').': ↓ '.$fmt($dl);
                  }
                  if ($ch2 > 0 || $ch5 > 0) {
                      $ch_parts = [];
                      if ($ch2 > 0) $ch_parts[] = '2.4GHz ch'.$ch2;
                      if ($ch5 > 0) $ch_parts[] = '5GHz ch'.$ch5;
                      $tip_lines[] = implode('  ', $ch_parts);
                  }
                  if (!empty($r['location']))   $tip_lines[] = __('Location',  'gdmsintegration').': '.$r['location'];
                  if (!empty($r['first_seen'])) $tip_lines[] = __('First seen','gdmsintegration').': '.substr($r['first_seen'],0,16);
                  if ($ls)                      $tip_lines[] = __('Last seen', 'gdmsintegration').': '.substr($ls,0,16);
                  $tip = htmlspecialchars(implode("
", $tip_lines), ENT_QUOTES, 'UTF-8');
                  ?>
                  <?php if ($tip): ?>
                  <small title="<?= $tip ?>" style="cursor:default;border-bottom:1px dotted rgba(128,128,128,.4);"><?= $uptime_display ?></small>
                  <?php else: ?>
                  <small><?= $uptime_display ?></small>
                  <?php endif; ?>
                  </td>
                  <td class="text-nowrap">
                     <span class="badge <?= $r['online'] ? 'bg-success' : 'bg-danger' ?> text-white">
                        <?= $r['online'] ? __('Online', 'gdmsintegration') : __('Offline', 'gdmsintegration') ?>
                     </span>
                     <?php if ($r['type'] === 'Phone' && !empty($r['sip_status'])): ?>
                     <br><span class="badge <?= $r['sip_status'] === 'registered' ? 'bg-primary' : 'bg-secondary' ?> mt-1" style="font-size:.68em;">
                        <?= $r['sip_status'] === 'registered' ? __('SIP Reg', 'gdmsintegration') : __('SIP Unreg', 'gdmsintegration') ?>
                     </span>
                     <?php endif; ?>
                     <?php if (!empty($r['sip_extension'])): ?>
                     <br><span class="badge bg-light text-dark border mt-1" style="font-size:.68em;" title="<?= htmlspecialchars(__('SIP Extension', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($r['sip_extension'], ENT_QUOTES, 'UTF-8') ?>
                     </span>
                     <?php endif; ?>
                  </td>
                  <?php
                  // Traffic column — format upload + download per device
                  $fmtB = function(int $b): string {
                      if ($b >= 1073741824) return round($b/1073741824,1).' GB';
                      if ($b >= 1048576)    return round($b/1048576,1).' MB';
                      if ($b >= 1024)       return round($b/1024,0).' KB';
                      return $b.' B';
                  };
                  // Prefer WAN port aggregate (router WAN total) over ap/list usage (wireless client traffic)
                  $wan_tx = (int)($r['wan_tx_bytes'] ?? 0);
                  $wan_rx = (int)($r['wan_rx_bytes'] ?? 0);
                  $ul_val = $wan_tx > 0 ? $wan_tx : (int)($r['upload_bytes']   ?? 0);
                  $dl_val = $wan_rx > 0 ? $wan_rx : (int)($r['download_bytes'] ?? 0);
                  $traffic_src = $wan_tx > 0
                      ? __('WAN port aggregate (sum of all WAN ports since last router reboot)', 'gdmsintegration')
                      : __('Device-reported traffic (wireless client usage)', 'gdmsintegration');
                  ?>
                  <?php
                  // Traffic tooltip: show period (first_seen → now) and note cumulative
                  $fs_raw = $r['first_seen'] ?? '';
                  if ($fs_raw) {
                      $fs_fmt   = substr($fs_raw, 0, 10);
                      $fs_diff  = max(1, (int)((time() - strtotime($fs_raw)) / 86400));
                      $tip_traffic = sprintf(
                          __('%s — since first seen in cloud (%s, ~%d days)', 'gdmsintegration'),
                          $traffic_src, $fs_fmt, $fs_diff
                      );
                  } else {
                      $tip_traffic = $traffic_src;
                  }
                  ?>
                  <td class="text-nowrap">
                     <?php if ($ul_val > 0 || $dl_val > 0): ?>
                     <small class="d-block"
                            title="<?= htmlspecialchars($tip_traffic, ENT_QUOTES, 'UTF-8') ?>"
                            style="font-size:.72em;cursor:default;border-bottom:1px dotted rgba(128,128,128,.4);">
                        <span class="text-success">↑<?= $fmtB($ul_val) ?></span>
                        <span class="ms-1 text-info">↓<?= $fmtB($dl_val) ?></span>
                     </small>
                     <?php else: ?><small class="text-muted">—</small><?php endif; ?>
                  </td>
                  <td class="text-center">
                     <?php $cli = (int)($r['clients'] ?? 0); ?>
                     <?php if ($cli > 0 && (int)($r['network_id'] ?? 0) > 0): ?>
                     <span class="badge text-bg-info fw-bold gdms-clients-badge"
                           style="cursor:pointer;"
                           data-mac="<?= htmlspecialchars($r['mac'], ENT_QUOTES, 'UTF-8') ?>"
                           data-network-id="<?= (int)($r['network_id'] ?? 0) ?>"
                           data-name="<?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?>"
                           title="<?= __('Click to see connected clients', 'gdmsintegration') ?>"><?= $cli ?></span>
                     <?php elseif ($cli > 0): ?>
                     <span class="badge text-bg-info fw-bold"><?= $cli ?></span>
                     <?php else: ?><small class="text-muted">—</small><?php endif; ?>
                  </td>
                  <td><?= $r['uptime'] ?>%</td>
                  <td><?= $r['sla'] ?></td>
               </tr>
               <?php endforeach; ?>
               <?php if (empty($rows)): ?>
               <tr><td colspan="15" class="text-center text-muted py-3">
                  <?= __('No GDMS devices found. Run a sync first.', 'gdmsintegration') ?>
               </td></tr>
               <?php endif; ?>
            </tbody>
         </table>
      </div>
   </div>

   <?php // Topology ?>
   <?php if (!empty($chart_datasets)): ?>
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="ti ti-chart-line"></i>
         <h5 class="mb-0 me-auto"><?= sprintf(__('Online %% — last %d days', 'gdmsintegration'), $chart_days) ?></h5>
         <a href="<?= ($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/history_export.php?entities_id=' . $entities_id ?>"
            class="btn btn-sm btn-outline-secondary"
            title="<?= __('Export to Excel', 'gdmsintegration') ?>">
            <i class="ti ti-file-spreadsheet me-1"></i><?= __('Export', 'gdmsintegration') ?>
         </a>
      </div>
      <div class="card-body p-0">
         <div id="gdms-history-chart" style="height:280px;"></div>
      </div>
   </div>
   <?php endif; ?>

   <?php if ($show_topology): ?>
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="ti ti-hierarchy"></i>
         <h5 class="mb-0"><?= __('Network Topology', 'gdmsintegration') ?></h5>
      </div>
      <div id="gdms-network" style="height:450px; border-radius:0 0 .375rem .375rem; overflow:hidden;"></div>
   </div>
   <?php endif; ?>

</div>

<?php if ($show_topology): ?>
<script src="<?= ($CFG_GLPI['root_doc'] ?? '') ?>/plugins/gdmsintegration/front/visnetwork.php"></script>
<?php endif; ?>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>
<script>
(function () {
    'use strict';

    // All translated strings safely encoded — apostrophes in translations won't break JS
    var STR = {
        syncing:       <?= json_encode(__('Syncing…',                                                              'gdmsintegration')) ?>,
        rebootWarning: <?= json_encode(__('The device will reboot during the update. Schedule during a maintenance window.', 'gdmsintegration')) ?>,
        reqFailed:     <?= json_encode(__('Request failed. Check connection.',                                           'gdmsintegration')) ?>,
        schedOk:       <?= json_encode(__('Update scheduled successfully. The device will update shortly.',              'gdmsintegration')) ?>,
        applyAsap:     <?= json_encode(__('Apply now (ASAP)',                                                            'gdmsintegration')) ?>,
        schedUpdate:   <?= json_encode(__('Schedule update',                                                             'gdmsintegration')) ?>,
        scheduling:    <?= json_encode(__('Scheduling…',                                                            'gdmsintegration')) ?>,
        linkDown:      <?= json_encode(__('Link down',                                                                   'gdmsintegration')) ?>,
        linkUp:        <?= json_encode(__('Link up',                                                                     'gdmsintegration')) ?>,
        online:        <?= json_encode(__('Online',                                                                      'gdmsintegration')) ?>,
        noInternet:    <?= json_encode(__('No internet',                                                                 'gdmsintegration')) ?>,
        noPorts:       <?= json_encode(__('No ports found',                                                              'gdmsintegration')) ?>,
        netUsage:      <?= json_encode(__('Network usage',                                                               'gdmsintegration')) ?>,
        netTraffic:    <?= json_encode(__('Network traffic',                                                             'gdmsintegration')) ?>,
        upload:        <?= json_encode(__('Upload',                                                                      'gdmsintegration')) ?>,
        download:      <?= json_encode(__('Download',                                                                    'gdmsintegration')) ?>,
        firstSeen:     <?= json_encode(__('First seen',                                                                  'gdmsintegration')) ?>,
        lastSeen:      <?= json_encode(__('Last seen',                                                                   'gdmsintegration')) ?>,
        wanOnline:     <?= json_encode(__('WAN online',                                                                  'gdmsintegration')) ?>,
        wanNoInet:     <?= json_encode(__('WAN up, no internet',                                                         'gdmsintegration')) ?>,
        wanUnknown:    <?= json_encode(__('WAN up, unknown',                                                             'gdmsintegration')) ?>,
        lanUp:         <?= json_encode(__('LAN up',                                                                      'gdmsintegration')) ?>,
        connection:    <?= json_encode(__('Connection',                                                                  'gdmsintegration')) ?>,
        ipAddress:     <?= json_encode(__('IP Address',                                                                  'gdmsintegration')) ?>,
        wanTypeLbl:    <?= json_encode(__('Type',                                                                        'gdmsintegration')) ?>,
        connectedFor:  <?= json_encode(__('Connected for',                                                               'gdmsintegration')) ?>,
        statusLbl:     <?= json_encode(__('Status',                                                                      'gdmsintegration')) ?>,
        linkSpeed:     <?= json_encode(__('Link speed',                                                                  'gdmsintegration')) ?>,
        portType:      <?= json_encode(__('Port type',                                                                   'gdmsintegration')) ?>,
        negotiatedSpeed: <?= json_encode(__('Negotiated speed',                                                          'gdmsintegration')) ?>,
        portLabel:     <?= json_encode(__('Port label',                                                                  'gdmsintegration')) ?>,
        description:   <?= json_encode(__('Description',                                                                 'gdmsintegration')) ?>,
        unknown:       <?= json_encode(__('Unknown',                                                                     'gdmsintegration')) ?>,
        official:      <?= json_encode(__('Official',                                                                    'gdmsintegration')) ?>,
        officialFw:    <?= json_encode(__('Official firmware',                                                              'gdmsintegration')) ?>,
        betaFw:        <?= json_encode(__('Beta firmware',                                                                  'gdmsintegration')) ?>,
        gdmsManaged:   <?= json_encode(__('GDMS managed',                                                                   'gdmsintegration')) ?>,
        gdmsVersionNote: <?= json_encode(__('GDMS applies the latest firmware available in its repository. The selected version is informational only.', 'gdmsintegration')) ?>,
        selectVersion: <?= json_encode(__('Select version',                                                                 'gdmsintegration')) ?>,
        curFw:         <?= json_encode(__('Current firmware',                                                            'gdmsintegration')) ?>,
        latestFw:      <?= json_encode(__('Latest stable firmware',                                                      'gdmsintegration')) ?>,
        deviceMac:     <?= json_encode(__('Device MAC',                                                                  'gdmsintegration')) ?>,
        hdx:           <?= json_encode(__('HDX',                                                                         'gdmsintegration')) ?>,
        fdx:           <?= json_encode(__('FDX',                                                                         'gdmsintegration')) ?>,
        disconnected:  <?= json_encode(__('Disconnected',                                                                'gdmsintegration')) ?>,
        lbl_online:    <?= json_encode(__('online',                                                                      'gdmsintegration')) ?>,
        lbl_offline:   <?= json_encode(__('offline',                                                                     'gdmsintegration')) ?>,
        lbl_total:     <?= json_encode(__('Total',                                                                       'gdmsintegration')) ?>,
        lbl_router:    <?= json_encode(__('Router',                                                                      'gdmsintegration')) ?>,
        lbl_switch:    <?= json_encode(__('Switch',                                                                      'gdmsintegration')) ?>,
        lbl_ap:        <?= json_encode(__('AP',                                                                          'gdmsintegration')) ?>,
        lbl_phones:    <?= json_encode(__('Phones & PBX',                                                                'gdmsintegration')) ?>,
        lbl_clients:   <?= json_encode(__('Clients',                                                                     'gdmsintegration')) ?>,
        noClients:     <?= json_encode(__('No clients connected',                                                          'gdmsintegration')) ?>,
        noAlerts:      <?= json_encode(__('No recent alerts',                                                              'gdmsintegration')) ?>,
        alertsError:   <?= json_encode(__('Could not load alerts',                                                        'gdmsintegration')) ?>,
        alertTime:     <?= json_encode(__('Time',                                                                         'gdmsintegration')) ?>,
        alertSev:      <?= json_encode(__('Severity',                                                                     'gdmsintegration')) ?>,
        alertDevice:   <?= json_encode(__('Device',                                                                       'gdmsintegration')) ?>,
        alertMsg:      <?= json_encode(__('Alert',                                                                        'gdmsintegration')) ?>,
        alertDismiss:  <?= json_encode(__('Dismiss',                                                                      'gdmsintegration')) ?>,
        hostname:      <?= json_encode(__('Hostname',                                                                     'gdmsintegration')) ?>,
        band:          <?= json_encode(__('Band',                                                                         'gdmsintegration')) ?>,
        signal:        <?= json_encode(__('Signal',                                                                       'gdmsintegration')) ?>,
        txrx:          <?= json_encode(__('TX / RX',                                                                      'gdmsintegration')) ?>,
        gateway:       <?= json_encode(__('Gateway',                                                                      'gdmsintegration')) ?>,
        dns:           <?= json_encode(__('DNS',                                                                          'gdmsintegration')) ?>,
        wanMac:        <?= json_encode(__('WAN MAC',                                                                      'gdmsintegration')) ?>,
        txrxPkts:      <?= json_encode(__('Packets ↑↓',                                                                  'gdmsintegration')) ?>,
        alertCategory: <?= json_encode(__('Category',                                                                     'gdmsintegration')) ?>,
        alertDevice:   <?= json_encode(__('Device',                                                                       'gdmsintegration')) ?>,
        alertReason:   <?= json_encode(__('Reason',                                                                       'gdmsintegration')) ?>,
        wanDhcp:       <?= json_encode(__('DHCP',                                                                         'gdmsintegration')) ?>,
        wanStatic:     <?= json_encode(__('Static',                                                                       'gdmsintegration')) ?>,
        wanPppoe:      <?= json_encode(__('PPPoE',                                                                        'gdmsintegration')) ?>,
        wanPptp:       <?= json_encode(__('PPTP',                                                                         'gdmsintegration')) ?>,
        wanL2tp:       <?= json_encode(__('L2TP',                                                                         'gdmsintegration')) ?>,
    };


    // Byte formatter — used in port modal and network modal
    function fmtBytes(b) {
        if (b >= 1073741824) return (b/1073741824).toFixed(1)+' GB';
        if (b >= 1048576)    return (b/1048576).toFixed(1)+' MB';
        if (b >= 1024)       return Math.round(b/1024)+' KB';
        return b+' B';
    }

    // Dark theme detection — check actual computed background color
    var _bg    = window.getComputedStyle(document.body).backgroundColor;
    var _rgb   = _bg.match(/\d+/g);
    var isDark = (_rgb && parseInt(_rgb[0]) < 100)
              || document.documentElement.getAttribute('data-bs-theme') === 'dark';
    var netBg  = isDark ? '#1e2433' : '#f8f9fa';

    // History chart rendered via <script type="module"> below (ensures echarts is loaded)

    // Export handled server-side by history_export.php

    <?php if ($show_topology): ?>
    // vis-network topology
    var container = document.getElementById('gdms-network');
    if (container) {
        container.style.backgroundColor = netBg;
        var nodes = new vis.DataSet(<?= json_encode(array_values($nodes)) ?>);
        var edges = new vis.DataSet(<?= json_encode(array_values($edges)) ?>);
        new vis.Network(container, { nodes:nodes, edges:edges }, {
            physics: { stabilization:{ iterations:150 } },
            edges:   { arrows:'to', color:'#888' },
            nodes:   { shape:'dot', size:18 }
        });
    }
    <?php endif; ?>

    // Non-blocking sync: fires request then reloads after 2s regardless
    // PHP sets ignore_user_abort so it keeps running even after reload
    var AJAX_URL       = '<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/sync.ajax.php?entities_id=' . $entities_id, ENT_QUOTES, 'UTF-8') ?>';
    var AJAX_URL_BTN   = AJAX_URL + '&source=button';
    var AJAX_URL_AUTO  = AJAX_URL + '&source=auto-refresh';
    var REFRESH_S      = <?= (int)$refresh_interval ?>;
    var gdmsCountdown  = REFRESH_S;
    var gdmsSyncing    = false;
    var countEl        = document.getElementById('gdms-refresh-countdown');
    var btn            = document.getElementById('gdms-refresh-btn');
    var icon           = document.getElementById('gdms-refresh-icon');

    function fmtTime(s) { return s >= 60 ? Math.floor(s/60)+'m '+(s%60)+'s' : s+'s'; }

    function doSync(source) {
        if (gdmsSyncing) return;
        gdmsSyncing = source || 'auto';
        if (icon) icon.className = 'ti ti-refresh me-1';
        if (icon) icon.style.cssText = 'animation:spin .8s linear infinite;display:inline-block;';
        if (btn)  btn.disabled = true;
        if (countEl) countEl.textContent = STR.syncing;
        var syncUrl = (gdmsSyncing === 'btn') ? AJAX_URL_BTN : AJAX_URL_AUTO;
        fetch(syncUrl, { credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(){ location.reload(); })
            .catch(function(){ location.reload(); });
    }

    if (btn) btn.addEventListener('click', function(){ gdmsCountdown = REFRESH_S; doSync('btn'); });

    setInterval(function() {
        if (gdmsSyncing) return;
        if (countEl) countEl.textContent = fmtTime(gdmsCountdown);
        if (--gdmsCountdown < 0) { gdmsCountdown = REFRESH_S; doSync(); }
    }, 1000);

    // ── Firmware update check ─────────────────────────────────────────────────
    var FW_URL             = '<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/firmware.ajax.php?action=check&entities_id=' . $entities_id, ENT_QUOTES, 'UTF-8') ?>';
    var FW_UPGRADE_URL     = '<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/firmware.ajax.php?action=upgrade&entities_id=' . $entities_id, ENT_QUOTES, 'UTF-8') ?>';
    var FW_CHECK_ALL_URL   = '<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/firmware.ajax.php?action=check_all&entities_id=' . $entities_id, ENT_QUOTES, 'UTF-8') ?>';
    var FW_UPGRADE_GDMS_URL= '<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/firmware.ajax.php?action=upgrade_gdms&entities_id=' . $entities_id, ENT_QUOTES, 'UTF-8') ?>';

    // Inject modal HTML once
    var modalHtml = `
<div class="modal fade" id="gdmsFwModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:min(540px,94vw);">
    <div class="modal-content">
      <div class="modal-header py-3">
        <h5 class="modal-title">
          <i class="ti ti-arrow-up-circle text-warning me-2"></i>
          <?= __('Firmware Update Available', 'gdmsintegration') ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="gdmsFwModalBody">
        <div class="d-flex align-items-center gap-2">
          <div class="spinner-border spinner-border-sm" role="status"></div>
          <span><?= __('Checking firmware…', 'gdmsintegration') ?></span>
        </div>
      </div>
      <div class="modal-footer flex-column align-items-stretch gap-2 pt-2">
        <div id="gdmsFwScheduleRow" style="display:none;">
          <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 text-nowrap small fw-semibold"><?= __('Schedule for', 'gdmsintegration') ?>:</label>
            <div class="btn-group flex-grow-1 flatpickr" id="gdmsFwFlatpickrWrap">
              <input type="text" class="form-control form-control-sm rounded-start ps-2"
                     id="gdmsFwDatetime" data-input placeholder="dd/mm/yyyy hh:mm" autocomplete="off">
              <button type="button" class="btn btn-outline-secondary btn-sm" data-toggle
                      title="<?= __('Show date picker', 'gdmsintegration') ?>">
                <i class="ti ti-calendar-time"></i>
              </button>
            </div>
          </div>
          <small class="text-muted d-block mt-1"><?= __('Leave empty to apply as soon as possible', 'gdmsintegration') ?></small>
        </div>
        <div class="d-flex flex-column gap-2 w-100">
          <button type="button" class="btn btn-success text-white w-100" id="gdmsFwAsapBtn" style="display:none;">
            <i class="ti ti-bolt me-1"></i><?= __('Apply now (ASAP)', 'gdmsintegration') ?>
          </button>
          <button type="button" class="btn btn-warning text-dark w-100" id="gdmsFwScheduleBtn" style="display:none;">
            <i class="ti ti-calendar-check me-1"></i><?= __('Schedule update', 'gdmsintegration') ?>
          </button>
          <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">
            <?= __('Close', 'gdmsintegration') ?>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    var fwData = {};  // mac → {currentVersion, latestVersion, hasUpdate}

    // Fetch firmware info for ALL devices 2s after page load (non-blocking)
    setTimeout(function() {
        fetch(FW_CHECK_ALL_URL, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(list) {
                if (!Array.isArray(list)) return;
                list.forEach(function(item) {
                    fwData[item.mac] = item; // store always, badge shown only if hasUpdate
                    if (!item.hasUpdate) return;
                    document.querySelectorAll('.gdms-fw-badge[data-mac="' + item.mac + '"]')
                        .forEach(function(el) { el.style.display = 'inline'; });
                });
            })
            .catch(function() { /* silently ignore if APIs not configured */ });
    }, 2000);

    // Click on badge → open modal with details
    document.addEventListener('click', function(e) {
        var badge = e.target.closest('.gdms-fw-badge');
        if (!badge) return;
        var mac     = badge.getAttribute('data-mac');
        var current = badge.getAttribute('data-current');
        var model   = badge.getAttribute('data-model') || '';
        var info    = fwData[mac] || {};
        var isGwn   = info.isGwn || /^GWN|^GSS/i.test(model);
        var official= info.official || null;
        var beta    = info.beta     || null;
        // For GWN: official comes from GWN Cloud API (no beta available via API)
        // For UC/phones: both official and beta come from grandstream.com scraper
        var latestFw = info.latestVersion || official || beta || null; // legacy fallback

        var body     = document.getElementById('gdmsFwModalBody');
        var schedBtn = document.getElementById('gdmsFwScheduleBtn');
        var asapBtn  = document.getElementById('gdmsFwAsapBtn');
        var schedRow = document.getElementById('gdmsFwScheduleRow');
        var dtInput  = document.getElementById('gdmsFwDatetime');
        var fpWrap   = document.getElementById('gdmsFwFlatpickrWrap');

        var esc = function(s){ var d = document.createElement('div'); d.appendChild(document.createTextNode(String(s))); return d.innerHTML; };

        // Build version selector rows
        var versionRows = '';
        if (official) {
            versionRows += '<tr>'
              + '<td class="pe-2"><label class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer;">'
              + '<input type="radio" name="gdmsFwVersion" value="' + esc(official) + '" class="form-check-input mt-0"' + (official ? ' checked' : '') + '>'
              + '<code class="text-warning">' + esc(official) + '</code>'
              + ' <span class="badge bg-success ms-1">' + STR.officialFw + '</span>'
              + '</label></td></tr>';
        }
        if (beta && beta !== official && isGwn) {
            // Beta only shown for GWN devices — GDMS ignores fwVersion for UCM/phones
            versionRows += '<tr>'
              + '<td class="pe-2"><label class="d-flex align-items-center gap-2 mb-0" style="cursor:pointer;">'
              + '<input type="radio" name="gdmsFwVersion" value="' + esc(beta) + '" class="form-check-input mt-0"' + (!official ? ' checked' : '') + '>'
              + '<code class="fw-semibold" style="color:var(--bs-warning-text,var(--bs-warning,#fd7e14));">' + esc(beta) + '</code>'
              + ' <span class="badge bg-warning text-dark ms-1">' + STR.betaFw + '</span>'
              + '</label></td></tr>';
        } else if (beta && beta !== official && !isGwn) {
            // For GDMS devices: show beta info but note GDMS controls actual version
            versionRows += '<tr>'
              + '<td class="pe-2 small" style="color:var(--bs-secondary-color,#6c757d);">'
              + '<i class="ti ti-info-circle me-1"></i>'
              + STR.betaFw + ': <code style="color:inherit;">' + esc(beta) + '</code>'
              + ' <span class="badge ms-1" style="background:var(--bs-primary,#006eca);color:#fff;">' + STR.gdmsManaged + '</span>'
              + '</td></tr>';
        }
        if (!official && !beta && latestFw) {
            // Legacy: GWN check action only returned latestVersion
            versionRows += '<tr><td><code class="text-warning">' + esc(latestFw) + '</code>'
              + ' <span class="badge bg-success ms-1">' + STR.officialFw + '</span></td></tr>';
        }

        body.innerHTML = '<table class="table table-sm mb-0">'
          + '<tr><th class="text-muted fw-normal w-50">' + STR.curFw + '</th>'
          + '<td><code>' + esc(current) + '</code></td></tr>'
          + '<tr><th class="text-muted fw-normal align-top pt-2">' + STR.selectVersion + '</th>'
          + '<td>' + (versionRows ? '<table class="mb-0">' + versionRows + '</table>' : '<span class="text-muted">—</span>') + '</td></tr>'
          + '<tr><th class="text-muted fw-normal">' + STR.deviceMac + '</th>'
          + '<td><code>' + esc(mac.toUpperCase()) + '</code></td></tr>'
          + '</table>'
          + '<div class="alert alert-warning mt-3 mb-0 py-2 small">'
          + '<i class="ti ti-alert-triangle me-1"></i>'
          + STR.rebootWarning
          + '</div>'
          + (!isGwn ? '<div class="alert alert-info mt-2 mb-0 py-2 small">'
            + '<i class="ti ti-info-circle me-1"></i>'
            + STR.gdmsVersionNote
            + '</div>' : '');

        // Init / reset flatpickr — GLPI native pattern (wrap:true, CustomFlatpickrButtons)
        if (!fpWrap._fp) {
            var _fpNow = new Date();
            fpWrap._fp = flatpickr(fpWrap, {
                wrap:          true,
                enableTime:    true,
                dateFormat:    'Y-m-d H:i:S',
                altInput:      true,
                altFormat:     'd/m/Y H:i',
                time_24hr:     true,
                minDate:       new Date(Date.now() + 5 * 60 * 1000),
                defaultHour:   _fpNow.getHours(),
                defaultMinute: _fpNow.getMinutes(),
                defaultSeconds: 0,
                locale: typeof getFlatPickerLocale === 'function'
                    ? getFlatPickerLocale(
                        '<?= htmlspecialchars($_fp_lang, ENT_QUOTES) ?>',
                        '<?= htmlspecialchars($_fp_region, ENT_QUOTES) ?>'
                      )
                    : { firstDayOfWeek: 1 },
                plugins: typeof CustomFlatpickrButtons === 'function'
                    ? [CustomFlatpickrButtons()] : []
            });
        } else {
            var _fpNow2 = new Date();
            fpWrap._fp.set('defaultHour',   _fpNow2.getHours());
            fpWrap._fp.set('defaultMinute', _fpNow2.getMinutes());
            fpWrap._fp.set('minDate', new Date(Date.now() + 5 * 60 * 1000));
            fpWrap._fp.clear();
        }
        schedRow.style.display = '';
        asapBtn.style.display  = 'inline-block';
        schedBtn.style.display = 'inline-block';
        asapBtn.disabled  = false;
        schedBtn.disabled = false;
        asapBtn.innerHTML  = '<i class="ti ti-bolt me-1"></i>' + STR.applyAsap;
        schedBtn.innerHTML = '<i class="ti ti-calendar-check me-1"></i>' + STR.schedUpdate;

        function getSelectedVersion() {
            var radio = body.querySelector('input[name="gdmsFwVersion"]:checked');
            return radio ? radio.value : (latestFw || '');
        }

        function doUpgrade(scheduleMs) {
            var version = getSelectedVersion();
            if (!version) return;
            var activeBtn = scheduleMs > 0 ? schedBtn : asapBtn;
            asapBtn.disabled = true; schedBtn.disabled = true;
            activeBtn.innerHTML = '<i class="ti ti-loader me-1" style="animation:spin .8s linear infinite;display:inline-block;"></i>' + STR.scheduling;

            var csrfValue = (typeof window.glpiGetNewCSRFToken === 'function')
                ? window.glpiGetNewCSRFToken()
                : (document.querySelector('meta[property="glpi:csrf_token"]') || {}).getAttribute('content') || '';

            var fetchUrl, fetchBody, fetchHeaders;

            if (isGwn) {
                // GWN: POST to upgrade action with macs array (existing path)
                var formData = new FormData();
                formData.append('_glpi_csrf_token', csrfValue);
                formData.append('macs', JSON.stringify([mac.replace(/:/g, '').toUpperCase()]));
                if (scheduleMs > 0) formData.append('scheduleTimeMs', scheduleMs);
                fetchUrl     = FW_UPGRADE_URL;
                fetchBody    = formData;
                fetchHeaders = {};
            } else {
                // UC/phones: POST to upgrade_gdms with mac + version
                var formData2 = new FormData();
                formData2.append('_glpi_csrf_token', csrfValue);
                formData2.append('mac', mac);
                formData2.append('version', version);
                if (scheduleMs > 0) formData2.append('scheduleMs', scheduleMs);
                fetchUrl     = FW_UPGRADE_GDMS_URL;
                fetchBody    = formData2;
                fetchHeaders = {};
            }

            fetch(fetchUrl, { method: 'POST', credentials: 'same-origin', body: fetchBody })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.error) {
                        var errDiv = document.createElement('div');
                        errDiv.className = 'alert alert-danger mt-2 mb-0 py-2 small';
                        errDiv.textContent = resp.error;
                        errDiv.insertAdjacentHTML('afterbegin', '<i class="ti ti-circle-x me-1"></i> ');
                        body.appendChild(errDiv);
                        asapBtn.disabled = false; schedBtn.disabled = false;
                        asapBtn.innerHTML  = '<i class="ti ti-bolt me-1"></i>' + STR.applyAsap;
                        schedBtn.innerHTML = '<i class="ti ti-calendar-check me-1"></i>' + STR.schedUpdate;
                    } else {
                        var ok = document.createElement('div');
                        ok.className = 'alert alert-success mt-2 mb-0 py-2 small';
                        ok.innerHTML = '<i class="ti ti-check-circle me-1"></i>' + STR.schedOk;
                        body.appendChild(ok);
                        asapBtn.style.display = 'none'; schedBtn.style.display = 'none';
                        schedRow.style.display = 'none';
                        document.querySelectorAll('.gdms-fw-badge[data-mac="' + mac + '"]')
                            .forEach(function(el) { el.style.display = 'none'; });
                    }
                })
                .catch(function() {
                    var connErr = document.createElement('div');
                    connErr.className = 'alert alert-danger mt-2 mb-0 py-2 small';
                    connErr.textContent = STR.reqFailed;
                    body.appendChild(connErr);
                    asapBtn.disabled = false; schedBtn.disabled = false;
                    asapBtn.innerHTML  = '<i class="ti ti-bolt me-1"></i>' + STR.applyAsap;
                    schedBtn.innerHTML = '<i class="ti ti-calendar-check me-1"></i>' + STR.schedUpdate;
                });
        }

        asapBtn.onclick  = function() { doUpgrade(0); };
        schedBtn.onclick = function() {
            var sel = fpWrap._fp && fpWrap._fp.selectedDates[0];
            if (!sel || sel.getTime() <= Date.now()) { doUpgrade(0); return; }
            doUpgrade(sel.getTime());
        };

        var modalEl = document.getElementById('gdmsFwModal');
        if (typeof bootstrap !== 'undefined') {
            new bootstrap.Modal(modalEl).show();
        } else if (typeof $ !== 'undefined') {
            $(modalEl).modal('show');
        }
    });

    // ── WAN port status ─────────────────────────────────────────────────────
    var PORTS_URL = '<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/ports.ajax.php?action=status&entities_id=' . $entities_id, ENT_QUOTES, 'UTF-8') ?>';

    // Port speed map
    // portSpeed encoding per GWN API: 0=no link, 1=10M HDX, 2=10M FDX, 3=100M HDX,
    // 4=100M FDX, 5=1G FDX, 6=2.5G FDX, 7=10G FDX.
    // If the API returns an unexpected value, enable verbose debug logging to see raw portInfo.
    var portSpeeds = {0:'—', 1:'10M '+STR.hdx, 2:'10M '+STR.fdx, 3:'100M '+STR.hdx, 4:'100M '+STR.fdx, 5:'1G '+STR.fdx, 6:'1G '+STR.fdx, 7:'10G '+STR.fdx};
    var wanTypeNames = {0:STR.wanDhcp, 1:STR.wanStatic, 2:STR.wanPppoe, 3:STR.wanPptp, 4:STR.wanL2tp};
    var connectNames = {0:STR.disconnected, 1:STR.online};

    function fmtDuration(secs) {
        if (!secs) return '—';
        var d = Math.floor(secs / 86400), h = Math.floor((secs % 86400) / 3600), m = Math.floor((secs % 3600) / 60);
        var r = '';
        if (d) r += d + 'd ';
        if (h) r += h + 'h ';
        r += m + 'm';
        return r.trim() || '<1m';
    }

    var portModal = document.createElement('div');
    portModal.className = 'modal fade';
    portModal.id = 'gdmsPortModal';
    portModal.setAttribute('tabindex', '-1');
    portModal.setAttribute('aria-hidden', 'true');
    portModal.innerHTML =
      '<div class="modal-dialog modal-dialog-centered modal-lg">' +
      '<div class="modal-content">' +
      '<div class="modal-header">' +
      '<h5 class="modal-title"><i class="ti ti-network me-2 text-primary"></i> ' +
      <?= json_encode(__('Port Status', 'gdmsintegration')) ?> +
      ' <small class="text-muted ms-2" id="gdmsPortModalDevice"></small></h5>' +
      '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
      '</div>' +
      '<div class="modal-body" id="gdmsPortModalBody">' +
      '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>' +
      '</div>' +
      '<div class="modal-footer">' +
      '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' +
      <?= json_encode(__('Close', 'gdmsintegration')) ?> +
      '</button></div></div></div>';
    document.body.appendChild(portModal);

    var portData = {}; // mac → [{id, silk, link, speed, wanName, ip, connectStatus, connectDuration}]

    // Fetch port status 3s after load
    setTimeout(function() {
        fetch(PORTS_URL, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                portData = data || {};
                Object.keys(portData).forEach(function(mac) {
                    var ports = portData[mac];
                    if (!Array.isArray(ports) || !ports.length) return;
                    var container = document.querySelector('.gdms-wan-ports[data-mac="' + mac + '"]');
                    if (!container) return;
                    container.innerHTML = '';
                    ports.forEach(function(p) {
                        var isWan = p.role == 1;
                        var linkUp = p.link == 1;
                        // Color logic:
                        // WAN: green=up+internet, amber=up+no-internet or unknown, gray=down
                        // LAN: teal=up, gray=down
                        var color;
                        if (isWan) {
                            if (!linkUp)       color = '#6c757d'; // gray = link down
                            else if (p.connectStatus === 1) color = '#28a745'; // green = internet confirmed
                            else if (p.connectStatus === 0) color = '#fd7e14'; // orange = up, no internet
                            else               color = '#ffc107'; // amber = up, status unknown
                        } else {
                            color = linkUp ? '#20c997' : '#6c757d'; // teal=up, gray=down
                        }
                        var label = p.silk || p.name || (isWan ? 'WAN' : 'LAN');
                        var wanLabel = p.wanName ? ' — ' + p.wanName : '';
                        var portLabel = p.name && p.name !== p.silk ? ' (' + p.name + ')' : '';
                        var dot = document.createElement('span');
                        var dotStatus;
                        if (!linkUp) {
                            dotStatus = ' — ' + STR.linkDown;
                        } else if (isWan) {
                            dotStatus = p.connectStatus === 1 ? ' — ' + STR.online
                                      : p.connectStatus === 0 ? ' — ' + STR.noInternet
                                      : ' — ' + STR.linkUp;
                        } else {
                            dotStatus = ' — ' + STR.linkUp; // LAN active
                        }
                        dot.title = label + portLabel + wanLabel + dotStatus;
                        dot.style.cssText = 'display:inline-block;width:9px;height:9px;border-radius:50%;background:' + color + ';cursor:pointer;flex-shrink:0' + (isWan ? ';outline:1px solid rgba(255,255,255,.3)' : '');
                        container.appendChild(dot);
                    });
                });
            })
            .catch(function() {});
    }, 3000);

    // Click on port container → open modal
    document.addEventListener('click', function(e) {
        var container = e.target.closest('.gdms-wan-ports');
        if (!container) return;
        var mac   = container.getAttribute('data-mac');
        var ports = portData[mac];
        if (!ports) return;

        // Find device name
        var row   = container.closest('tr');
        var dName = row ? (row.querySelector('td a') || row.querySelector('td')).textContent.trim() : mac;
        document.getElementById('gdmsPortModalDevice').textContent = dName;

        var body  = document.getElementById('gdmsPortModalBody');
        if (!ports.length) { body.innerHTML = '<p class="text-muted text-center">' + STR.noPorts + '</p>'; }
        else {
            // Device info block: traffic + timestamps above legend
            var ulBytes   = parseInt(container.getAttribute('data-upload')    || '0', 10);
            var dlBytes   = parseInt(container.getAttribute('data-download')   || '0', 10);
            var firstSeen = container.getAttribute('data-first-seen') || '';
            var lastSeen  = container.getAttribute('data-last-seen')  || '';
            var infoHtml = '';
            if (ulBytes > 0 || dlBytes > 0 || firstSeen || lastSeen) {
                infoHtml = '<div class="border-bottom pb-2 mb-3 small">';
                if (ulBytes > 0 || dlBytes > 0) {
                    infoHtml += '<div class="fw-semibold text-muted mb-1"><i class="ti ti-chart-bar me-1"></i>' + STR.netUsage + '</div>';
                    infoHtml += '<div class="d-flex gap-4 mb-2">';
                    infoHtml += '<span><span class="text-muted me-1">' + STR.upload + ':</span><span class="text-success fw-semibold"> ↑ ' + fmtBytes(ulBytes) + '</span></span>';
                    infoHtml += '<span><span class="text-muted me-1">' + STR.download + ':</span><span class="text-info fw-semibold"> ↓ ' + fmtBytes(dlBytes) + '</span></span>';
                    infoHtml += '</div>';
                }
                if (firstSeen || lastSeen) {
                    infoHtml += '<div class="d-flex flex-wrap gap-3 text-muted">';
                    if (firstSeen) infoHtml += '<span><i class="ti ti-calendar-plus me-1"></i><span class="me-1">' + STR.firstSeen + ':</span>' + firstSeen.slice(0,16) + '</span>';
                    if (lastSeen)  infoHtml += '<span><i class="ti ti-calendar-check me-1"></i><span class="me-1">' + STR.lastSeen + ':</span>' + lastSeen.slice(0,16) + '</span>';
                    infoHtml += '</div>';
                }
                infoHtml += '</div>';
            }

            // Legend
            // Legend — strings rendered by PHP through gettext, assembled in JS
            var legendItems = [
                {color:'#28a745', label:STR.wanOnline},
                {color:'#fd7e14', label:STR.wanNoInet},
                {color:'#ffc107', label:STR.wanUnknown},
                {color:'#20c997', label:STR.lanUp},
                {color:'#6c757d', label:STR.linkDown},
            ];
            var legend = '<div class="d-flex flex-wrap gap-3 mb-3 small border-bottom pb-2">';
            legendItems.forEach(function(li) {
                legend += '<span style="display:inline-flex;align-items:center;gap:5px">'
                        + '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + li.color + ';outline:1px solid rgba(128,128,128,.35);flex-shrink:0"></span>'
                        + '<span>' + li.label + '</span>'
                        + '</span>';
            });
            legend += '</div>';
            var html = infoHtml + legend + '<div class="row g-2">';
            ports.forEach(function(p) {
                var isWan  = p.role == 1;
                var linkUp = p.link == 1;
                var cs     = (p.connectStatus !== undefined) ? p.connectStatus : -1;
                var statusColor, statusIcon, statusText;
                if (isWan) {
                    if (!linkUp)  { statusColor='secondary'; statusIcon='circle-x';       statusText=STR.linkDown; }
                    else if(cs==1){ statusColor='success';   statusIcon='circle-check';   statusText=STR.online; }
                    else if(cs==0){ statusColor='warning';   statusIcon='alert-circle';   statusText=STR.noInternet; }
                    else          { statusColor='warning';   statusIcon='alert-circle';   statusText=STR.linkUp; }
                } else {
                    statusColor = linkUp ? 'info'  : 'secondary';
                    statusIcon  = linkUp ? 'circle-check' : 'circle-x';
                    statusText  = linkUp ? STR.linkUp : STR.linkDown;
                }
                var label    = p.silk  || (isWan ? 'WAN' : 'LAN');
                var portName = (p.name && p.name !== p.silk) ? p.name : '';
                html += '<div class="col-md-6"><div class="card border-' + statusColor + ' h-100">';
                html += '<div class="card-header bg-transparent border-' + statusColor + ' d-flex justify-content-between align-items-center py-2">';
                html += '<strong class="text-' + statusColor + '"><i class="ti ti-' + statusIcon + ' me-1"></i>' + label;
                if (portName) html += ' <small class="fw-normal opacity-75">(' + portName + ')</small>';
                html += '</strong>';
                if (p.wanName) html += '<small class="text-muted ms-2">' + p.wanName + '</small>';
                html += '</div><div class="card-body py-2 small"><dl class="row mb-0">';
                if (isWan) {
                    html += '<dt class="col-5 text-muted fw-normal">' + STR.connection + '</dt><dd class="col-7">' + statusText + '</dd>';
                    if (p.ip) html += '<dt class="col-5 text-muted fw-normal">' + STR.ipAddress + '</dt><dd class="col-7"><code>' + p.ip + '</code></dd>';
                    if (p.wanType !== undefined && p.wanType >= 0) html += '<dt class="col-5 text-muted fw-normal">' + STR.wanTypeLbl + '</dt><dd class="col-7">' + (wanTypeNames[p.wanType] || '—') + '</dd>';
                    if (p.gateway) html += '<dt class="col-5 text-muted fw-normal">' + STR.gateway + '</dt><dd class="col-7"><code>' + p.gateway + '</code></dd>';
                    if (p.firstDns || p.secondDns) {
                        var dnsVal = [p.firstDns, p.secondDns].filter(Boolean).join(' / ');
                        html += '<dt class="col-5 text-muted fw-normal">' + STR.dns + '</dt><dd class="col-7"><code>' + dnsVal + '</code></dd>';
                    }
                    if (p.wamMac) html += '<dt class="col-5 text-muted fw-normal">' + STR.wanMac + '</dt><dd class="col-7"><code>' + p.wamMac + '</code></dd>';
                    if (p.portIpv6) html += '<dt class="col-5 text-muted fw-normal">IPv6</dt><dd class="col-7"><code style="font-size:.8em;word-break:break-all;">' + p.portIpv6 + '</code></dd>';
                    if (p.connectDuration) html += '<dt class="col-5 text-muted fw-normal">' + STR.connectedFor + '</dt><dd class="col-7">' + fmtDuration(p.connectDuration) + '</dd>';
                    // Per-port traffic (v1.2.5)
                    if (p.txBytes || p.rxBytes) {
                        html += '<dt class="col-5 text-muted fw-normal">↑ Upload</dt><dd class="col-7 text-success">' + fmtBytes(p.txBytes || 0) + '</dd>';
                        html += '<dt class="col-5 text-muted fw-normal">↓ Download</dt><dd class="col-7 text-info">' + fmtBytes(p.rxBytes || 0) + '</dd>';
                    }
                    if (p.txPackets || p.rxPackets) {
                        html += '<dt class="col-5 text-muted fw-normal">' + STR.txrxPkts + '</dt>'
                             + '<dd class="col-7 small text-nowrap">'
                             + '<span class="text-success">↑' + (p.txPackets || 0).toLocaleString() + '</span>'
                             + ' <span class="text-info">↓' + (p.rxPackets || 0).toLocaleString() + '</span>'
                             + '</dd>';
                    }
                } else {
                    // LAN port
                    html += '<dt class="col-5 text-muted fw-normal">' + STR.statusLbl + '</dt><dd class="col-7">' + statusText + '</dd>';
                    if (linkUp) {
                        // Active LAN port — show negotiated speed prominently
                        var spStr = portSpeeds[p.speed] || '—';
                        html += '<dt class="col-5 text-muted fw-normal">' + STR.negotiatedSpeed + '</dt><dd class="col-7 fw-semibold text-success">' + spStr + '</dd>';
                    }
                    if (p.customName) html += '<dt class="col-5 text-muted fw-normal">' + STR.portLabel + '</dt><dd class="col-7">' + p.customName + '</dd>';
                    if (p.desc)       html += '<dt class="col-5 text-muted fw-normal">' + STR.description + '</dt><dd class="col-7">' + p.desc + '</dd>';
                }
                html += '<dt class="col-5 text-muted fw-normal">' + STR.linkSpeed + '</dt><dd class="col-7">' + (portSpeeds[p.speed] || '—') + '</dd>';
                if (p.type) html += '<dt class="col-5 text-muted fw-normal">' + STR.portType + '</dt><dd class="col-7">' + p.type + '</dd>';
                html += '</dl></div></div></div>';
            });
            html += '</div>';
            body.innerHTML = html;
        }

        var bsModal = typeof bootstrap !== 'undefined' ? new bootstrap.Modal(portModal) : null;
        if (bsModal) bsModal.show();
        else if (typeof $ !== 'undefined') $(portModal).modal('show');
    });

    // ── Network stats modal ────────────────────────────────────────────────
    var netModal = document.createElement('div');
    netModal.className = 'modal fade';
    netModal.id = 'gdmsNetModal';
    netModal.setAttribute('tabindex', '-1');
    netModal.setAttribute('aria-hidden', 'true');
        netModal.innerHTML =
      '<div class="modal-dialog modal-sm"><div class="modal-content">' +
      '<div class="modal-header py-2">' +
      '<i class="ti ti-network me-2 text-primary"></i>' +
      '<h6 class="modal-title mb-0 fw-semibold" id="gdmsNetModalTitle"></h6>' +
      '<button type="button" class="btn-close ms-auto" data-bs-dismiss="modal"></button>' +
      '</div>' +
      '<div class="modal-body p-0" id="gdmsNetModalBody"></div>' +
      '<div class="modal-footer py-2 justify-content-end">' +
      '<button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">' +
      <?= json_encode(__('Close', 'gdmsintegration')) ?> + '</button>' +
      '</div></div></div>';
    document.body.appendChild(netModal);

    var LBL_ONLINE  = STR.lbl_online;
    var LBL_OFFLINE = STR.lbl_offline;
    var LBL_TOTAL   = STR.lbl_total;
    var LBL_ROUTER  = STR.lbl_router;
    var LBL_SWITCH  = STR.lbl_switch;
    var LBL_AP      = STR.lbl_ap;
    var LBL_CLIENTS = STR.lbl_clients;
    var LBL_PHONES  = STR.lbl_phones;

    document.addEventListener('click', function(e) {
        var link = e.target.closest('.gdms-net-link');
        if (!link) return;
        var raw = link.getAttribute('data-net');
        if (!raw) return;
        var nd;
        try { nd = JSON.parse(raw); } catch(ex) { return; }

        document.getElementById('gdmsNetModalTitle').textContent = nd.name;

        var rows = [
            { icon: 'ti ti-sitemap', label: LBL_ROUTER, on: nd.router_on, off: nd.router_off },
            { icon: 'ti ti-server',  label: LBL_SWITCH, on: nd.switch_on, off: nd.switch_off },
            { icon: 'ti ti-wifi',    label: LBL_AP,     on: nd.ap_on,     off: nd.ap_off     },
            { icon: 'ti ti-phone',   label: LBL_PHONES, on: nd.phone_on,  off: nd.phone_off  },
        ];
        rows = rows.filter(function(r){ return (r.on + r.off) > 0; });

        var html = '<ul class="list-group list-group-flush">';
        rows.forEach(function(r) {
            var total = r.on + r.off;
            var pct   = total > 0 ? Math.round(r.on / total * 100) : null;
            var barClass = (pct === null) ? 'bg-secondary' : (pct >= 80 ? 'bg-success' : (pct >= 50 ? 'bg-warning' : 'bg-danger'));
            html += '<li class="list-group-item px-3 py-2">';
            html += '<div class="d-flex align-items-center justify-content-between mb-1">';
            html += '<span><i class="' + r.icon + ' me-2 text-primary" style="width:14px;"></i>'
                  + '<strong>' + r.label + '</strong></span>';
            html += '<span class="small text-muted">';
            if (total === 0) {
                html += '<span class="badge text-bg-secondary opacity-50">0</span>';
            } else {
                html += '<span class="badge bg-success me-1">' + r.on + ' ' + LBL_ONLINE + '</span>';
                if (r.off > 0) html += '<span class="badge bg-danger">' + r.off + ' ' + LBL_OFFLINE + '</span>';
            }
            html += '</span></div>';
            if (total > 0) {
                html += '<div class="progress" style="height:4px;border-radius:2px;">';
                html += '<div class="progress-bar ' + barClass + '" style="width:' + (pct||0) + '%;"></div>';
                html += '</div>';
                html += '<div class="text-end text-muted" style="font-size:.7rem;margin-top:2px;">' + LBL_TOTAL + ': ' + total + '</div>';
            }
            html += '</li>';
        });

        // Clients row
        html += '<li class="list-group-item px-3 py-2 d-flex align-items-center justify-content-between">';
        html += '<span><i class="ti ti-users me-2 text-warning" style="width:14px;"></i><strong>' + LBL_CLIENTS + '</strong></span>';
        html += '<span class="badge bg-warning text-dark fs-6">' + nd.clients + '</span>';
        html += '</li>';

        if (nd.upload_bytes > 0 || nd.download_bytes > 0) {
            html += '<li class="list-group-item px-3 py-2">';
            html += '<div class="fw-semibold text-muted mb-1 small"><i class="ti ti-chart-bar me-1"></i>' + STR.netTraffic + '</div>';
            html += '<div class="d-flex gap-3 small">';
            html += '<span class="text-success">↑ ' + fmtBytes(nd.upload_bytes) + '</span>';
            html += '<span class="text-info">↓ ' + fmtBytes(nd.download_bytes) + '</span>';
            html += '</div></li>';
        }

        html += '</ul>';
        document.getElementById('gdmsNetModalBody').innerHTML = html;

        var bsNet = typeof bootstrap !== 'undefined' ? new bootstrap.Modal(netModal) : null;
        if (bsNet) bsNet.show();
        else if (typeof $ !== 'undefined') $(netModal).modal('show');
    });

    // ── WiFi Clients modal ───────────────────────────────────────────────────
    var CLIENTS_URL = '<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/clients.ajax.php?entities_id=' . $entities_id, ENT_QUOTES, 'UTF-8') ?>';

    var clientModal = document.createElement('div');
    clientModal.className = 'modal fade';
    clientModal.id = 'gdmsClientModal';
    clientModal.setAttribute('tabindex', '-1');
    clientModal.setAttribute('aria-hidden', 'true');
    clientModal.innerHTML =
      '<div class="modal-dialog modal-dialog-centered modal-lg">' +
      '<div class="modal-content">' +
      '<div class="modal-header"><h5 class="modal-title"><i class="ti ti-users me-2 text-info"></i>' +
      <?= json_encode(__('Connected Clients', 'gdmsintegration')) ?> +
      ' <small class="text-muted ms-2" id="gdmsClientModalDevice"></small></h5>' +
      '<button type="button" class="btn-close" data-bs-dismiss="modal"></button>' +
      '</div>' +
      '<div class="modal-body p-0" id="gdmsClientModalBody">' +
      '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>' +
      '</div>' +
      '<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">' +
      <?= json_encode(__('Close', 'gdmsintegration')) ?> +
      '</button></div></div></div>';
    document.body.appendChild(clientModal);

    document.addEventListener('click', function(e) {
        var badge = e.target.closest('.gdms-clients-badge');
        if (!badge) return;
        var networkId = badge.getAttribute('data-network-id');
        var apMac     = badge.getAttribute('data-mac') || '';
        var devName   = badge.getAttribute('data-name') || apMac;
        document.getElementById('gdmsClientModalDevice').textContent = devName;
        var body = document.getElementById('gdmsClientModalBody');
        body.innerHTML = '<div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>';

        var bsCli = typeof bootstrap !== 'undefined' ? new bootstrap.Modal(clientModal) : null;
        if (bsCli) bsCli.show(); else if (typeof $ !== 'undefined') $(clientModal).modal('show');

        var url = CLIENTS_URL + '&network_id=' + encodeURIComponent(networkId) + '&mac=' + encodeURIComponent(apMac);
        fetch(url, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(list) {
                if (!Array.isArray(list) || !list.length) {
                    body.innerHTML = '<p class="text-muted text-center py-3">' + STR.noClients + '</p>';
                    return;
                }
                var esc = function(s){ var d = document.createElement('div'); d.appendChild(document.createTextNode(String(s || '—'))); return d.innerHTML; };
                var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0"><thead><tr>' +
                    '<th class="ps-3">' + STR.hostname + '</th>' +
                    '<th>IP</th><th>MAC</th>' +
                    '<th>' + STR.band + ' / SSID</th>' +
                    '<th>' + STR.signal + '</th>' +
                    '<th>' + STR.txrx + '</th>' +
                    '</tr></thead><tbody>';
                list.forEach(function(c) {
                    var rssiColor = c.rssi <= -76 ? 'text-danger' : (c.rssi <= -60 ? 'text-warning' : 'text-success');
                    html += '<tr>' +
                        '<td class="ps-3 fw-semibold">' + esc(c.hostname || c.mac) + '</td>' +
                        '<td><code class="small">' + esc(c.ip) + '</code></td>' +
                        '<td><code class="small">' + esc(c.mac) + '</code></td>' +
                        '<td><small>' + esc(c.band) + (c.ssid ? ' — ' + esc(c.ssid) : '') + '</small></td>' +
                        '<td class="' + rssiColor + ' fw-semibold">' + (c.rssi ? c.rssi + ' dBm' : '—') + '</td>' +
                        '<td class="small text-nowrap">' +
                            (c.txRate ? '<span class="text-success">↑' + c.txRate + 'M</span> ' : '') +
                            (c.rxRate ? '<span class="text-info">↓' + c.rxRate + 'M</span>' : '') +
                        '</td></tr>';
                });
                html += '</tbody></table></div>';
                body.innerHTML = html;
            })
            .catch(function() {
                body.innerHTML = '<p class="text-danger text-center py-3">' + STR.reqFailed + '</p>';
            });
    });

    // ── Cloud Alerts panel ────────────────────────────────────────────────────
    var ALERTS_URL  = '<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/alerts.ajax.php?entities_id=' . $entities_id, ENT_QUOTES, 'UTF-8') ?>';
    var alertsBody = document.getElementById('gdms-alerts-body');
    var alertsMeta = document.getElementById('gdms-alerts-meta');

    if (alertsBody) {
        // Dismiss click — hides row locally only; GWN Cloud has no working dismiss API
        alertsBody.addEventListener('click', function(e) {
            var btn = e.target.closest('.gdms-alert-dismiss');
            if (!btn) return;
            e.preventDefault();
            var row = btn.closest('tr');
            if (row) row.style.display = 'none';
        });

        setTimeout(function() {
            fetch(ALERTS_URL, { credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(list) {
                    if (!Array.isArray(list) || !list.length) {
                        alertsBody.innerHTML = '<p class="text-muted text-center py-2 small mb-0">' + STR.noAlerts + '</p>';
                        return;
                    }
                    if (typeof console !== 'undefined') console.log('GDMS alert[0]:', list[0]);
                    var sevColor = {'critical':'danger','warning':'warning','medium':'warning','info':'info','low':'secondary','error':'danger'};
                    var esc = function(s){ var d = document.createElement('div'); d.appendChild(document.createTextNode(String(s || ''))); return d.innerHTML; };
                    var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle"><thead><tr>' +
                        '<th class="ps-3" style="white-space:nowrap">' + STR.alertTime + '</th>' +
                        '<th>' + STR.alertSev + '</th>' +
                        '<th>' + STR.alertDevice + '</th>' +
                        '<th>' + STR.alertMsg + '</th>' +
                        '<th></th>' +
                        '</tr></thead><tbody>';
                    list.slice(0, 50).forEach(function(a) {
                        var sev = String(a.severity || 'info').toLowerCase();
                        var cls = sevColor[sev] || 'secondary';
                        var ct  = parseInt(a.createTime || 0);
                        var ts  = ct ? new Date(ct).toLocaleString() : '—';
                        var desc = String(a.description || a.alertType || '');
                        // Enrich description with reason and category if available
                        var extras = [];
                        if (a.category)  extras.push(STR.alertCategory + ': ' + a.category);
                        if (a.reason)    extras.push(STR.alertReason   + ': ' + a.reason);
                        if (a.port_id)   extras.push('Port: ' + a.port_id);
                        var extraHtml = extras.length
                            ? '<br><span class="text-muted" style="font-size:.8em;">' + extras.map(esc).join(' · ') + '</span>'
                            : '';
                        var devName = String(a.deviceName || a.deviceMac || '—');
                        html += '<tr data-alert-id="' + esc(a.id) + '">'
                            + '<td class="ps-3 text-nowrap small text-muted">' + esc(ts) + '</td>'
                            + '<td><span class="badge bg-' + cls + ' text-' + (cls === 'warning' ? 'dark' : 'white') + '">' + esc(sev) + '</span></td>'
                            + '<td class="small text-nowrap">' + esc(devName) + '</td>'
                            + '<td class="small">' + esc(desc) + extraHtml + '</td>'
                            + '<td class="text-end pe-2"><button type="button" class="btn btn-sm btn-link p-0 text-muted gdms-alert-dismiss" data-alert-id="' + esc(a.id) + '" title="' + esc(STR.alertDismiss) + '"><i class="ti ti-x"></i></button></td>'
                            + '</tr>';
                    });
                    html += '</tbody></table></div>';
                    alertsBody.innerHTML = html;
                })
                .catch(function() {
                    alertsBody.innerHTML = '<p class="text-muted text-center py-2 small mb-0">' + STR.alertsError + '</p>';
                });
        }, 4500);
    }

    // Copy-to-clipboard for model, MAC, serial cells
    document.addEventListener('click', function(e) {
        var el = e.target.closest('.gdms-copy');
        if (!el || !el.dataset.copy) return;
        var val = el.dataset.copy;
        if (!val) return;
        navigator.clipboard.writeText(val).then(function() {
            var prev = el.innerHTML;
            el.innerHTML = '<span style="color:var(--bs-success,#198754)">✓</span>';
            setTimeout(function() { el.innerHTML = prev; }, 900);
        });
    });

    // Chevron rotation for alerts collapse
    var alertsCollapse = document.getElementById('gdms-alerts-collapse');
    if (alertsCollapse) {
        alertsCollapse.addEventListener('show.bs.collapse', function() {
            var ch = document.querySelector('.gdms-alerts-chevron');
            if (ch) ch.style.transform = 'rotate(180deg)';
        });
        alertsCollapse.addEventListener('hide.bs.collapse', function() {
            var ch = document.querySelector('.gdms-alerts-chevron');
            if (ch) ch.style.transform = 'rotate(0deg)';
        });
    }

})();
</script>

<?php if (!empty($chart_datasets)): ?>
<script type="module">
/* ECharts 5 — <script type="module"> defers until after all regular footer scripts,
   so echarts global is guaranteed to be available */
(function() {
    var hc = document.getElementById('gdms-history-chart');
    if (!hc || typeof echarts === 'undefined') return;

    var _bg   = window.getComputedStyle(document.body).backgroundColor;
    var _rgb  = _bg.match(/\d+/g);
    var dark  = (_rgb && parseInt(_rgb[0]) < 100)
             || document.documentElement.getAttribute('data-bs-theme') === 'dark';

    var chartLabels   = <?= json_encode($chart_labels) ?>;
    var chartDatasets = <?= json_encode(array_values($chart_datasets)) ?>;

    var ec = echarts.init(hc, dark ? 'dark' : null, { renderer: 'svg' });
    ec.setOption({
        backgroundColor: 'transparent',
        tooltip: {
            trigger: 'axis',
            formatter: function(params) {
                var s = '<b>' + params[0].axisValue + '</b><br/>';
                params.forEach(function(p) {
                    if (p.value === null || p.value === undefined) return;
                    s += '<span style="display:inline-block;width:10px;height:10px;'
                       + 'border-radius:50%;background:' + p.color
                       + ';margin-right:5px;"></span>'
                       + p.seriesName + ': ' + p.value + '%<br/>';
                });
                return s;
            }
        },
        legend: { bottom: 0, type: 'scroll', textStyle: { fontSize: 11 } },
        grid: { top: 12, right: 16, bottom: 40, left: 48, containLabel: false },
        xAxis: {
            type: 'category',
            data: chartLabels,
            axisLabel: { fontSize: 11 },
            axisLine: { lineStyle: { opacity: .3 } },
            splitLine: { show: false }
        },
        yAxis: {
            type: 'value', min: 0, max: 100,
            axisLabel: { formatter: '{value}%', fontSize: 11 },
            splitLine: { lineStyle: { opacity: .15 } }
        },
        series: chartDatasets.map(function(ds) {
            return {
                name: ds.label, type: 'line', data: ds.data,
                smooth: true, connectNulls: true, symbolSize: 4,
                lineStyle: { color: ds.borderColor, width: 2 },
                itemStyle: { color: ds.borderColor },
                areaStyle: { color: ds.borderColor, opacity: .08 }
            };
        })
    });
    window.addEventListener('resize', function() { ec.resize(); });
})();
</script>
<?php endif; ?>

<script>
(function () {
    var tbl = document.getElementById('gdms-device-table');
    if (!tbl) return;
    var tbody   = tbl.querySelector('tbody');
    var resetBtn = document.getElementById('gdms-sort-reset');
    var sortCol = null;
    var sortAsc = true;
    var numCols = ['type', 'status', 'clients', 'avail', 'sla'];

    function applySort(col, asc) {
        sortCol = col; sortAsc = asc;
        tbl.querySelectorAll('th.gdms-sortable .gdms-sort-icon').forEach(function (ic) {
            ic.textContent = '⇅'; ic.className = 'gdms-sort-icon opacity-40';
        });
        var activeTh = tbl.querySelector('th[data-col="' + col + '"]');
        if (activeTh) {
            var icon = activeTh.querySelector('.gdms-sort-icon');
            if (icon) { icon.textContent = asc ? '↑' : '↓'; icon.className = 'gdms-sort-icon'; }
        }
        var rows = Array.from(tbody.querySelectorAll('tr[data-original-index]'));
        rows.sort(function (a, b) {
            var av = a.dataset[col] || '', bv = b.dataset[col] || '', cmp;
            cmp = numCols.indexOf(col) !== -1 ? parseFloat(av) - parseFloat(bv) : av.localeCompare(bv);
            return asc ? cmp : -cmp;
        });
        rows.forEach(function (r) { tbody.appendChild(r); });
        if (resetBtn) resetBtn.style.display = '';
    }

    // Restore sort from URL on load
    (function () {
        var p = new URLSearchParams(location.search);
        var c = p.get('sort'), d = p.get('dir');
        if (c && tbl.querySelector('th[data-col="' + c + '"]')) applySort(c, d !== 'desc');
    })();

    tbl.querySelectorAll('th.gdms-sortable').forEach(function (th) {
        th.addEventListener('click', function () {
            var col = th.dataset.col;
            var asc = sortCol === col ? !sortAsc : true;
            applySort(col, asc);
            var url = new URL(location.href);
            url.searchParams.set('sort', col);
            url.searchParams.set('dir', asc ? 'asc' : 'desc');
            history.replaceState(null, '', url);
        });
    });

    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            sortCol = null;
            tbl.querySelectorAll('th.gdms-sortable .gdms-sort-icon').forEach(function (ic) {
                ic.textContent = '⇅'; ic.className = 'gdms-sort-icon opacity-40';
            });
            var rows = Array.from(tbody.querySelectorAll('tr[data-original-index]'));
            rows.sort(function (a, b) { return +a.dataset.originalIndex - +b.dataset.originalIndex; });
            rows.forEach(function (r) { tbody.appendChild(r); });
            resetBtn.style.display = 'none';
            var url = new URL(location.href);
            url.searchParams.delete('sort'); url.searchParams.delete('dir');
            history.replaceState(null, '', url);
        });
    }
})();
</script>

<?php Html::footer(); ?>
