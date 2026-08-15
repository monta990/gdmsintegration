<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SyncService
{
    public static function run(Request $request): JsonResponse
    {
        if (!$request->isMethod('POST')) return new JsonResponse(['success'=>false,'error'=>'POST required'],405);
        if (!\Session::haveRight('config', UPDATE)) return new JsonResponse(['error'=>'Access denied'],403);
        $entities_id=AccessService::entityId($request); AccessService::requireEntityAccess($entities_id);
        $source=(string)$request->query->get('source','button');
        \GlpiPlugin\Gdmsintegration\Utils::log("Controller sync called — entity={$entities_id}, source={$source}");
        try{$processed=\GlpiPlugin\Gdmsintegration\Sync::syncEntity($entities_id,$source);return new JsonResponse(['success'=>true,'processed'=>$processed,'mode'=>'sync']);}catch(\Throwable $e){\GlpiPlugin\Gdmsintegration\Utils::log('Controller sync error: '.$e->getMessage());return new JsonResponse(['success'=>false,'error'=>$e->getMessage()],500);}
    }
}
