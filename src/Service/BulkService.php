<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class BulkService
{
    public static function reboot(Request $request): JsonResponse
    {
        if (!$request->isMethod('POST')) return new JsonResponse(['error' => 'POST required'], 405);
        if (!\Session::haveRight('config', UPDATE)) return new JsonResponse(['error' => 'Access denied'], 403);
        $e = AccessService::entityId($request); AccessService::requireEntityAccess($e);
        $action = (string)$request->request->get('action', '');
        $rawMacs = $request->request->get('macs');
        if (is_string($rawMacs)) {
            $decodedMacs = json_decode($rawMacs, true);
            $rawMacs = is_array($decodedMacs) ? $decodedMacs : [$rawMacs];
        }
        $macs = array_values(array_unique(array_filter(array_map(static fn($m) => strtolower(trim((string)$m)), (array)$rawMacs))));
        if (!$macs || count($macs) > 50) return new JsonResponse(['error' => 'Select 1 to 50 devices']);
        if ($action !== 'reboot_gdms') return new JsonResponse(['error' => 'Unsupported bulk action']);
        $config = \GlpiPlugin\Gdmsintegration\Config::getConfigByEntity($e); $ok=0; $failed=[]; global $DB;
        foreach ($macs as $mac) {
            $r=\GlpiPlugin\Gdmsintegration\API::gdmsCreateRebootTask($config,$mac); $success=!empty($r['ok']); $success?$ok++:$failed[]=$mac;
            try { $DB->insert('glpi_plugin_gdmsintegration_action_log',['entities_id'=>$e,'users_id'=>(int)\Session::getLoginUserID(),'action'=>'bulk_reboot_gdms','target_mac'=>$mac,'details'=>json_encode($r),'success'=>$success?1:0,'date'=>gmdate('Y-m-d H:i:s')]); } catch (\Throwable $x) { }
        }
        return new JsonResponse(['ok'=>$ok,'failed'=>$failed]);
    }
}
