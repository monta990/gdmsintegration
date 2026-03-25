<?php
/**
 * GDMS Integration — Configuration form
 */
include('../../../inc/includes.php');

// Require login and config write permission
Session::checkLoginUser();
Session::checkRight('config', UPDATE);

$config = new PluginGdmsintegrationConfig();

if (isset($_POST['save'])) {
    // Validate CSRF token before touching any data
    Session::checkCSRF($_POST);

    $config->saveConfig([
        'entities_id'    => (int) ($_POST['entities_id'] ?? 0),
        'username'       => Toolbox::addslashes_deep($_POST['username']       ?? ''),
        'password'       => Toolbox::addslashes_deep($_POST['password']       ?? ''),
        'client_id'      => Toolbox::addslashes_deep($_POST['client_id']      ?? ''),
        'client_secret'  => Toolbox::addslashes_deep($_POST['client_secret']  ?? ''),
        'webhook_secret' => Toolbox::addslashes_deep($_POST['webhook_secret'] ?? ''),
    ]);

    Session::addMessageAfterRedirect(
        __('Configuration saved.', 'gdmsintegration'),
        true,
        INFO
    );
    Html::back();
}

$entities_id = (int) ($_SESSION['glpiactive_entity'] ?? 0);
$current     = $config->getConfigByEntity($entities_id);

Html::header(
    __('GDMS Configuration', 'gdmsintegration'),
    '',
    'config',
    'PluginGdmsintegrationConfig'
);
?>
<div class="container-xl mt-3">
   <div class="card">
      <div class="card-header d-flex align-items-center gap-2">
         <i class="fas fa-cog"></i>
         <h5 class="mb-0"><?= __('GDMS Configuration', 'gdmsintegration') ?></h5>
      </div>
      <div class="card-body">
         <form method="post" action="">
            <?= Html::hidden('_glpi_csrf_token', ['value' => Session::getNewCSRFToken()]) ?>

            <div class="mb-3">
               <label class="form-label fw-bold"><?= __('Entity', 'gdmsintegration') ?></label>
               <?php Entity::dropdown([
                  'name'  => 'entities_id',
                  'value' => $entities_id,
               ]); ?>
            </div>

            <div class="mb-3">
               <label class="form-label fw-bold"><?= __('GDMS Username', 'gdmsintegration') ?></label>
               <input type="text" class="form-control" name="username"
                  value="<?= htmlspecialchars($current['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="off">
               <div class="form-text"><?= __('Your GDMS Cloud login email.', 'gdmsintegration') ?></div>
            </div>

            <div class="mb-3">
               <label class="form-label fw-bold"><?= __('GDMS Password', 'gdmsintegration') ?></label>
               <input type="password" class="form-control" name="password"
                  placeholder="<?= htmlspecialchars(__('Leave empty to keep current value', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="new-password">
               <div class="form-text"><?= __('Your GDMS Cloud login password.', 'gdmsintegration') ?></div>
            </div>

            <div class="mb-3">
               <label class="form-label fw-bold"><?= __('Client ID', 'gdmsintegration') ?></label>
               <input type="text" class="form-control" name="client_id"
                  value="<?= htmlspecialchars($current['client_id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="off">
            </div>

            <div class="mb-3">
               <label class="form-label fw-bold"><?= __('Client Secret', 'gdmsintegration') ?></label>
               <input type="password" class="form-control" name="client_secret"
                  placeholder="<?= htmlspecialchars(__('Leave empty to keep current value', 'gdmsintegration'), ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="new-password">
            </div>

            <div class="mb-4">
               <label class="form-label fw-bold"><?= __('Webhook Secret', 'gdmsintegration') ?></label>
               <input type="text" class="form-control" name="webhook_secret"
                  value="<?= htmlspecialchars($current['webhook_secret'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="off">
               <div class="form-text">
                  <?= __('Used to validate incoming GDMS webhook requests via HMAC-SHA256.', 'gdmsintegration') ?>
               </div>
            </div>

            <div class="d-flex align-items-center gap-3">
               <button type="submit" name="save" class="btn btn-primary">
                  <i class="fas fa-save me-1"></i><?= __('Save', 'gdmsintegration') ?>
               </button>
               <small class="text-muted">
                  <?= sprintf(
                     __('Webhook URL: %s', 'gdmsintegration'),
                     '<code>' . htmlspecialchars(
                        Plugin::getWebDir('gdmsintegration', false)
                        . '/front/webhook.php?entities_id=' . $entities_id,
                        ENT_QUOTES, 'UTF-8'
                     ) . '</code>'
                  ) ?>
               </small>
            </div>
         </form>
      </div>
   </div>
</div>
<?php Html::footer(); ?>
