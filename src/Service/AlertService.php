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

        $device_obj = new \GlpiPlugin\Gdmsintegration\Device();
        $states = $device_obj->find(['network_id' => ['>', 0]] + AccessService::entityCriteria($device_obj->getTable(), $entities_id));
        $net_ids = array_unique(array_map('intval', array_column($states, 'network_id')));
        if ($filter_network) $net_ids = array_filter($net_ids, static fn($id) => (int)$id === $filter_network);
        $all = []; $complete = true;
        $completeByNetwork = [];
        $alertsByNetwork = \GlpiPlugin\Gdmsintegration\API::gwnGetAlertsBatch(
            $config,
            array_map('intval', $net_ids),
            30,
            $completeByNetwork
        );
        foreach ($net_ids as $nid) {
            $networkId = (int) $nid;
            $complete = $complete && ($completeByNetwork[$networkId] ?? false);
            foreach (($alertsByNetwork[$networkId] ?? []) as $a) {
                $a['_network_id'] = $networkId;
                $all[] = $a;
            }
        }
        usort($all, static fn($a,$b) => ((int)($b['createTime'] ?? 0)) <=> ((int)($a['createTime'] ?? 0)));
        return new JsonResponse(array_slice($all, 0, 100), 200, ['X-GDMS-Result-Complete' => $complete ? '1' : '0']);
    }
}
