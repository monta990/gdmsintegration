<?php
/**
 * GDMS Integration — Configuration form
 */
global $CFG_GLPI;

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

$config = new PluginGdmsintegrationConfig();

if (isset($_POST['save'])) {
    // GLPI 11: CSRF validated automatically by Symfony CheckCsrfListener before reaching here.

    $config->saveConfig([
        'entities_id'       => (int) ($_POST['entities_id']       ?? 0),
        'username'          => $_POST['username']          ?? '',
        'password'          => $_POST['password']          ?? '',
        'client_id'         => $_POST['client_id']         ?? '',
        'client_secret'     => $_POST['client_secret']     ?? '',
        'gwn_client_id'     => $_POST['gwn_client_id']     ?? '',
        'gwn_client_secret' => $_POST['gwn_client_secret'] ?? '',
        'webhook_secret'      => $_POST['webhook_secret']      ?? '',
        'refresh_interval'    => max(30, (int)($_POST['refresh_interval'] ?? 300)),
        'debug_logging'       => isset($_POST['debug_logging']) ? 1 : 0,
        'chart_days'          => max(7, min(365, (int)($_POST['chart_days'] ?? 60))),
        'show_topology'       => isset($_POST['show_topology']) ? 1 : 0,
        'ticket_requester_id'  => (int)($_POST['ticket_requester_id'] ?? 0),
        'wan_debounce_seconds' => max(0, min(3600, (int)($_POST['wan_debounce_seconds'] ?? 300))),
        'wan_tickets_enabled'  => isset($_POST['wan_tickets_enabled']) ? 1 : 0,
        'tickets_phone'        => isset($_POST['tickets_phone'])  ? 1 : 0,
        'tickets_router'       => isset($_POST['tickets_router']) ? 1 : 0,
        'tickets_switch'       => isset($_POST['tickets_switch']) ? 1 : 0,
        'tickets_ap'           => isset($_POST['tickets_ap'])     ? 1 : 0,
        'tickets_pbx'          => isset($_POST['tickets_pbx'])    ? 1 : 0,
    ]);

    // Real connection test after saving
    $entities_id_test = (int) ($_POST['entities_id'] ?? 0);
    $saved_config     = $config->getConfigByEntity($entities_id_test);
    $test             = PluginGdmsintegrationAPI::testConnections($saved_config);

    if ($test['gwn'] === true) {
        Session::addMessageAfterRedirect(
            __('GWN Networking: connection successful.', 'gdmsintegration'), true, INFO
        );
    } elseif ($test['gwn'] !== null) {
        Session::addMessageAfterRedirect(
            __('GWN Networking:', 'gdmsintegration') . ' ' . $test['gwn'], false, ERROR
        );
    }

    if ($test['gdms'] === true) {
        Session::addMessageAfterRedirect(
            __('GDMS Unified Communications: connection successful.', 'gdmsintegration'), true, INFO
        );
    } elseif ($test['gdms'] !== null) {
        Session::addMessageAfterRedirect(
            __('GDMS Unified Communications:', 'gdmsintegration') . ' ' . $test['gdms'], false, ERROR
        );
    }

    if ($test['gwn'] === null && $test['gdms'] === null) {
        Session::addMessageAfterRedirect(
            __('Configuration saved. No credentials to test yet.', 'gdmsintegration'), true, WARNING
        );
    } else {
        Session::addMessageAfterRedirect(
            __('Configuration saved.', 'gdmsintegration'), true, INFO
        );
    }

    // Clear cached debug flag so next request re-reads from DB
    unset($_SESSION['_gdms_debug']);
    Html::back();
}

$entities_id = (int) ($_SESSION['glpiactive_entity'] ?? 0);
$cur = $config->getConfigByEntity($entities_id);
// Always clear debug session cache on config page load so displayed state is fresh from DB
unset($_SESSION['_gdms_debug']);

