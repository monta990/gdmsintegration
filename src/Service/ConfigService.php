<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

final class ConfigService
{
    public static function handle(Request $request, string $configUrl): Response
    {
            global $CFG_GLPI;

            $form = $request->request;

            \Session::checkLoginUser();
            \Session::checkRight('config', UPDATE);

            $config      = new \GlpiPlugin\Gdmsintegration\Config();
            $entities_id = (int)\Session::getActiveEntity();
            $cur         = $config->getConfigByEntity($entities_id);

            // ---- History import -----
            if (!\Session::haveAccessToEntity($entities_id)) { throw new \Glpi\Exception\Http\AccessDeniedHttpException(); }

            if ($request->request->has('import_history')) {
                $upload = $request->files->get('history_xlsx');
                $upload_ok = $upload && $upload->isValid() && $upload->getPathname() !== '';
                $xlsx_mime = $upload_ok ? $upload->getMimeType() : false;
                $xlsx_ok   = in_array($xlsx_mime, [
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/zip', // xlsx is a zip; some finfo builds return this
                ], true);
                $max_xlsx_mb  = max(1, min(50, (int)($cur['max_xlsx_size_mb'] ?? 5)));
                $xlsx_size_ok = $upload_ok && ($upload->getSize() ?? 0) <= $max_xlsx_mb * 1024 * 1024;
                if (!$upload_ok || !$xlsx_ok || !$xlsx_size_ok) {
                    \Session::addMessageAfterRedirect(__('No file received or upload error.', 'gdmsintegration'), false, ERROR);
                    return new \Symfony\Component\HttpFoundation\RedirectResponse($configUrl);
                } else {
                    try { $spreadsheet = IOFactory::load($upload->getPathname()); }
                    catch (\Exception $e) {
                        \Session::addMessageAfterRedirect(__('Could not read file:', 'gdmsintegration') . ' ' . $e->getMessage(), false, ERROR);
                        return new \Symfony\Component\HttpFoundation\RedirectResponse($configUrl);
                    }
                    if ($spreadsheet->getSheetCount() < 2) {
                        \Session::addMessageAfterRedirect(__('File must contain at least 2 sheets (pivot + summary).', 'gdmsintegration'), false, ERROR);
                        return new \Symfony\Component\HttpFoundation\RedirectResponse($configUrl);
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
                            \Session::addMessageAfterRedirect(__('No recognizable device columns found. Ensure the file was exported by this plugin.', 'gdmsintegration'), false, WARNING);
                            return new \Symfony\Component\HttpFoundation\RedirectResponse($configUrl);
                        } else {
                            $histObj  = new \GlpiPlugin\Gdmsintegration\History();
                            $existing = [];
                            foreach ($histObj->find() as $h) {
                                $m = strtolower(trim($h['mac']??'')); $d = substr($h['date']??'',0,10);
                                if ($m&&$d) $existing[$m][$d]=true;
                            }
                            global $DB;
                            $RECORDS=100; $STEP=864; $ins=0; $skip=0;
                            $DB->startTransaction();
                            try {
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
                                $DB->commit();
                            } catch (\Throwable $e) {
                                $DB->rollback();
                                throw $e;
                            }
                            \Session::addMessageAfterRedirect(sprintf(__('Import complete: %d device-days imported (%d records each), %d device-days skipped (already had data).','gdmsintegration'),$ins,$RECORDS,$skip),true,INFO);
                            return new \Symfony\Component\HttpFoundation\RedirectResponse($configUrl);
                        }
                    }
                }
                return new \Symfony\Component\HttpFoundation\RedirectResponse($configUrl);
            }

