<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use GlpiPlugin\Gdmsintegration\Sync;
use GlpiPlugin\Gdmsintegration\Utils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class SyncService
{
    public static function run(Request $request): JsonResponse
    {
        if (!$request->isMethod('POST')) {
            return new JsonResponse(['success' => false,'error' => 'POST required'], 405);
        }
        if (!\Session::haveRight('config', UPDATE)) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }
        $entities_id = AccessService::entityId($request);
        AccessService::requireEntityAccess($entities_id);
        $source = (string) $request->query->get('source', 'button');
        Utils::log("Controller sync called — entity={$entities_id}, source={$source}");
        try {
            $partial = false;
            $skipped = false;
            $lockFailed = false;
            $processed = Sync::syncEntity($entities_id, $source, $partial, $skipped, $lockFailed);
            if ($lockFailed) {
                return new JsonResponse([
                    'success' => false,
                    'completed' => false,
                    'busy' => false,
                    'error' => 'Synchronization could not acquire its database lock. Check the GLPI database connection and retry.',
                    'processed' => 0,
                    'mode' => 'sync',
                ], 503);
            }
            if ($skipped) {
                return new JsonResponse([
                    'success' => true,
                    'completed' => false,
                    'busy' => true,
                    'processed' => 0,
                    'mode' => 'sync',
                ]);
            }
            return new JsonResponse([
                'success' => true,
                'completed' => true,
                'partial' => $partial,
                'processed' => $processed,
                'mode' => 'sync',
            ]);
        } catch (\Throwable $e) {
            Utils::log('Controller sync error: ' . $e->getMessage());
            return new JsonResponse(['success' => false,'error' => $e->getMessage()], 500);
        }
    }
}
