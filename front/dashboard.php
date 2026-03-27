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

if (!$is_configured) {
    $config_url = '/plugins/gdmsintegration/front/config.form.php';
    echo '<div class="container-xl mt-5">';
    echo '   <div class="card border-0 shadow-sm mx-auto" style="max-width:540px;">';
    echo '      <div class="card-body text-center py-5 px-4">';
    echo '         <i class="fas fa-plug fa-3x text-secondary mb-3"></i>';
    echo '         <h4 class="fw-bold mb-2">' . __('GDMS not configured yet', 'gdmsintegration') . '</h4>';
    echo '         <p class="text-muted mb-4">' . __('To start syncing your Grandstream devices, you need to connect your GDMS Cloud account. It only takes a moment.', 'gdmsintegration') . '</p>';
    echo '         <a href="' . htmlspecialchars($config_url, ENT_QUOTES, 'UTF-8') . '" class="btn btn-primary px-4">';
    echo '            <i class="fas fa-cog me-2"></i>' . __('Set up GDMS', 'gdmsintegration');
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
    $name     = $glpi ? htmlspecialchars($glpi['name'],    ENT_QUOTES, 'UTF-8') : htmlspecialchars($mac, ENT_QUOTES, 'UTF-8');
    $serial   = $glpi ? htmlspecialchars($glpi['serial']   ?? '', ENT_QUOTES, 'UTF-8') : '';
    $itemtype = $glpi ? $glpi['_itemtype'] : 'NetworkEquipment';
    $glpi_id  = $glpi ? (int)$glpi['id'] : 0;

    if ($glpi_id > 0) {
        $asset_url = $root . ($itemtype === 'Phone' ? '/front/phone.form.php?id=' : '/front/networkequipment.form.php?id=') . $glpi_id;
    } else {
        $asset_url = '';
    }

    $isOnline ? $online++ : $offline++;
    $rows[] = [
        'name'         => $name,
        'asset_url'    => htmlspecialchars($asset_url, ENT_QUOTES, 'UTF-8'),
        'type'         => $itemtype,
        'mac'          => htmlspecialchars($mac, ENT_QUOTES, 'UTF-8'),
        'serial'       => $sn_cloud ?: $serial,
        'network_name' => $net_name,
        'ip'           => $ip,
        'firmware'     => $firmware,
        'uptime_sec'   => $uptime_sec,
        'online'       => $isOnline,
        'uptime'       => $uptime,
        'sla'          => htmlspecialchars($sla, ENT_QUOTES, 'UTF-8'),
    ];
}

// Uptime history — last 60 days per device per day
$history_obj = new PluginGdmsintegrationHistory();
$sixty_ago   = date('Y-m-d H:i:s', strtotime('-60 days'));
$hist_rows   = $history_obj->find(['date' => ['>', $sixty_ago]], ['date DESC']);

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

// Topology — all MACs tracked by plugin
$entity_macs = array_keys($mac_to_asset);
$link        = new PluginGdmsintegrationLink();
$links_raw   = empty($entity_macs) ? [] : $link->find(['source_mac' => $entity_macs]);