$has_gdms         = !empty($cur['client_id']) && !empty($cur['client_secret']);
$refresh_interval = (int)($cur['refresh_interval'] ?? 300);
$has_gwn  = !empty($cur['gwn_client_id']) && !empty($cur['gwn_client_secret']);
$chart_days           = max(7, min(365, (int)($cur['chart_days'] ?? 60)));
$show_topology        = (int)($cur['show_topology'] ?? 1);
$ticket_requester_id  = (int)($cur['ticket_requester_id'] ?? 0);
$wan_debounce_seconds = max(0, min(3600, (int)($cur['wan_debounce_seconds'] ?? 300)));
$wan_tickets_enabled  = (int)($cur['wan_tickets_enabled'] ?? 1);
$tickets_phone  = (int)($cur['tickets_phone']  ?? 1);
$tickets_router = (int)($cur['tickets_router'] ?? 1);
$tickets_switch = (int)($cur['tickets_switch'] ?? 1);
$tickets_ap     = (int)($cur['tickets_ap']     ?? 1);
$tickets_pbx    = (int)($cur['tickets_pbx']    ?? 1);

// Full absolute webhook URL using the GLPI base URL
$webhook_url = htmlspecialchars(
    rtrim($CFG_GLPI['url_base'] ?? '', '/')
    . '/plugins/gdmsintegration/front/webhook.php?entities_id=' . $entities_id,
    ENT_QUOTES, 'UTF-8'
);

Html::header(
    __('GDMS Configuration', 'gdmsintegration'),
    '',
    'config',
    'PluginGdmsintegrationConfig'
);

// Helper: password field with show/hide eye toggle
function gdms_pw(string $name, string $placeholder, bool $saved): string {
    $ph  = htmlspecialchars(
        $saved ? __('Saved — leave empty to keep', 'gdmsintegration') : $placeholder,
        ENT_QUOTES, 'UTF-8'
    );
    $uid = 'gdms_pw_' . $name;
    return "<div class='input-group'>"
         . "<input type='password' class='form-control gdms-pw' name='{$name}' id='{$uid}'"
         . " autocomplete='new-password' placeholder='{$ph}'>"
         . "<button type='button' class='btn btn-outline-secondary gdms-pw-toggle'"
         . " data-target='{$uid}' tabindex='-1'>"
         . "<i class='ti ti-eye'></i></button>"
         . "</div>";
}