            // ---- Config export -----
            if ($request->request->has('export_config')) {
                \Session::checkRight('config', READ);
                $eid = (int)($request->request->get('entities_id', \Session::getActiveEntity()) ?? 0);
                $cfg_obj2 = new \GlpiPlugin\Gdmsintegration\Config();
                $cfg2 = $cfg_obj2->getConfigByEntity($eid);
                $inc = !empty($request->request->get('include_credentials'));
                $exp = ['_plugin'=>'gdmsintegration','_version'=>PLUGIN_GDMSINTEGRATION_VERSION,'_exported_at'=>date('c'),'_includes_credentials'=>$inc,'entities_id'=>$eid,
                    'username'=>$cfg2['username']??'','gdms_region'=>$cfg2['gdms_region']??'us','refresh_interval'=>(int)($cfg2['refresh_interval']??300),
                    'ip_version'=>$cfg2['ip_version']??'ipv4','debug_logging'=>(int)($cfg2['debug_logging']??0),
                    'chart_days'=>(int)($cfg2['chart_days']??60),'show_topology'=>(int)($cfg2['show_topology']??1),
                    'ticket_requester_id'=>(int)($cfg2['ticket_requester_id']??0),'ticket_category_network_id'=>(int)($cfg2['ticket_category_network_id']??0),'ticket_category_telephony_id'=>(int)($cfg2['ticket_category_telephony_id']??0),'wan_debounce_seconds'=>(int)($cfg2['wan_debounce_seconds']??300),
                    'wan_tickets_enabled'=>(int)($cfg2['wan_tickets_enabled']??1),'tickets_phone'=>(int)($cfg2['tickets_phone']??1),
                    'tickets_router'=>(int)($cfg2['tickets_router']??1),'tickets_switch'=>(int)($cfg2['tickets_switch']??1),
                    'tickets_ap'=>(int)($cfg2['tickets_ap']??1),'tickets_pbx'=>(int)($cfg2['tickets_pbx']??1),
                    'max_xlsx_size_mb'=>(int)($cfg2['max_xlsx_size_mb']??5),
                ];
                if ($inc) foreach (['password','client_id','client_secret','gwn_client_id','gwn_client_secret'] as $k) $exp[$k]=$cfg2[$k]??'';
                    return new \Symfony\Component\HttpFoundation\Response(
                    json_encode($exp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                    200,
                    [
                        'Content-Type'          => 'application/json; charset=utf-8',
                        'Content-Disposition'   => 'attachment; filename="gdms_config_entity' . $eid . '_' . date('Y-m-d') . '.json"',
                        'Cache-Control'         => 'no-cache, no-store',
                        'X-Gdms-New-Csrf-Token' => \Session::getNewCSRFToken(),
                    ]
                );
            }

            // ---- Config import -----
            if ($request->request->has('import_config')) {
                $upload2 = $request->files->get('config_json');
                $upload2_ok = $upload2 && $upload2->isValid() && $upload2->getPathname() !== '';
                $json_mime  = $upload2_ok ? $upload2->getMimeType() : false;
                $json_ok    = in_array($json_mime, ['application/json', 'text/plain', 'text/json'], true);
                if (!$upload2_ok || !$json_ok) {
                    \Session::addMessageAfterRedirect(__('No file received or upload error.', 'gdmsintegration'), false, ERROR);
                } else {
                    $data = json_decode(file_get_contents($upload2->getPathname()), true);
                    if (!is_array($data) || ($data['_plugin']??'') !== 'gdmsintegration') {
                        \Session::addMessageAfterRedirect(__('Invalid or unrecognised configuration file.', 'gdmsintegration'), false, ERROR);
                    } else {
                        $eid2 = (int)$request->request->get('entities_id', $data['entities_id'] ?? \Session::getActiveEntity());
                        $inp = ['entities_id'=>$eid2];
                        foreach (['refresh_interval','gdms_region','ip_version','debug_logging','chart_days','show_topology','ticket_requester_id','ticket_category_network_id','ticket_category_telephony_id','wan_debounce_seconds','wan_tickets_enabled','tickets_phone','tickets_router','tickets_switch','tickets_ap','tickets_pbx','max_xlsx_size_mb','history_retention_days'] as $k) {
                            if (array_key_exists($k,$data)) $inp[$k]=$data[$k];
                        }
                        if (!empty($data['username'])) $inp['username']=$data['username'];
                        if (!empty($data['_includes_credentials'])) {
                            foreach (['password','client_id','client_secret','gwn_client_id','gwn_client_secret'] as $k) {
                                if (array_key_exists($k,$data)) $inp[$k]=$data[$k];
                            }
                        }
                                    (new \GlpiPlugin\Gdmsintegration\Config())->saveConfig($inp);
                        \Session::addMessageAfterRedirect(__('Configuration imported. API credentials were not changed — re-enter them if needed.', 'gdmsintegration'), true, INFO);
                    }
                }
                return new \Symfony\Component\HttpFoundation\RedirectResponse($configUrl);
            }

            if ($request->request->has('save')) {
                // GLPI 11: CSRF validated automatically by Symfony CheckCsrfListener before reaching here.
                
                $config->saveConfig([
                    'entities_id'       => (int) ($request->request->get('entities_id', 0)),
                    'username'          => $request->request->get('username', ''),
                    'gdms_region'       => in_array(strtolower((string)($request->request->get('gdms_region', 'us'))), ['us','eu'], true) ? strtolower((string)$request->request->get('gdms_region')) : 'us',
                    'password'          => $request->request->get('password', ''),
                    'client_id'         => $request->request->get('client_id', ''),
                    'client_secret'     => $request->request->get('client_secret', ''),
                    'gwn_client_id'     => $request->request->get('gwn_client_id', ''),
                    'gwn_client_secret' => $request->request->get('gwn_client_secret', ''),
                    'refresh_interval'    => max(30, (int)($request->request->get('refresh_interval', 300))),
                    'ip_version'          => in_array($request->request->get('ip_version', ''), ['ipv4', 'ipv6'], true) ? $request->request->get('ip_version') : 'ipv4',
                    'debug_logging'       => $request->request->has('debug_logging') ? 1 : 0,
                    'chart_days'          => max(7, min(365, (int)($request->request->get('chart_days', 60)))),
                    'show_topology'       => $request->request->has('show_topology') ? 1 : 0,
                    'ticket_requester_id'  => (int)($request->request->get('ticket_requester_id', 0)),
                    'ticket_category_network_id' => (int)($request->request->get('ticket_category_network_id', 0)),
                    'ticket_category_telephony_id' => (int)($request->request->get('ticket_category_telephony_id', 0)),
                    'wan_debounce_seconds' => max(0, min(3600, (int)($request->request->get('wan_debounce_seconds', 300)))),
                    'wan_tickets_enabled'  => $request->request->has('wan_tickets_enabled') ? 1 : 0,
                    'max_xlsx_size_mb'     => max(1, min(50, (int)($request->request->get('max_xlsx_size_mb', 5)))),
                    'history_retention_days' => max(7, min(3650, (int)($request->request->get('history_retention_days', 90)))),
                    'tickets_phone'        => $request->request->has('tickets_phone')  ? 1 : 0,
                    'tickets_router'       => $request->request->has('tickets_router') ? 1 : 0,
                    'tickets_switch'       => $request->request->has('tickets_switch') ? 1 : 0,
                    'tickets_ap'           => $request->request->has('tickets_ap')     ? 1 : 0,
                    'tickets_pbx'          => $request->request->has('tickets_pbx')    ? 1 : 0,
                ]);

                // Real connection test after saving
                $entities_id_test = (int) ($request->request->get('entities_id', 0));
                $saved_config     = $config->getConfigByEntity($entities_id_test);
                $test             = \GlpiPlugin\Gdmsintegration\API::testConnections($saved_config);

                if ($test['gwn'] === true) {
                    \Session::addMessageAfterRedirect(
                        __('GWN Networking: connection successful.', 'gdmsintegration'), true, INFO
                    );
                } elseif ($test['gwn'] !== null) {
                    \Session::addMessageAfterRedirect(
                        __('GWN Networking:', 'gdmsintegration') . ' ' . $test['gwn'], false, ERROR
                    );
                }

                if ($test['gdms'] === true) {
                    \Session::addMessageAfterRedirect(
                        __('GDMS Unified Communications: connection successful.', 'gdmsintegration'), true, INFO
                    );
                } elseif ($test['gdms'] !== null) {
                    \Session::addMessageAfterRedirect(
                        __('GDMS Unified Communications:', 'gdmsintegration') . ' ' . $test['gdms'], false, ERROR
                    );
                }

                if ($test['gwn'] === null && $test['gdms'] === null) {
                    \Session::addMessageAfterRedirect(
                        __('Configuration saved. No credentials to test yet.', 'gdmsintegration'), true, WARNING
                    );
                } else {
                    \Session::addMessageAfterRedirect(
                        __('Configuration saved.', 'gdmsintegration'), true, INFO
                    );
                }

                // Clear cached debug flag so next request re-reads from DB
                unset($_SESSION['_gdms_debug']);
                return new \Symfony\Component\HttpFoundation\RedirectResponse($configUrl);
            }

            // Always clear debug session cache on config page load so displayed state is fresh from DB
            unset($_SESSION['_gdms_debug']);

            $has_gdms         = !empty($cur['client_id']) && !empty($cur['client_secret']);
            $refresh_interval = (int)($cur['refresh_interval'] ?? 300);
            $has_gwn  = !empty($cur['gwn_client_id']) && !empty($cur['gwn_client_secret']);
            $chart_days           = max(7, min(365, (int)($cur['chart_days'] ?? 60)));
            $show_topology        = (int)($cur['show_topology'] ?? 1);
            $ip_version           = in_array($cur['ip_version'] ?? '', ['ipv4', 'ipv6'], true) ? $cur['ip_version'] : 'ipv4';
            $ticket_requester_id  = (int)($cur['ticket_requester_id'] ?? 0);
            $ticket_category_network_id = \GlpiPlugin\Gdmsintegration\Config::validateTicketCategoryId((int)($cur['ticket_category_network_id'] ?? 0), $entities_id);
            $ticket_category_telephony_id = \GlpiPlugin\Gdmsintegration\Config::validateTicketCategoryId((int)($cur['ticket_category_telephony_id'] ?? 0), $entities_id);
            $ticket_category_options = \GlpiPlugin\Gdmsintegration\Config::getTicketCategoryOptions($entities_id);
            $wan_debounce_seconds = max(0, min(3600, (int)($cur['wan_debounce_seconds'] ?? 300)));
            $wan_tickets_enabled  = (int)($cur['wan_tickets_enabled'] ?? 1);
            $max_xlsx_size_mb     = max(1, min(50, (int)($cur['max_xlsx_size_mb'] ?? 5)));
            $history_retention_days = max(7, min(3650, (int)($cur['history_retention_days'] ?? 90)));
            $tickets_phone  = (int)($cur['tickets_phone']  ?? 1);
            $tickets_router = (int)($cur['tickets_router'] ?? 1);
            $tickets_switch = (int)($cur['tickets_switch'] ?? 1);
            $tickets_ap     = (int)($cur['tickets_ap']     ?? 1);
            $tickets_pbx    = (int)($cur['tickets_pbx']    ?? 1);

            $_plugin_web = ($CFG_GLPI['root_doc'] ?? '') . '/plugins/gdmsintegration';

            ob_start();

            \Html::header(
                __('GDMS Configuration', 'gdmsintegration'),
                '',
                'config',
                '\GlpiPlugin\Gdmsintegration\Config'
            );

            ob_start();
            \Entity::dropdown(['name' => 'entities_id', 'value' => $entities_id]);
            $entity_dropdown = ob_get_clean();

            $users_raw     = (new \User())->find(['is_active' => 1, 'is_deleted' => 0]);
            $users_options = [];
            foreach ($users_raw as $u) {
                $uid           = (int)$u['id'];
                $label         = trim(($u['firstname'] ?? '') . ' ' . ($u['realname'] ?? '')) ?: ($u['name'] ?? "User #{$uid}");
                $users_options[] = ['id' => $uid, 'label' => $label, 'selected' => $uid === $ticket_requester_id];
            }

            echo \GlpiPlugin\Gdmsintegration\Twig::get()->render('config_form.html.twig', [
                'cur'                  => $cur,
                'entities_id'          => $entities_id,
                'entity_dropdown'      => $entity_dropdown,
                'has_gdms'             => $has_gdms,
                'has_gwn'              => $has_gwn,
                'refresh_interval'     => $refresh_interval,
                'chart_days'           => $chart_days,
                'show_topology'        => $show_topology,
                'ip_version'           => $ip_version,
                'ticket_requester_id'  => $ticket_requester_id,
                'ticket_category_network_id' => $ticket_category_network_id,
                'ticket_category_telephony_id' => $ticket_category_telephony_id,
                'ticket_category_options' => $ticket_category_options,
                'wan_debounce_seconds' => $wan_debounce_seconds,
                'wan_tickets_enabled'  => $wan_tickets_enabled,
                'max_xlsx_size_mb'     => $max_xlsx_size_mb,
                'history_retention_days' => $history_retention_days,
                'tickets_phone'        => $tickets_phone,
                'tickets_router'       => $tickets_router,
                'tickets_switch'       => $tickets_switch,
                'tickets_ap'           => $tickets_ap,
                'tickets_pbx'          => $tickets_pbx,
                'users_options'        => $users_options,
                'dashboard_url'        => $_plugin_web . '/dashboard',
                'plugin_update'        => \GlpiPlugin\Gdmsintegration\Config::getPluginUpdateInfo(),
                'csrf_main'            => \Session::getNewCSRFToken(),
                'csrf_history'         => \Session::getNewCSRFToken(),
                'csrf_export'          => \Session::getNewCSRFToken(),
            ]);

            \Html::footer();
                    return new \Symfony\Component\HttpFoundation\Response(ob_get_clean());
    }
}
