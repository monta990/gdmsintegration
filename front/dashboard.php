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
$chart_days       = max(7, min(365, (int)($config['chart_days'] ?? 60)));
$show_topology    = (int)($config['show_topology'] ?? 1);

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
$all_states = $state_obj->find(); // every MAC the plugin has ever synced

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
    $uptime      = PluginGdmsintegrationSync::calculateUptime($mac);
    $sla         = PluginGdmsintegrationSync::calculateSLA($mac);
    $net_name    = htmlspecialchars($state['network_name'] ?? '', ENT_QUOTES, 'UTF-8');
    $ip          = htmlspecialchars($state['ip']           ?? '', ENT_QUOTES, 'UTF-8');
    $firmware    = htmlspecialchars($state['firmware']     ?? '', ENT_QUOTES, 'UTF-8');
    $uptime_sec  = (int)($state['uptime_sec']              ?? 0);
    $sn_cloud    = htmlspecialchars($state['sn_cloud']     ?? '', ENT_QUOTES, 'UTF-8');

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
        'raw_model'    => $state['model'] ?? '', // raw apType for device classification
        'clients'      => (int)($state['clients'] ?? 0),
    ];
}

// Build per-network device stats for tooltip (router/switch/AP/clients counts)
// Device classification: GWN7001/7002/7003 prefix → router; GWN7800/GSS → switch; GWN76xx → AP; UCM/GCC/GRP/GXP etc → phone/pbx
$net_stats = []; // network_name → [router_on, router_off, switch_on, switch_off, ap_on, ap_off, clients_wired, clients_wireless]
foreach ($rows as $r) {
    $nname = $r['network_name'];
    if ($nname === '') continue;
    if (!isset($net_stats[$nname])) {
        $net_stats[$nname] = ['router_on'=>0,'router_off'=>0,'switch_on'=>0,'switch_off'=>0,'ap_on'=>0,'ap_off'=>0,'clients'=>0];
    }
    $m   = strtoupper($r['raw_model'] ?? '');
    $on  = $r['online'];
    // Classify by model prefix
    if (preg_match('/^GWN700[0-9]/', $m)) { // GWN7001/7002/7003 = routers
        $on ? $net_stats[$nname]['router_on']++ : $net_stats[$nname]['router_off']++;
    } elseif (preg_match('/^GWN78|^GSS/', $m)) { // GWN7800x = switches, GSS = smart switches
        $on ? $net_stats[$nname]['switch_on']++ : $net_stats[$nname]['switch_off']++;
    } elseif (preg_match('/^GWN/', $m)) { // other GWN = APs
        $on ? $net_stats[$nname]['ap_on']++ : $net_stats[$nname]['ap_off']++;
    }
    // clients is total connected clients reported by AP/router
    $net_stats[$nname]['clients'] += $r['clients'];
}

// Uptime history — last N days per device per day (configurable via chart_days)
$history_obj = new PluginGdmsintegrationHistory();
$history_ago  = date('Y-m-d H:i:s', strtotime("-{$chart_days} days"));
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