function gdms_badge(bool $ok): string {
    if ($ok) {
        return "<span class='badge bg-success'>"
             . "<i class='ti ti-check-circle me-1'></i>"
             . __('Connected', 'gdmsintegration') . "</span>";
    }
    return "<span class='badge bg-warning text-dark'>"
         . "<i class='ti ti-alert-triangle me-1'></i>"
         . __('Not configured', 'gdmsintegration') . "</span>";
}
?>
<div class="container-fluid px-4 mt-3">

   <form method='post' action=''>

   <?php // ── Entity ──────────────────────────────────────────────────── ?>
   <div class="row mb-4">
      <div class="col-md-4">
         <label class="form-label fw-semibold"><?= __('Entity', 'gdmsintegration') ?></label>
         <?php Entity::dropdown(['name' => 'entities_id', 'value' => $entities_id]); ?>
         <small class="text-muted d-block mt-1"><?= __('GLPI entity this configuration applies to.', 'gdmsintegration') ?></small>
      </div>
   </div>

   <?php // ── GDMS login (separate card, top) ──────────────────────── ?>
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="ti ti-user-shield"></i>
         <h5 class="mb-0"><?= __('GDMS Account', 'gdmsintegration') ?></h5>
      </div>
      <div class="card-body">
         <p class="text-muted mb-3">
            <?= __('Your gdms.cloud login credentials. Used to authenticate against the GDMS API.', 'gdmsintegration') ?>
         </p>
         <div class="row g-3">
            <div class="col-md-6">
               <label class="form-label fw-semibold"><?= __('GDMS Username', 'gdmsintegration') ?></label>
               <input type="text" class="form-control" name="username"
                  value="<?= htmlspecialchars($cur['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="off"
                  placeholder="<?= htmlspecialchars(__('Your gdms.cloud username', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>">
               <small class="text-muted d-block mt-1"><?= __('Username (not email) to sign in to gdms.cloud.', 'gdmsintegration') ?></small>
            </div>
            <div class="col-md-6">
               <label class="form-label fw-semibold"><?= __('GDMS Password', 'gdmsintegration') ?></label>
               <?= gdms_pw('password', __('Your gdms.cloud password', 'gdmsintegration'), !empty($cur['password'])) ?>
               <small class="text-muted d-block mt-1"><?= __('Your gdms.cloud login password. Stored encrypted.', 'gdmsintegration') ?></small>
            </div>
         </div>
      </div>
   </div>

   <?php // ── GWN — Networking (first) ─────────────────────────────── ?>
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center justify-content-between">
         <div class="d-flex align-items-center gap-2">
            <i class="ti ti-network"></i>
            <h5 class="mb-0"><?= __('GDMS Networking — APs, Switches & Routers', 'gdmsintegration') ?></h5>
         </div>
         <?= gdms_badge($has_gwn) ?>
      </div>
      <div class="card-body">
         <div class="alert alert-info d-flex gap-2 align-items-start mb-3">
            <i class="ti ti-info-circle mt-1 flex-shrink-0"></i>
            <span>
               <?= __('Syncs GWN APs, switches and routers. No username or password required — only API credentials.', 'gdmsintegration') ?>
               <?= __('Credentials from', 'gdmsintegration') ?>
               <a href="https://www.gdms.cloud/developer" target="_blank" rel="noopener">gdms.cloud → GDMS Networking → API Developer</a>.
            </span>
         </div>
         <div class="row g-3">
            <div class="col-md-6">
               <label class="form-label fw-semibold"><?= __('API ID', 'gdmsintegration') ?></label>
               <input type="text" class="form-control" name="gwn_client_id"
                  value="<?= htmlspecialchars($cur['gwn_client_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="off"
                  placeholder="<?= htmlspecialchars(__('API ID from GWN developer page', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>">
               <small class="text-muted d-block mt-1"><?= __('API ID from gdms.cloud → GDMS Networking → API Developer.', 'gdmsintegration') ?></small>
            </div>
            <div class="col-md-6">
               <label class="form-label fw-semibold"><?= __('API Secret Key', 'gdmsintegration') ?></label>
               <?= gdms_pw('gwn_client_secret', __('Secret Key from GWN developer page', 'gdmsintegration'), !empty($cur['gwn_client_secret'])) ?>
               <small class="text-muted d-block mt-1"><?= __('API Secret Key from gdms.cloud → GDMS Networking → API Developer. Stored encrypted.', 'gdmsintegration') ?></small>
            </div>
         </div>
      </div>
   </div>

   <?php // ── GDMS — UC/VoIP (second) ──────────────────────────────── ?>
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center justify-content-between">
         <div class="d-flex align-items-center gap-2">
            <i class="ti ti-phone"></i>
            <h5 class="mb-0"><?= __('GDMS Unified Communications — Phones & PBX', 'gdmsintegration') ?></h5>
         </div>
         <?= gdms_badge($has_gdms) ?>
      </div>
      <div class="card-body">
         <div class="alert alert-info d-flex gap-2 align-items-start mb-3">
            <i class="ti ti-info-circle mt-1 flex-shrink-0"></i>
            <span>
               <?= __('Syncs GRP, GXP, GXV, WP phones and UCM PBX appliances.', 'gdmsintegration') ?>
               <?= __('Credentials from', 'gdmsintegration') ?>
               <a href="https://www.gdms.cloud/developer" target="_blank" rel="noopener">gdms.cloud → System → API Development</a>.
            </span>
         </div>
         <div class="row g-3">
            <div class="col-md-6">
               <label class="form-label fw-semibold"><?= __('API ID', 'gdmsintegration') ?></label>
               <input type="text" class="form-control" name="client_id"
                  value="<?= htmlspecialchars($cur['client_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="off"
                  placeholder="<?= htmlspecialchars(__('API ID from GDMS developer page', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>">
               <small class="text-muted d-block mt-1"><?= __('API ID shown in GDMS → System → API Development.', 'gdmsintegration') ?></small>
            </div>
            <div class="col-md-6">
               <label class="form-label fw-semibold"><?= __('API Secret Key', 'gdmsintegration') ?></label>
               <?= gdms_pw('client_secret', __('Secret Key from GDMS developer page', 'gdmsintegration'), !empty($cur['client_secret'])) ?>
               <small class="text-muted d-block mt-1"><?= __('API Secret Key from GDMS → System → API Development. Stored encrypted.', 'gdmsintegration') ?></small>
            </div>
         </div>
      </div>
   </div>

   <?php // ── Webhook ──────────────────────────────────────────────────── ?>
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="ti ti-bolt"></i>
         <h5 class="mb-0"><?= __('Webhook (optional)', 'gdmsintegration') ?></h5>
      </div>
      <div class="card-body">
         <p class="text-muted mb-3">
            <?= __('A webhook lets GDMS notify GLPI immediately when a device goes offline, instead of waiting for the next scheduled sync. If you configure it in GDMS, paste the URL below into GDMS → System → Alerts → Webhook.', 'gdmsintegration') ?>
         </p>
         <div class="row g-3">
            <div class="col-md-6">
               <label class="form-label fw-semibold"><?= __('Webhook Secret', 'gdmsintegration') ?></label>
               <input type="text" class="form-control" name="webhook_secret"
                  value="<?= htmlspecialchars($cur['webhook_secret'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="off"
                  placeholder="<?= htmlspecialchars(__('Optional — leave empty to skip validation', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>">
               <small class="text-muted d-block mt-1"><?= __('If set, GDMS must sign each request with this secret. Recommended for security.', 'gdmsintegration') ?></small>
            </div>
            <div class="col-md-6">
               <label class="form-label fw-semibold"><?= __('Your Webhook URL', 'gdmsintegration') ?></label>
               <div class="input-group">
                  <input type="text" class="form-control font-monospace"
                     value="<?= $webhook_url ?>" readonly id="gdms-webhook-url">
                  <button type="button" class="btn btn-outline-secondary"
                     title="<?= htmlspecialchars(__('Copy URL', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"
                     onclick="navigator.clipboard.writeText(document.getElementById('gdms-webhook-url').value).then(function(){var b=this;b.innerHTML='<i class=\'ti ti-check\'></i>';setTimeout(function(){b.innerHTML='<i class=\'ti ti-copy\'></i>';},1500)}.bind(this))">
                     <i class="ti ti-copy"></i>
                  </button>
               </div>
               <small class="text-muted d-block mt-1"><?= __('Copy this URL and paste it in the GDMS webhook settings.', 'gdmsintegration') ?></small>
            </div>
         </div>
      </div>
      <div class="card-body border-top py-3">
         <div class="row g-3">
            <div class="col-md-4">
               <label class="form-label fw-semibold"><?= __('Dashboard auto-refresh (seconds)', 'gdmsintegration') ?></label>
               <input type="number" class="form-control" name="refresh_interval"
                  min="30" max="3600"
                  value="<?= (int)$refresh_interval ?>">
               <small class="text-muted d-block mt-1"><?= __('How often the dashboard syncs from the cloud. Minimum 30s, default 300s (5 min).', 'gdmsintegration') ?></small>
            </div>
            <div class="mb-3">
               <label class="form-label fw-semibold"><?= __('Debug logging', 'gdmsintegration') ?></label>
               <div class="form-check form-switch mt-1">
                  <input class="form-check-input" type="checkbox" name="debug_logging" id="gdms_debug_logging" value="1"
                         <?= ((int)($cur['debug_logging'] ?? 0) === 1) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="gdms_debug_logging">
                     <?= __('Verbose logging (debug mode)', 'gdmsintegration') ?>
                  </label>
               </div>
               <small class="text-muted d-block mt-1">
                  <?= __('Enable verbose API and sync logging. Recommended only for troubleshooting — generates large log entries.', 'gdmsintegration') ?>
               </small>
               <small class="text-muted d-block"><?= __('When disabled, only errors and key sync results are logged.', 'gdmsintegration') ?></small>
            </div>
         </div>
      </div>
   </div>

   <!-- ── Dashboard & Tickets ───────────────────────────────────────────── -->
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="ti ti-dashboard"></i>
         <h5 class="mb-0"><?= __('Dashboard & Tickets', 'gdmsintegration') ?></h5>
      </div>
      <div class="card-body">
         <div class="row g-3">
            <div class="col-md-4">
               <label class="form-label fw-semibold"><?= __('Availability chart — days to display', 'gdmsintegration') ?></label>
               <input type="number" class="form-control" name="chart_days"
                  min="7" max="365" value="<?= $chart_days ?>">
               <small class="text-muted d-block mt-1"><?= __('Recommended: 60 days. Values above 90 days may slow down the dashboard due to larger history queries.', 'gdmsintegration') ?></small>
            </div>
            <div class="col-md-4">
               <label class="form-label fw-semibold"><?= __('Ticket requester (user)', 'gdmsintegration') ?></label>
               <?php
               $users = getAllDataFromTable('glpi_users', ['is_active' => 1, 'is_deleted' => 0]);
               echo '<select class="form-select" name="ticket_requester_id">';
               echo '<option value="0">' . __('System / cron user (default)', 'gdmsintegration') . '</option>';
               foreach ($users as $u) {
                   $uid   = (int)$u['id'];
                   $label = htmlspecialchars(trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) ?: ($u['name'] ?? "User #{$uid}"), ENT_QUOTES, 'UTF-8');
                   $sel   = ($uid === $ticket_requester_id) ? ' selected' : '';
                   echo "<option value=\"{$uid}\"{$sel}>{$label}</option>";
               }
               echo '</select>';
               ?>
               <small class="text-muted d-block mt-1"><?= __('This user will be set as the requester on automatically generated incident tickets.', 'gdmsintegration') ?></small>
            </div>
            <div class="col-md-4">
               <label class="form-label fw-semibold d-block"><?= __('Network topology card', 'gdmsintegration') ?></label>
               <div class="form-check form-switch mt-2">
                  <input class="form-check-input" type="checkbox" name="show_topology" id="gdms_show_topology" value="1"
                         <?= ($show_topology === 1) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="gdms_show_topology">
                     <?= __('Show topology card on dashboard', 'gdmsintegration') ?>
                  </label>
               </div>
               <small class="text-muted d-block mt-1"><?= __('When disabled, the topology graph is hidden and its data processing is skipped entirely.', 'gdmsintegration') ?></small>
            </div>
         </div>
         <div class="row g-3 mt-1">
            <div class="col-md-4">
               <label class="form-label fw-semibold"><?= __('WAN no-internet debounce (seconds)', 'gdmsintegration') ?></label>
               <input type="number" class="form-control" name="wan_debounce_seconds"
                  min="0" max="3600" value="<?= $wan_debounce_seconds ?>">
               <small class="text-muted d-block mt-1">
                  <?= __('Seconds to wait before opening a "no internet" WAN ticket. Prevents false alerts from transient high-latency events. 0 = open immediately. Default: 300.', 'gdmsintegration') ?>
               </small>
            </div>
            <div class="col-md-4">
               <label class="form-label fw-semibold d-block"><?= __('Create WAN port tickets', 'gdmsintegration') ?></label>
               <div class="form-check form-switch mt-2">
                  <input class="form-check-input" type="checkbox" name="wan_tickets_enabled" id="gdms_wan_tickets_enabled" value="1"
                         <?= ($wan_tickets_enabled === 1) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="gdms_wan_tickets_enabled">
                     <?= __('Open tickets on WAN port failures', 'gdmsintegration') ?>
                  </label>
               </div>
               <small class="text-muted d-block mt-1">
                  <?= __('When enabled, the plugin automatically opens incident tickets when a WAN port reports link-down or internet loss. Disable to suppress all WAN port alert tickets.', 'gdmsintegration') ?>
               </small>
            </div>
         </div>
      </div>
   </div>

   <!-- ── Ticket creation by device type ──────────────────────────────────── -->
   <div class="card mb-4">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="ti ti-ticket"></i>
         <h5 class="mb-0"><?= __('Ticket creation by device type', 'gdmsintegration') ?></h5>
      </div>
      <div class="card-body">
         <p class="text-muted mb-3">
            <?= __('Controls which device types trigger offline incident tickets when they go offline. All types are enabled by default.', 'gdmsintegration') ?>
         </p>
         <div class="row g-3">
            <div class="col-md-4 col-lg-2">
               <div class="form-check form-switch mt-1">
                  <input class="form-check-input" type="checkbox" name="tickets_phone" id="gdms_tickets_phone" value="1"
                         <?= ($tickets_phone === 1) ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="gdms_tickets_phone">
                     <?= __('IP Phones (GRP, GXP, GXV, WP)', 'gdmsintegration') ?>
                  </label>
               </div>
            </div>
            <div class="col-md-4 col-lg-2">
               <div class="form-check form-switch mt-1">
                  <input class="form-check-input" type="checkbox" name="tickets_router" id="gdms_tickets_router" value="1"
                         <?= ($tickets_router === 1) ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="gdms_tickets_router">
                     <?= __('Routers (GWN7001/7002/7003)', 'gdmsintegration') ?>
                  </label>
               </div>
            </div>
            <div class="col-md-4 col-lg-2">
               <div class="form-check form-switch mt-1">
                  <input class="form-check-input" type="checkbox" name="tickets_switch" id="gdms_tickets_switch" value="1"
                         <?= ($tickets_switch === 1) ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="gdms_tickets_switch">
                     <?= __('Switches (GWN7800, GSS)', 'gdmsintegration') ?>
                  </label>
               </div>
            </div>
            <div class="col-md-4 col-lg-2">
               <div class="form-check form-switch mt-1">
                  <input class="form-check-input" type="checkbox" name="tickets_ap" id="gdms_tickets_ap" value="1"
                         <?= ($tickets_ap === 1) ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="gdms_tickets_ap">
                     <?= __('Access Points (GWN76xx)', 'gdmsintegration') ?>
                  </label>
               </div>
            </div>
            <div class="col-md-4 col-lg-2">
               <div class="form-check form-switch mt-1">
                  <input class="form-check-input" type="checkbox" name="tickets_pbx" id="gdms_tickets_pbx" value="1"
                         <?= ($tickets_pbx === 1) ? 'checked' : '' ?>>
                  <label class="form-check-label fw-semibold" for="gdms_tickets_pbx">
                     <?= __('IP PBX / UCM (UCM, GCC)', 'gdmsintegration') ?>
                  </label>
               </div>
            </div>
         </div>
      </div>
   </div>

   <div class="mb-4 d-flex align-items-center gap-3 flex-wrap">
      <button type="submit" name="save" class="btn btn-primary px-4">
         <i class="ti ti-device-floppy me-1"></i><?= __('Save', 'gdmsintegration') ?>
      </button>
      <?php if ($has_gdms || $has_gwn): ?>
      <a href="/plugins/gdmsintegration/front/dashboard.php" class="btn btn-outline-secondary px-4">
         <i class="ti ti-dashboard me-2"></i><?= __('Go to Dashboard', 'gdmsintegration') ?>
      </a>
      <?php endif; ?>
   </div>

   <?php
   // Inject CSRF token — required for GLPI 11 Symfony CheckCsrfListener
   echo Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]);
   ?>
   </form>

</div>
<script>
document.querySelectorAll('.gdms-pw-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var input = document.getElementById(this.dataset.target);
        if (!input) return;
        input.type = input.type === 'password' ? 'text' : 'password';
        this.querySelector('i').className = input.type === 'password' ? 'ti ti-eye' : 'ti ti-eye-slash';
    });
});
</script>
<?php Html::footer(); ?>
