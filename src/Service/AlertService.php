<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AlertService
{
    public static function list(Request $request): JsonResponse
    {
        if (!\Session::haveRight('config', READ) && !\Session::haveRight('networking', READ)) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }
        $entities_id = AccessService::entityId($request);
        AccessService::requireEntityAccess($entities_id);
        $filter_network = (int)$request->query->get('network_id', 0);
        $config = (new \GlpiPlugin\Gdmsintegration\Config())->getConfigByEntity($entities_id);
        if (empty($config['gwn_client_id'])) return new JsonResponse([]);

        $states = (new \GlpiPlugin\Gdmsintegration\Device())->find(['network_id' => ['>', 0]]);
        $net_ids = array_unique(array_map('intval', array_column($states, 'network_id')));
        if ($filter_network) $net_ids = array_filter($net_ids, static fn($id) => (int)$id === $filter_network);
        $all = []; $complete = true;
        foreach ($net_ids as $nid) {
            $network_complete = false;
            $alerts = \GlpiPlugin\Gdmsintegration\API::gwnGetAlerts($config, (int)$nid, 30, $network_complete);
            $complete = $complete && $network_complete;
            foreach ($alerts as $a) { $a['_network_id'] = (int)$nid; $all[] = $a; }
        }
        usort($all, static fn($a,$b) => ((int)($b['createTime'] ?? 0)) <=> ((int)($a['createTime'] ?? 0)));
        return new JsonResponse(array_slice($all, 0, 100), 200, ['X-GDMS-Result-Complete' => $complete ? '1' : '0']);
    }
}
