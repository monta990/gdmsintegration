<?php
/**
 * GDMS Integration — NOC Dashboard
 */
include('../../../inc/includes.php');

Session::checkLoginUser();

// Require at minimum read on config or networking
if (!Session::haveRight('config', READ) && !Session::haveRight('networking', READ)) {
    Html::forbidden();
    exit;
}

Html::header(
    __('GDMS Dashboard', 'gdmsintegration'),
    '',
    'plugins',
    'PluginGdmsintegrationMenu'
);

$entities_id = (int) ($_SESSION['glpiactive_entity'] ?? 0);

// Load GDMS-managed assets from both NetworkEquipment and Phone
$online  = 0;
$offline = 0;
$rows    = [];

foreach (['NetworkEquipment', 'Phone'] as $itemtype) {
    $obj = new $itemtype();
    $all = $obj->find(['entities_id' => $entities_id]);
    foreach (array_filter($all, static fn($d) => str_starts_with($d['comment'] ?? '', 'GDMS:')) as $d) {
        $isOnline = ($d['comment'] === 'GDMS:Online');
        $isOnline ? $online++ : $offline++;

        $mac    = $d['uuid'] ?? '';
        $uptime = PluginGdmsintegrationSync::calculateUptime($mac);
        $sla    = PluginGdmsintegrationSync::calculateSLA($mac);

        $rows[] = [
            'name'     => htmlspecialchars($d['name'],   ENT_QUOTES, 'UTF-8'),
            'mac'      => htmlspecialchars($mac,          ENT_QUOTES, 'UTF-8'),
            'serial'   => htmlspecialchars($d['serial'] ?? '', ENT_QUOTES, 'UTF-8'),
            'type'     => $itemtype,
            'online'   => $isOnline,
            'uptime'   => $uptime,
            'sla'      => htmlspecialchars($sla,          ENT_QUOTES, 'UTF-8'),
        ];
    }
}

// Build topology data (already escaped values for JS)
$link      = new PluginGdmsintegrationLink();
$links_raw = $link->find();

$nodes = [];
foreach ($rows as $r) {
    $nodes[] = [
        'id'    => $r['mac'],
        'label' => $r['name'],
        'color' => ['background' => $r['online'] ? '#28a745' : '#dc3545', 'border' => '#ffffff'],
        'font'  => ['color' => '#ffffff'],
    ];
}

$edges = [];
foreach ($links_raw as $l) {
    if (!empty($l['source_mac']) && !empty($l['target_mac'])) {
        $edges[] = [
            'from' => htmlspecialchars($l['source_mac'], ENT_QUOTES, 'UTF-8'),
            'to'   => htmlspecialchars($l['target_mac'], ENT_QUOTES, 'UTF-8'),
        ];
    }
}
?>
<div class="container-xl mt-3">

   <!-- Summary cards -->
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
         <div class="card p-3 h-100 d-flex align-items-center justify-content-center">
            <canvas id="gdms-chart" style="max-height:140px;"></canvas>
         </div>
      </div>
   </div>

   <!-- Device table -->
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="fas fa-list"></i>
         <h5 class="mb-0"><?= __('Devices', 'gdmsintegration') ?></h5>
      </div>
      <div class="card-body p-0">
         <table class="table table-hover table-sm mb-0">
            <thead class="table-dark">
               <tr>
                  <th><?= __('Device Name',  'gdmsintegration') ?></th>
                  <th><?= __('Type',         'gdmsintegration') ?></th>
                  <th><?= __('MAC Address',  'gdmsintegration') ?></th>
                  <th><?= __('Serial',       'gdmsintegration') ?></th>
                  <th><?= __('Status',       'gdmsintegration') ?></th>
                  <th><?= __('Uptime %',     'gdmsintegration') ?></th>
                  <th><?= __('SLA',          'gdmsintegration') ?></th>
               </tr>
            </thead>
            <tbody>
               <?php foreach ($rows as $r): ?>
               <tr class="<?= $r['online'] ? 'table-success' : 'table-danger' ?>">
                  <td><?= $r['name'] ?></td>
                  <td>
                     <span class="badge <?= $r['type'] === 'Phone' ? 'bg-info' : 'bg-secondary' ?>">
                        <?= $r['type'] === 'Phone'
                              ? __('Phone', 'gdmsintegration')
                              : __('Network', 'gdmsintegration') ?>
                     </span>
                  </td>
                  <td><code><?= $r['mac'] ?></code></td>
                  <td><small><?= $r['serial'] ?: '—' ?></small></td>
                  <td>
                     <span class="badge <?= $r['online'] ? 'bg-success' : 'bg-danger' ?>">
                        <?= $r['online']
                              ? __('Online',  'gdmsintegration')
                              : __('Offline', 'gdmsintegration') ?>
                     </span>
                  </td>
                  <td><?= $r['uptime'] ?>%</td>
                  <td><?= $r['sla'] ?></td>
               </tr>
               <?php endforeach; ?>
               <?php if (empty($rows)): ?>
               <tr><td colspan="7" class="text-center text-muted py-3">
                  <?= __('No GDMS devices found. Run a sync first.', 'gdmsintegration') ?>
               </td></tr>
               <?php endif; ?>
            </tbody>
         </table>
      </div>
   </div>

   <!-- Topology -->
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="fas fa-project-diagram"></i>
         <h5 class="mb-0"><?= __('Network Topology', 'gdmsintegration') ?></h5>
      </div>
      <div class="card-body p-0">
         <div id="gdms-network" style="height:500px; background:#f8f9fa;"></div>
      </div>
   </div>

</div>

<!-- Chart.js CDN (integrity hash for security) -->
<script
   src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"
   integrity="sha256-oVuCFpJsqMqFe1w3BDNqcnSAJDRi77gAOWJHx0lyJ7w="
   crossorigin="anonymous"></script>
<!-- vis-network CDN -->
<script src="https://unpkg.com/vis-network@9.1.9/standalone/umd/vis-network.min.js"></script>

<script>
(function () {
   'use strict';

   // Doughnut chart
   var ctx = document.getElementById('gdms-chart');
   if (ctx) {
      new Chart(ctx, {
         type: 'doughnut',
         data: {
            labels: [
               <?= json_encode(__('Online',  'gdmsintegration')) ?>,
               <?= json_encode(__('Offline', 'gdmsintegration')) ?>
            ],
            datasets: [{
               data: [<?= (int) $online ?>, <?= (int) $offline ?>],
               backgroundColor: ['#28a745', '#dc3545'],
               borderWidth: 0
            }]
         },
         options: {
            plugins: { legend: { position: 'right' } },
            cutout: '60%'
         }
      });
   }

   // vis-network topology
   var container = document.getElementById('gdms-network');
   if (container) {
      var nodes = new vis.DataSet(<?= json_encode(array_values($nodes)) ?>);
      var edges = new vis.DataSet(<?= json_encode(array_values($edges)) ?>);
      new vis.Network(
         container,
         { nodes: nodes, edges: edges },
         {
            physics: { stabilization: { iterations: 150 } },
            edges:   { arrows: 'to', color: '#999' },
            nodes:   { shape: 'dot', size: 18 }
         }
      );
   }

   // Auto-refresh every 60 s
   setTimeout(function () { location.reload(); }, 60000);
})();
</script>

<?php Html::footer(); ?>