// Build dataset per device (only devices that have history)
$chart_datasets = [];
$palette = ['#28a745','#007bff','#fd7e14','#dc3545','#6f42c1','#20c997','#ffc107','#e83e8c','#17a2b8','#6c757d'];
$pi = 0;
foreach ($per_device as $mac => $days) {
    $label  = $mac_to_name[$mac] ?? $mac;
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
    $summary['clients']    += $ns['clients'];
}
foreach ($rows as $r) {
    if ($r['type'] === 'Phone') {
        $r['online'] ? $summary['phone_on']++ : $summary['phone_off']++;
    }
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

   <?php // Device table ?>
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="ti ti-list"></i>
         <h5 class="mb-0"><?= __('Devices', 'gdmsintegration') ?></h5>
      </div>
      <div class="table-responsive">
         <table class="table table-hover table-sm mb-0">
            <thead>
               <tr>
                  <th class="ps-3"><?= __('Device Name', 'gdmsintegration') ?></th>
                  <th><?= __('Type', 'gdmsintegration') ?></th>
                  <th><?= __('Model', 'gdmsintegration') ?></th>
                  <th><?= __('Network', 'gdmsintegration') ?></th>
                  <th><?= __('IP', 'gdmsintegration') ?></th>
                  <th><?= __('MAC Address', 'gdmsintegration') ?></th>
                  <th><?= __('Serial', 'gdmsintegration') ?></th>
                  <th><?= __('Firmware', 'gdmsintegration') ?></th>
                  <th><?= __('Ports', 'gdmsintegration') ?></th>
                  <th><?= __('Uptime', 'gdmsintegration') ?></th>
                  <th><?= __('Status', 'gdmsintegration') ?></th>
                  <th><?= __('Avail. %', 'gdmsintegration') ?></th>
                  <th><?= __('SLA', 'gdmsintegration') ?></th>
               </tr>
            </thead>
            <tbody>
               <?php foreach ($rows as $r): ?>
               <?php
               // Convert uptime seconds → d h m
               $us = (int)($r['uptime_sec'] ?? 0);
               $ud = intdiv($us, 86400);
               $uh = intdiv($us % 86400, 3600);
               $um = intdiv($us % 3600, 60);
               $uptime_str = $ud > 0 ? "{$ud}d {$uh}h {$um}m" : ($uh > 0 ? "{$uh}h {$um}m" : "{$um}m");
               ?>
               <tr>
                  <td class="ps-3">
                     <?php if (!empty($r['asset_url'])): ?>
                     <a href="<?= $r['asset_url'] ?>" class="fw-semibold text-decoration-none">
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
                  <td><small class="text-muted font-monospace"><?= htmlspecialchars($r['model'] ?? '', ENT_QUOTES, 'UTF-8') ?: '—' ?></small></td>
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
                          'clients'    => $rstat['clients'],
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
                     <a href="https://www.whois.com/whois/<?= urlencode($r['ip']) ?>" target="_blank" rel="noopener" class="text-decoration-none">
                        <?= $r['ip'] ?> <i class="ti ti-external-link opacity-50" style="font-size:.65em;"></i>
                     </a>
                     <?php else: ?>—<?php endif; ?>
                  </small></td>
                  <td><code class="small"><?= $r['mac'] ?></code></td>
                  <td><small class="text-muted"><?= $r['serial'] ?: '—' ?></small></td>
                  <td class="text-nowrap">
                     <small class="font-monospace text-muted"><?= $r['firmware'] ?: '—' ?></small>
                     <?php if (!empty($r['firmware']) && $r['type'] !== 'Phone'): ?>
                     <span class="gdms-fw-badge" style="display:none; cursor:pointer;"
                           data-mac="<?= htmlspecialchars(strtolower($r['mac'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           data-current="<?= htmlspecialchars($r['firmware'], ENT_QUOTES, 'UTF-8') ?>"
                           title="<?= __('Firmware update available', 'gdmsintegration') ?>">
                        <i class="ti ti-arrow-up-circle text-warning ms-1"></i>
                     </span>
                     <?php endif; ?>
                  </td>
                  <td>
                     <?php if ($r['type'] !== 'Phone' && !empty($r['mac'])): ?>
                     <span class="gdms-wan-ports d-flex gap-1 align-items-center flex-nowrap"
                           data-mac="<?= htmlspecialchars(strtolower($r['mac']), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="text-muted small">—</span>
                     </span>
                     <?php endif; ?>
                  </td>
                  <td><small><?= $us > 0 ? $uptime_str : '—' ?></small></td>
                  <td>
                     <span class="badge <?= $r['online'] ? 'bg-success' : 'bg-danger' ?> text-white">
                        <?= $r['online'] ? __('Online', 'gdmsintegration') : __('Offline', 'gdmsintegration') ?>
                     </span>
                  </td>
                  <td><?= $r['uptime'] ?>%</td>
                  <td><?= $r['sla'] ?></td>
               </tr>
               <?php endforeach; ?>
               <?php if (empty($rows)): ?>
               <tr><td colspan="11" class="text-center text-muted py-3">
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
         <canvas id="gdms-history-chart" style="max-height:280px; padding:12px;"></canvas>
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
<script src="https://unpkg.com/vis-network@10.0.2/standalone/umd/vis-network.min.js"></script>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>
<script>
(function () {
    'use strict';

    // Dark theme detection
    var bg     = window.getComputedStyle(document.body).backgroundColor;
    var rgb    = bg.match(/\d+/g);
    var isDark = rgb && parseInt(rgb[0]) < 100;
    var netBg  = isDark ? '#1e2433' : '#f8f9fa';
    var txtClr = isDark ? '#ccc' : '#555';
    var grdClr = isDark ? '#333' : '#eee';

    // History chart (60 days uptime %)
    var hc = document.getElementById('gdms-history-chart');
    var chartLabels   = <?= json_encode($chart_labels) ?>;
    var chartDatasets = <?= json_encode(array_values($chart_datasets)) ?>;
    if (hc) {
        new Chart(hc, {
            type: 'line',
            data: { labels: chartLabels, datasets: chartDatasets },
            options: {
                scales: {
                    y: { min:0, max:100, ticks:{ color:txtClr, callback: v => v+'%' }, grid:{ color:grdClr } },
                    x: { ticks:{ color:txtClr, maxTicksLimit:15 }, grid:{ display:false } }
                },
                plugins: {
                    legend: { display: true, position: 'bottom',
                        labels: { color: txtClr, boxWidth: 12, font:{ size:11 } }
                    }
                },
                responsive: true, maintainAspectRatio: false
            }
        });
    }

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
        if (countEl) countEl.textContent = '<?= __('Syncing…', 'gdmsintegration') ?>';
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
    var FW_URL = '<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/firmware.ajax.php?action=check&entities_id=' . $entities_id, ENT_QUOTES, 'UTF-8') ?>';
    var FW_UPGRADE_URL = '<?= htmlspecialchars(($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/firmware.ajax.php?action=upgrade&entities_id=' . $entities_id, ENT_QUOTES, 'UTF-8') ?>';

    // Inject modal HTML once
    var modalHtml = `
<div class="modal fade" id="gdmsFwModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
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
      <div class="modal-footer flex-column align-items-stretch gap-2">
        <div class="d-flex align-items-center gap-2 w-100" id="gdmsFwScheduleRow" style="display:none!important;">
          <label class="form-label mb-0 text-nowrap small fw-semibold"><?= __('Schedule for', 'gdmsintegration') ?>:</label>
          <input type="datetime-local" class="form-control form-control-sm" id="gdmsFwDatetime" style="max-width:220px;">
          <small class="text-muted"><?= __('Leave empty to apply as soon as possible', 'gdmsintegration') ?></small>
        </div>
        <div class="d-flex justify-content-end gap-2 w-100">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <?= __('Close', 'gdmsintegration') ?>
          </button>
          <button type="button" class="btn btn-success" id="gdmsFwAsapBtn" style="display:none;">
            <i class="ti ti-bolt me-1"></i><?= __('Apply now (ASAP)', 'gdmsintegration') ?>
          </button>
          <button type="button" class="btn btn-warning" id="gdmsFwScheduleBtn" style="display:none;">
            <i class="ti ti-calendar-check me-1"></i><?= __('Schedule update', 'gdmsintegration') ?>
          </button>
        </div>
      </div>
    </div>
  </div>
</div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);

    var fwData = {};  // mac → {currentVersion, latestVersion, hasUpdate}

    // Fetch firmware info after page load (non-blocking, ~2s)
    setTimeout(function() {
        fetch(FW_URL, { credentials: 'same-origin' })
            .then(function(r) { return r.json(); })
            .then(function(list) {
                if (!Array.isArray(list)) return;
                list.forEach(function(item) {
                    if (!item.hasUpdate) return;
                    fwData[item.mac] = item;
                    document.querySelectorAll('.gdms-fw-badge[data-mac="' + item.mac + '"]')
                        .forEach(function(el) { el.style.display = 'inline'; });
                });
            })
            .catch(function() { /* silently ignore if GWN not configured */ });
    }, 2000);

    // Click on badge → open modal with details
    document.addEventListener('click', function(e) {
        var badge = e.target.closest('.gdms-fw-badge');
        if (!badge) return;
        var mac     = badge.getAttribute('data-mac');
        var current = badge.getAttribute('data-current');
        var info    = fwData[mac] || {};
        var latest  = info.latestVersion || '<?= __('Unknown', 'gdmsintegration') ?>';
        var body     = document.getElementById('gdmsFwModalBody');
        var schedBtn = document.getElementById('gdmsFwScheduleBtn');
        var asapBtn  = document.getElementById('gdmsFwAsapBtn');
        var schedRow = document.getElementById('gdmsFwScheduleRow');
        var dtInput  = document.getElementById('gdmsFwDatetime');

        var esc = function(s){ var d = document.createElement('div'); d.appendChild(document.createTextNode(String(s))); return d.innerHTML; };
        body.innerHTML = '<table class="table table-sm mb-0">'
          + '<tr><th class="text-muted fw-normal w-50"><?= __('Current firmware', 'gdmsintegration') ?></th>'
          + '<td><code>' + esc(current) + '</code></td></tr>'
          + '<tr><th class="text-muted fw-normal"><?= __('Latest stable firmware', 'gdmsintegration') ?></th>'
          + '<td><code class="text-warning">' + esc(latest) + '</code>'
          + ' <span class="badge bg-success ms-1"><?= __('Official', 'gdmsintegration') ?></span></td></tr>'
          + '<tr><th class="text-muted fw-normal"><?= __('Device MAC', 'gdmsintegration') ?></th>'
          + '<td><code>' + esc(mac.toUpperCase()) + '</code></td></tr>'
          + '</table>'
          + '<div class="alert alert-warning mt-3 mb-0 py-2 small">'
          + '<i class="ti ti-alert-triangle me-1"></i>'
          + '<?= __('The device will reboot during the update. Schedule during a maintenance window.', 'gdmsintegration') ?>'
          + '</div>';

        // Reset datetime picker — clear and set min to now+5min
        var nowPlus5 = new Date(Date.now() + 5*60*1000);
        dtInput.min   = nowPlus5.toISOString().slice(0,16);
        dtInput.value = '';

        schedRow.style.display = '';
        asapBtn.style.display  = 'inline-block';
        schedBtn.style.display = 'inline-block';
        asapBtn.disabled  = false;
        schedBtn.disabled = false;
        asapBtn.innerHTML  = '<i class="ti ti-bolt me-1"></i><?= __('Apply now (ASAP)', 'gdmsintegration') ?>';
        schedBtn.innerHTML = '<i class="ti ti-calendar-check me-1"></i><?= __('Schedule update', 'gdmsintegration') ?>';

        function doUpgrade(scheduleTimeMs) {
            var activeBtn = scheduleTimeMs > 0 ? schedBtn : asapBtn;
            asapBtn.disabled = true; schedBtn.disabled = true;
            activeBtn.innerHTML = '<i class="ti ti-loader me-1" style="animation:spin .8s linear infinite;display:inline-block;"></i><?= __('Scheduling…', 'gdmsintegration') ?>';
            var csrfValue = (typeof window.glpiGetNewCSRFToken === 'function')
                ? window.glpiGetNewCSRFToken()
                : (document.querySelector('meta[property="glpi:csrf_token"]') || {}).getAttribute('content') || '';
            var formData = new FormData();
            formData.append('_glpi_csrf_token', csrfValue);
            formData.append('macs', JSON.stringify([mac.replace(/:/g, '').toUpperCase()]));
            if (scheduleTimeMs > 0) formData.append('scheduleTimeMs', scheduleTimeMs);
            fetch(FW_UPGRADE_URL, { method: 'POST', credentials: 'same-origin', body: formData })
                .then(function(r) { return r.json(); })
                .then(function(resp) {
                    if (resp.error) {
                        var errDiv = document.createElement('div');
                        errDiv.className = 'alert alert-danger mt-2 mb-0 py-2 small';
                        errDiv.textContent = resp.error;
                        errDiv.insertAdjacentHTML('afterbegin', '<i class="ti ti-circle-x me-1"></i> ');
                        body.appendChild(errDiv);
                        asapBtn.disabled = false; schedBtn.disabled = false;
                        asapBtn.innerHTML  = '<i class="ti ti-bolt me-1"></i><?= __('Apply now (ASAP)', 'gdmsintegration') ?>';
                        schedBtn.innerHTML = '<i class="ti ti-calendar-check me-1"></i><?= __('Schedule update', 'gdmsintegration') ?>';
                    } else {
                        var ok = document.createElement('div');
                        ok.className = 'alert alert-success mt-2 mb-0 py-2 small';
                        ok.innerHTML = '<i class="ti ti-check-circle me-1"></i><?= __('Update scheduled successfully. The device will update shortly.', 'gdmsintegration') ?>';
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
                    connErr.textContent = '<?= __('Request failed. Check connection.', 'gdmsintegration') ?>';
                    body.appendChild(connErr);
                    asapBtn.disabled = false; schedBtn.disabled = false;
                    asapBtn.innerHTML  = '<i class="ti ti-bolt me-1"></i><?= __('Apply now (ASAP)', 'gdmsintegration') ?>';
                    schedBtn.innerHTML = '<i class="ti ti-calendar-check me-1"></i><?= __('Schedule update', 'gdmsintegration') ?>';
                });
        }

        asapBtn.onclick  = function() { doUpgrade(0); };
        schedBtn.onclick = function() {
            var dtVal = dtInput.value;
            if (!dtVal) { doUpgrade(0); return; }
            var ms = new Date(dtVal).getTime();
            if (!ms || ms <= Date.now()) { doUpgrade(0); return; }
            doUpgrade(ms);
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
    var portSpeeds = {0:'—', 1:'10M <?= __('HDX','gdmsintegration') ?>', 2:'10M <?= __('FDX','gdmsintegration') ?>', 3:'100M <?= __('HDX','gdmsintegration') ?>', 4:'100M <?= __('FDX','gdmsintegration') ?>', 5:'1G <?= __('FDX','gdmsintegration') ?>', 6:'1G <?= __('FDX','gdmsintegration') ?>', 7:'10G <?= __('FDX','gdmsintegration') ?>'};
    var wanTypeNames = {0:'DHCP', 1:'Static', 2:'PPPoE', 3:'PPTP', 4:'L2TP'};
    var connectNames = {0:'<?= __('Disconnected', 'gdmsintegration') ?>', 1:'<?= __('Online', 'gdmsintegration') ?>'};

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
    portModal.innerHTML = `
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title">
              <i class="ti ti-network me-2 text-primary"></i>
              <?= __('Port Status', 'gdmsintegration') ?>
              <small class="text-muted ms-2" id="gdmsPortModalDevice"></small>
            </h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body" id="gdmsPortModalBody">
            <div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= __('Close', 'gdmsintegration') ?></button>
          </div>
        </div>
      </div>`;
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
                        dot.title = label + portLabel + wanLabel + (!linkUp ? ' — <?= __('Link down', 'gdmsintegration') ?>' : (p.connectStatus === 1 ? ' — <?= __('Online', 'gdmsintegration') ?>' : p.connectStatus === 0 ? ' — <?= __('No internet', 'gdmsintegration') ?>' : ''));
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
        if (!ports.length) { body.innerHTML = '<p class="text-muted text-center"><?= addslashes(__('No ports found', 'gdmsintegration')) ?></p>'; }
        else {
            // Legend
            // Legend — strings rendered by PHP through gettext, assembled in JS
            var legendItems = [
                {color:'#28a745', label:'<?= addslashes(__('WAN online',          'gdmsintegration')) ?>'},
                {color:'#fd7e14', label:'<?= addslashes(__('WAN up, no internet', 'gdmsintegration')) ?>'},
                {color:'#ffc107', label:'<?= addslashes(__('WAN up, unknown',     'gdmsintegration')) ?>'},
                {color:'#20c997', label:'<?= addslashes(__('LAN up',              'gdmsintegration')) ?>'},
                {color:'#6c757d', label:'<?= addslashes(__('Link down',           'gdmsintegration')) ?>'},
            ];
            var legend = '<div class="d-flex flex-wrap gap-3 mb-3 small border-bottom pb-2">';
            legendItems.forEach(function(li) {
                legend += '<span style="display:inline-flex;align-items:center;gap:5px">'
                        + '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' + li.color + ';outline:1px solid rgba(128,128,128,.35);flex-shrink:0"></span>'
                        + '<span>' + li.label + '</span>'
                        + '</span>';
            });
            legend += '</div>';
            var html = legend + '<div class="row g-2">';
            ports.forEach(function(p) {
                var isWan  = p.role == 1;
                var linkUp = p.link == 1;
                var cs     = (p.connectStatus !== undefined) ? p.connectStatus : -1;
                var statusColor, statusIcon, statusText;
                if (isWan) {
                    if (!linkUp)  { statusColor='secondary'; statusIcon='circle-x';       statusText='<?= __('Link down', 'gdmsintegration') ?>'; }
                    else if(cs==1){ statusColor='success';   statusIcon='circle-check';   statusText='<?= __('Online', 'gdmsintegration') ?>'; }
                    else if(cs==0){ statusColor='warning';   statusIcon='alert-circle';   statusText='<?= __('No internet', 'gdmsintegration') ?>'; }
                    else          { statusColor='warning';   statusIcon='alert-circle';   statusText='<?= __('Link up', 'gdmsintegration') ?>'; }
                } else {
                    statusColor = linkUp ? 'info'  : 'secondary';
                    statusIcon  = linkUp ? 'circle-check' : 'circle-x';
                    statusText  = linkUp ? '<?= __('Link up', 'gdmsintegration') ?>' : '<?= __('Link down', 'gdmsintegration') ?>';
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
                    html += '<dt class="col-5 text-muted fw-normal"><?= __('Connection', 'gdmsintegration') ?></dt><dd class="col-7">' + statusText + '</dd>';
                    if (p.ip) html += '<dt class="col-5 text-muted fw-normal"><?= __('IP Address', 'gdmsintegration') ?></dt><dd class="col-7"><code>' + p.ip + '</code></dd>';
                    if (p.wanType !== undefined) html += '<dt class="col-5 text-muted fw-normal"><?= __('Type', 'gdmsintegration') ?></dt><dd class="col-7">' + (wanTypeNames[p.wanType] || '—') + '</dd>';
                    if (p.connectDuration) html += '<dt class="col-5 text-muted fw-normal"><?= __('Connected for', 'gdmsintegration') ?></dt><dd class="col-7">' + fmtDuration(p.connectDuration) + '</dd>';
                } else {
                    html += '<dt class="col-5 text-muted fw-normal"><?= __('Status', 'gdmsintegration') ?></dt><dd class="col-7">' + statusText + '</dd>';
                }
                html += '<dt class="col-5 text-muted fw-normal"><?= __('Link speed', 'gdmsintegration') ?></dt><dd class="col-7">' + (portSpeeds[p.speed] || '—') + '</dd>';
                if (p.type) html += '<dt class="col-5 text-muted fw-normal"><?= __('Port type', 'gdmsintegration') ?></dt><dd class="col-7">' + p.type + '</dd>';
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
    netModal.innerHTML = `
      <div class="modal-dialog modal-sm">
        <div class="modal-content">
          <div class="modal-header py-2">
            <i class="ti ti-network me-2 text-primary"></i>
            <h6 class="modal-title mb-0 fw-semibold" id="gdmsNetModalTitle"></h6>
            <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body p-0" id="gdmsNetModalBody"></div>
          <div class="modal-footer py-2 justify-content-end">
            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">
              <?= __('Close', 'gdmsintegration') ?>
            </button>
          </div>
        </div>
      </div>`;
    document.body.appendChild(netModal);

    var LBL_ONLINE  = '<?= addslashes(__('online',  'gdmsintegration')) ?>';
    var LBL_OFFLINE = '<?= addslashes(__('offline', 'gdmsintegration')) ?>';
    var LBL_TOTAL   = '<?= addslashes(__('Total',   'gdmsintegration')) ?>';
    var LBL_ROUTER  = '<?= addslashes(__('Router',  'gdmsintegration')) ?>';
    var LBL_SWITCH  = '<?= addslashes(__('Switch',  'gdmsintegration')) ?>';
    var LBL_AP      = '<?= addslashes(__('AP',      'gdmsintegration')) ?>';
    var LBL_CLIENTS = '<?= addslashes(__('Clients', 'gdmsintegration')) ?>';

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
        ];

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

        html += '</ul>';
        document.getElementById('gdmsNetModalBody').innerHTML = html;

        var bsNet = typeof bootstrap !== 'undefined' ? new bootstrap.Modal(netModal) : null;
        if (bsNet) bsNet.show();
        else if (typeof $ !== 'undefined') $(netModal).modal('show');
    });

})();
</script>

<?php Html::footer(); ?>
