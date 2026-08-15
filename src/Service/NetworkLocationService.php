<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class NetworkLocationService
{
    public static function save(Request $request): JsonResponse
    {
        if (!$request->isMethod('POST')) return new JsonResponse(['error'=>'POST required'], 405);
        if (!\Session::haveRight('config', UPDATE)) return new JsonResponse(['error'=>'Access denied'], 403);
        $e=AccessService::entityId($request); AccessService::requireEntityAccess($e);
        $nid=(int)$request->request->get('network_id',0); $lid=(int)$request->request->get('locations_id',0); $name=trim((string)$request->request->get('network_name',''));
        if(!$nid) return new JsonResponse(['error'=>'Missing network_id']);
        global $DB;
        $DB->updateOrInsert('glpi_plugin_gdmsintegration_network_locations',['entities_id'=>$e,'network_id'=>$nid,'network_name'=>$name,'locations_id'=>$lid,'date_mod'=>gmdate('Y-m-d H:i:s')],['entities_id'=>$e,'network_id'=>$nid]);
        return new JsonResponse(['ok'=>true]);
    }
}
