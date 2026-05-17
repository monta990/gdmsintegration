<?php
/**
 * GDMS Integration --- Configuration form
 */
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

global $CFG_GLPI;

Session::checkLoginUser();
Session::checkRight('config', UPDATE);

// ---- History import -----
if (isset($_POST['import_history'])) {
    $upload = $_FILES['history_xlsx'] ?? null;
    if (!$upload || $upload['error'] !== UPLOAD_ERR_OK || empty($upload['tmp_name'])) {
        Session::addMessageAfterRedirect(__('No file received or upload error.', 'gdmsintegration'), false, ERROR);
        Html::back();
    } else {
        try { $spreadsheet = IOFactory::load($upload['tmp_name']); }
        catch (\Exception $e) {
            Session::addMessageAfterRedirect(__('Could not read file:', 'gdmsintegration') . ' ' . $e->getMessage(), false, ERROR);
            Html::back();
            exit;
        }
        if ($spreadsheet->getSheetCount() < 2) {
            Session::addMessageAfterRedirect(__('File must contain at least 2 sheets (pivot + summary).', 'gdmsintegration'), false, ERROR);
            Html::back();
        } else {
            $cell = function($sheet, $c, $r) {
                return $sheet->getCell(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r);
            };
            $sumSheet = $spreadsheet->getSheet(1);
            $sumRows  = $sumSheet->getHighestRow();
            $nameToMac = [];
            for ($r = 2; $r <= $sumRows; $r++) {
                $name = trim((string)$cell($sumSheet,1,$r)->getValue());
                $mac  = strtolower(trim((string)$cell($sumSheet,2,$r)->getValue()));
                if ($name !== '' && $mac !== '') $nameToMac[strtolower($name)] = $mac;
            }
            $pivotSheet = $spreadsheet->getSheet(0);
            $maxCol = Coordinate::columnIndexFromString($pivotSheet->getHighestColumn());
            $maxRow = $pivotSheet->getHighestRow();
            $colToMac = [];
            for ($c = 2; $c <= $maxCol; $c++) {
                $h = trim((string)$cell($pivotSheet,$c,1)->getValue());
                if ($h === '') continue;
                $mac = $nameToMac[strtolower($h)] ?? null;
                if (!$mac) { $clean = strtolower(preg_replace('/[^0-9a-fA-F:]/','',$h)); if (strlen($clean)>=12) $mac=$clean; }
                if ($mac) $colToMac[$c] = $mac;
            }
            if (empty($colToMac)) {
                Session::addMessageAfterRedirect(__('No recognizable device columns found. Ensure the file was exported by this plugin.', 'gdmsintegration'), false, WARNING);
                Html::back();
            } else {
                $histObj  = new PluginGdmsintegrationHistory();
                $existing = [];
                foreach ($histObj->find() as $h) {
                    $m = strtolower(trim($h['mac']??'')); $d = substr($h['date']??'',0,10);
                    if ($m&&$d) $existing[$m][$d]=true;
                }
                global $DB;
                $RECORDS=100; $STEP=864; $ins=0; $skip=0;
                for ($r=2; $r<=$maxRow; $r++) {
                    $raw = $cell($pivotSheet,1,$r)->getValue();
                    if ($raw===null||$raw==='') continue;
                    $day = is_numeric($raw) ? XlDate::excelToDateTimeObject((float)$raw)->format('Y-m-d') : substr(trim((string)$raw),0,10);
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$day)) continue;
                    foreach ($colToMac as $c=>$mac) {
                        if (!empty($existing[$mac][$day])) { $skip++; continue; }
                        $val = $cell($pivotSheet,$c,$r)->getValue();
                        if ($val===null||$val==='') continue;
                        $pct = max(0.0,min(1.0,(float)$val));
                        $on  = (int)round($pct*$RECORDS);
                        $ts  = strtotime($day.' 00:08:00');
                        if ($ts===false||$ts<86400) { $skip++; continue; }
                        for ($i=0;$i<$RECORDS;$i++) {
                            $DB->insert('glpi_plugin_gdmsintegration_history', [
                                'mac'=>$mac, 'status'=>$i<$on?'online':'offline',
                                'date'=>date('Y-m-d H:i:s',$ts+$i*$STEP),
                            ]);
                        }
                        $existing[$mac][$day]=true; $ins++;
                    }
                }
                Session::addMessageAfterRedirect(sprintf(__('Import complete: %d device-days imported (%d records each), %d device-days skipped (already had data).','gdmsintegration'),$ins,$RECORDS,$skip),true,INFO);
                Html::back();
            }
        }
    }
    exit;
}