$nodes = [];
foreach ($rows as $r) {
    $nodes[] = [
        'id'    => $r['mac'],
        'label' => $r['name'],
        'color' => ['background' => $r['online'] ? '#28a745' : '#dc3545', 'border' => '#aaa'],
        'font'  => ['color' => '#ffffff'],
        'title' => $r['name'] . ' — ' . ($r['online'] ? 'Online' : 'Offline'),
    ];
}
$edges = [];
foreach ($links_raw as $l) {
    if (!empty($l['source_mac']) && !empty($l['target_mac'])) {
        $edges[] = ['from' => $l['source_mac'], 'to' => $l['target_mac']];
    }
}
?>
<div class="container-fluid px-4 mt-3">

   <?php // Header card ?>
   <div class="card mb-4">
      <div class="card-body py-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
         <div class="d-flex align-items-center gap-3">
            <i class="fas fa-satellite-dish fa-lg text-primary"></i>
            <div>
               <h4 class="mb-0 fw-bold"><?= 'GDMS — ' . __('Dashboard', 'gdmsintegration') ?></h4>
               <small class="text-muted"><?= __('Live view of your Grandstream cloud devices', 'gdmsintegration') ?></small>
            </div>
         </div>
         <div class="d-flex align-items-center gap-2">
            <small class="text-muted" id="gdms-refresh-countdown"></small>
            <button type="button" id="gdms-refresh-btn" class="btn btn-sm btn-outline-primary">
               <i class="fas fa-sync-alt me-1" id="gdms-refresh-icon"></i><?= __('Sync now', 'gdmsintegration') ?>
            </button>
            <a href="/plugins/gdmsintegration/front/config.form.php" class="btn btn-sm btn-outline-secondary">
               <i class="fas fa-cog me-1"></i><?= __('Settings', 'gdmsintegration') ?>
            </a>
         </div>
      </div>
   </div>

   <?php // Summary cards + chart ?>
   <div class="row g-3 mb-4">
      <div class="col-6 col-md-3">
         <div class="card text-white bg-success text-center py-3">
            <div class="fs-1 fw-bold"><?= $online ?></div>
            <div><?= __('Online', 'gdmsintegration') ?></div>
         </div>
      </div>
      <div class="col-6 col-md-3">
         <div class="card text-white bg-danger text-center py-3">
            <div class="fs-1 fw-bold"><?= $offline ?></div>
            <div><?= __('Offline', 'gdmsintegration') ?></div>
         </div>
      </div>
      <div class="col-md-6">
         <div class="card p-3 h-100">
            <?php
            $total = $online + $offline;
            $pct   = $total > 0 ? round($online / $total * 100) : 0;
            ?>
            <div class="d-flex align-items-center justify-content-between mb-2">
               <span class="small fw-semibold"><?= __('Availability', 'gdmsintegration') ?></span>
               <span class="small fw-bold <?= $pct >= 90 ? 'text-success' : ($pct >= 70 ? 'text-warning' : 'text-danger') ?>"><?= $pct ?>%</span>
            </div>
            <div class="progress mb-3" style="height:12px;">
               <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
            </div>
            <div class="row text-center g-2">
               <div class="col-4">
                  <div class="small text-muted"><?= __('Total', 'gdmsintegration') ?></div>
                  <div class="fw-bold"><?= $total ?></div>
               </div>
               <div class="col-4">
                  <div class="small text-success"><?= __('Online', 'gdmsintegration') ?></div>
                  <div class="fw-bold text-success"><?= $online ?></div>
               </div>
               <div class="col-4">
                  <div class="small text-danger"><?= __('Offline', 'gdmsintegration') ?></div>
                  <div class="fw-bold text-danger"><?= $offline ?></div>
               </div>
            </div>
         </div>
      </div>
   </div>

   <?php // Device table ?>
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="fas fa-list"></i>
         <h5 class="mb-0"><?= __('Devices', 'gdmsintegration') ?></h5>
      </div>
      <div class="table-responsive">
         <table class="table table-hover table-sm mb-0">
            <thead>
               <tr>
                  <th class="ps-3"><?= __('Device Name', 'gdmsintegration') ?></th>
                  <th><?= __('Type', 'gdmsintegration') ?></th>
                  <th><?= __('Network', 'gdmsintegration') ?></th>
                  <th><?= __('IP', 'gdmsintegration') ?></th>
                  <th><?= __('MAC Address', 'gdmsintegration') ?></th>
                  <th><?= __('Serial', 'gdmsintegration') ?></th>
                  <th><?= __('Firmware', 'gdmsintegration') ?></th>
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
                        <i class="fas fa-external-link-alt ms-1 small opacity-50"></i>
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
                  <td><small><?= $r['network_name'] ?: '—' ?></small></td>
                  <td><small class="font-monospace">
                     <?php if (!empty($r['ip'])): ?>
                     <a href="https://www.whois.com/whois/<?= urlencode($r['ip']) ?>" target="_blank" rel="noopener" class="text-decoration-none">
                        <?= $r['ip'] ?> <i class="fas fa-external-link-alt opacity-50" style="font-size:.65em;"></i>
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
                        <i class="fas fa-arrow-circle-up text-warning ms-1"></i>
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
         <i class="fas fa-chart-line"></i>
         <h5 class="mb-0 me-auto"><?= __('Online % — last 60 days', 'gdmsintegration') ?></h5>
         <a href="<?= ($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration/front/history_export.php?entities_id=' . $entities_id ?>"
            class="btn btn-sm btn-outline-secondary"
            title="<?= __('Export to Excel', 'gdmsintegration') ?>">
            <i class="fas fa-file-excel me-1"></i><?= __('Export', 'gdmsintegration') ?>
         </a>
      </div>
      <div class="card-body p-0">
         <canvas id="gdms-history-chart" style="max-height:280px; padding:12px;"></canvas>
      </div>
   </div>
   <?php endif; ?>

   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="fas fa-project-diagram"></i>
         <h5 class="mb-0"><?= __('Network Topology', 'gdmsintegration') ?></h5>
      </div>
      <div id="gdms-network" style="height:450px; border-radius:0 0 .375rem .375rem; overflow:hidden;"></div>
   </div>

</div>

<script src="https://unpkg.com/vis-network@9.1.9/standalone/umd/vis-network.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>

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
        if (icon) icon.className = 'fas fa-sync-alt fa-spin me-1';
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
          <i class="fas fa-arrow-circle-up text-warning me-2"></i>
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
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
          <?= __('Close', 'gdmsintegration') ?>
        </button>
        <button type="button" class="btn btn-warning" id="gdmsFwScheduleBtn" style="display:none;">
          <i class="fas fa-calendar-check me-1"></i>
          <?= __('Schedule update', 'gdmsintegration') ?>
        </button>
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
                    // Show upgrade icon for this MAC
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
        var body    = document.getElementById('gdmsFwModalBody');
        var schedBtn = document.getElementById('gdmsFwScheduleBtn');

        // Build modal body using DOM methods to prevent XSS
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
          + '<i class="fas fa-exclamation-triangle me-1"></i>'
          + '<?= __('The device will reboot during the update. Schedule during a maintenance window.', 'gdmsintegration') ?>'
          + '</div>';

        schedBtn.style.display = 'inline-block';
        schedBtn.onclick = null;
        schedBtn.onclick = function() {
            schedBtn.disabled = true;
            schedBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?= __('Scheduling…', 'gdmsintegration') ?>';
            // GLPI 11 requires CSRF token on POST requests
        // Use FormData so GLPI reads _glpi_csrf_token from $_POST (reliable in all GLPI 11 versions)
        // glpiGetNewCSRFToken() fetches a fresh single-use token — avoids "already consumed" rejections
        var csrfValue = (typeof window.glpiGetNewCSRFToken === 'function')
            ? window.glpiGetNewCSRFToken()
            : (document.querySelector('meta[property="glpi:csrf_token"]') || {}).getAttribute('content') || '';
        var formData = new FormData();
        formData.append('_glpi_csrf_token', csrfValue);
        formData.append('macs', JSON.stringify([mac.replace(/:/g, '').toUpperCase()]));
        fetch(FW_UPGRADE_URL, {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (resp.error) {
                    var errDiv = document.createElement('div');
                    errDiv.className = 'alert alert-danger mt-2 mb-0 py-2 small';
                    errDiv.textContent = resp.error;
                    errDiv.insertAdjacentHTML('afterbegin', '<i class="fas fa-times-circle me-1"></i> ');
                    body.appendChild(errDiv);
                    schedBtn.disabled = false;
                    schedBtn.innerHTML = '<i class="fas fa-calendar-check me-1"></i><?= __('Schedule update', 'gdmsintegration') ?>';
                } else {
                    var ok = document.createElement('div');
                    ok.className = 'alert alert-success mt-2 mb-0 py-2 small';
                    ok.innerHTML = '<i class="fas fa-check-circle me-1"></i><?= __('Update scheduled successfully. The device will update shortly.', 'gdmsintegration') ?>';
                    body.appendChild(ok);
                    schedBtn.style.display = 'none';
                    // Hide the badge since update is scheduled
                    document.querySelectorAll('.gdms-fw-badge[data-mac="' + mac + '"]')
                        .forEach(function(el) { el.style.display = 'none'; });
                }
            })
            .catch(function() {
                var connErr = document.createElement('div');
                connErr.className = 'alert alert-danger mt-2 mb-0 py-2 small';
                connErr.textContent = '<?= __('Request failed. Check connection.', 'gdmsintegration') ?>';
                body.appendChild(connErr);
                schedBtn.disabled = false;
                schedBtn.innerHTML = '<i class="fas fa-calendar-check me-1"></i><?= __('Schedule update', 'gdmsintegration') ?>';
            });
        };

        // Show modal
        var modalEl = document.getElementById('gdmsFwModal');
        if (typeof bootstrap !== 'undefined') {
            new bootstrap.Modal(modalEl).show();
        } else if (typeof $ !== 'undefined') {
            $(modalEl).modal('show');
        }
    });
})();
</script>

<?php Html::footer(); ?>