// ---- Config export -----
if (isset($_POST['export_config'])) {
    Session::checkRight('config', READ);
    $eid = (int)($_POST['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
    $cfg_obj2 = new PluginGdmsintegrationConfig();
    $cfg2 = $cfg_obj2->getConfigByEntity($eid);
    $inc = !empty($_POST['include_credentials']);
    $exp = ['_plugin'=>'gdmsintegration','_version'=>PLUGIN_GDMSINTEGRATION_VERSION,'_exported_at'=>date('c'),'_includes_credentials'=>$inc,'entities_id'=>$eid,
        'username'=>$cfg2['username']??'','refresh_interval'=>(int)($cfg2['refresh_interval']??300),
        'ip_version'=>$cfg2['ip_version']??'ipv4','debug_logging'=>(int)($cfg2['debug_logging']??0),
        'chart_days'=>(int)($cfg2['chart_days']??60),'show_topology'=>(int)($cfg2['show_topology']??1),
        'ticket_requester_id'=>(int)($cfg2['ticket_requester_id']??0),'wan_debounce_seconds'=>(int)($cfg2['wan_debounce_seconds']??300),
        'wan_tickets_enabled'=>(int)($cfg2['wan_tickets_enabled']??1),'tickets_phone'=>(int)($cfg2['tickets_phone']??1),
        'tickets_router'=>(int)($cfg2['tickets_router']??1),'tickets_switch'=>(int)($cfg2['tickets_switch']??1),
        'tickets_ap'=>(int)($cfg2['tickets_ap']??1),'tickets_pbx'=>(int)($cfg2['tickets_pbx']??1),
    ];
    if ($inc) foreach (['password','client_id','client_secret','gwn_client_id','gwn_client_secret','webhook_secret'] as $k) $exp[$k]=$cfg2[$k]??'';
    ob_clean(); // discard any GLPI debug output captured before this return
    return new \Symfony\Component\HttpFoundation\Response(
        json_encode($exp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        200,
        [
            'Content-Type'          => 'application/json; charset=utf-8',
            'Content-Disposition'   => 'attachment; filename="gdms_config_entity' . $eid . '_' . date('Y-m-d') . '.json"',
            'Cache-Control'         => 'no-cache, no-store',
            'X-Gdms-New-Csrf-Token' => Session::getNewCSRFToken(),
        ]
    );
}

// ---- Config import -----
if (isset($_POST['import_config'])) {
    $upload2 = $_FILES['config_json'] ?? null;
    if (!$upload2 || $upload2['error'] !== UPLOAD_ERR_OK) {
        Session::addMessageAfterRedirect(__('No file received or upload error.', 'gdmsintegration'), false, ERROR);
    } else {
        $data = json_decode(file_get_contents($upload2['tmp_name']), true);
        if (!is_array($data) || ($data['_plugin']??'') !== 'gdmsintegration') {
            Session::addMessageAfterRedirect(__('Invalid or unrecognised configuration file.', 'gdmsintegration'), false, ERROR);
        } else {
            $eid2 = (int)($_POST['entities_id'] ?? $data['entities_id'] ?? $_SESSION['glpiactive_entity'] ?? 0);
            $inp = ['entities_id'=>$eid2];
            foreach (['refresh_interval','ip_version','debug_logging','chart_days','show_topology','ticket_requester_id','wan_debounce_seconds','wan_tickets_enabled','tickets_phone','tickets_router','tickets_switch','tickets_ap','tickets_pbx'] as $k) {
                if (array_key_exists($k,$data)) $inp[$k]=$data[$k];
            }
            if (!empty($data['username'])) $inp['username']=$data['username'];
            if (!empty($data['_includes_credentials'])) {
                foreach (['password','client_id','client_secret','gwn_client_id','gwn_client_secret','webhook_secret'] as $k) {
                    if (array_key_exists($k,$data)) $inp[$k]=$data[$k];
                }
            }
            (new PluginGdmsintegrationConfig())->saveConfig($inp);
            Session::addMessageAfterRedirect(__('Configuration imported. API credentials were not changed — re-enter them if needed.', 'gdmsintegration'), true, INFO);
        }
    }
    Html::back(); exit;
}

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
        'ip_version'          => in_array($_POST['ip_version'] ?? '', ['ipv4', 'ipv6'], true) ? $_POST['ip_version'] : 'ipv4',
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
$ip_version           = in_array($cur['ip_version'] ?? '', ['ipv4', 'ipv6'], true) ? $cur['ip_version'] : 'ipv4';
$ticket_requester_id  = (int)($cur['ticket_requester_id'] ?? 0);
$wan_debounce_seconds = max(0, min(3600, (int)($cur['wan_debounce_seconds'] ?? 300)));
$wan_tickets_enabled  = (int)($cur['wan_tickets_enabled'] ?? 1);
$tickets_phone  = (int)($cur['tickets_phone']  ?? 1);
$tickets_router = (int)($cur['tickets_router'] ?? 1);
$tickets_switch = (int)($cur['tickets_switch'] ?? 1);
$tickets_ap     = (int)($cur['tickets_ap']     ?? 1);
$tickets_pbx    = (int)($cur['tickets_pbx']    ?? 1);

$_plugin_web = ($CFG_GLPI['root_doc'] ?? '') . '/' . basename(dirname(__DIR__, 2)) . '/gdmsintegration';
$webhook_url = rtrim($CFG_GLPI['url_base'] ?? '', '/') . $_plugin_web . '/front/webhook.php?entities_id=' . $entities_id;

Html::header(
    __('GDMS Configuration', 'gdmsintegration'),
    '',
    'config',
    'PluginGdmsintegrationConfig'
);

ob_start();
Entity::dropdown(['name' => 'entities_id', 'value' => $entities_id]);
$entity_dropdown = ob_get_clean();

$users_raw     = getAllDataFromTable('glpi_users', ['is_active' => 1, 'is_deleted' => 0]);
$users_options = [];
foreach ($users_raw as $u) {
    $uid           = (int)$u['id'];
    $label         = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) ?: ($u['name'] ?? "User #{$uid}");
    $users_options[] = ['id' => $uid, 'label' => $label, 'selected' => $uid === $ticket_requester_id];
}

echo PluginGdmsintegrationTwig::get()->render('config_form.html.twig', [
    'cur'                  => $cur,
    'entities_id'          => $entities_id,
    'entity_dropdown'      => $entity_dropdown,
    'has_gdms'             => $has_gdms,
    'has_gwn'              => $has_gwn,
    'webhook_url'          => $webhook_url,
    'refresh_interval'     => $refresh_interval,
    'chart_days'           => $chart_days,
    'show_topology'        => $show_topology,
    'ip_version'           => $ip_version,
    'ticket_requester_id'  => $ticket_requester_id,
    'wan_debounce_seconds' => $wan_debounce_seconds,
    'wan_tickets_enabled'  => $wan_tickets_enabled,
    'tickets_phone'        => $tickets_phone,
    'tickets_router'       => $tickets_router,
    'tickets_switch'       => $tickets_switch,
    'tickets_ap'           => $tickets_ap,
    'tickets_pbx'          => $tickets_pbx,
    'users_options'        => $users_options,
    'dashboard_url'        => $_plugin_web . '/front/dashboard.php',
    'csrf_main'            => Session::getNewCSRFToken(),
    'csrf_history'         => Session::getNewCSRFToken(),
    'csrf_export'          => Session::getNewCSRFToken(),
]);

Html::footer();
