<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class AlertService
{
    public static function list(Request $request): JsonResponse
    {
        if (!\Session::haveRight('config', READ) && !\Session::haveRight('networking', READ)) {
            return self::jsonNoCache(['error' => 'Access denied'], 403);
        }
        $entities_id = AccessService::entityId($request);
        AccessService::requireEntityAccess($entities_id);
        $filter_network = (int)$request->query->get('network_id', 0);
        $config = (new \GlpiPlugin\Gdmsintegration\Config())->getConfigByEntity($entities_id);
        if (empty($config['gwn_client_id'])) return self::jsonNoCache([]);

        $device_obj = new \GlpiPlugin\Gdmsintegration\Device();
        $states = $device_obj->find(['network_id' => ['>', 0]] + AccessService::entityCriteria($device_obj->getTable(), $entities_id));
        $net_ids = array_unique(array_map('intval', array_column($states, 'network_id')));
        if ($filter_network) $net_ids = array_filter($net_ids, static fn($id) => (int)$id === $filter_network);
        $all = [];
        $receivedCount = 0;
        $hiddenByStateCount = 0;
        $complete = true;
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
                if (!is_array($a)) {
                    continue;
                }
                $receivedCount++;
                if (!self::isCloudAlertVisible($a)) {
                    $hiddenByStateCount++;
                    continue;
                }
                $a['_network_id'] = $networkId;
                $all[] = $a;
            }
        }
        \GlpiPlugin\Gdmsintegration\Utils::debug(sprintf(
            'GWN dashboard alert refresh: networks=%d received=%d hidden_by_explicit_state=%d returned=%d',
            count($net_ids),
            $receivedCount,
            $hiddenByStateCount,
            count($all)
        ));
        usort($all, static fn($a,$b) => ((int)($b['createTime'] ?? 0)) <=> ((int)($a['createTime'] ?? 0)));
        return self::jsonNoCache(
            array_slice($all, 0, 100),
            200,
            ['X-GDMS-Result-Complete' => $complete ? '1' : '0']
        );
    }

    /**
     * Do not let a browser or shared reverse proxy reuse a previous alert list.
     * The cloud API does not expose a documented read/delete operation; when it
     * does return an explicit state flag, honor it without guessing from age or
     * numeric status codes.
     */
    private static function jsonNoCache($data, int $status = 200, array $headers = []): JsonResponse
    {
        $noCacheHeaders = [
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Thu, 01 Jan 1970 00:00:00 GMT',
            'Vary' => 'Cookie',
        ];
        return new JsonResponse($data, $status, array_merge($noCacheHeaders, $headers));
    }

    /**
     * Hide alerts only when the API explicitly marks them as read, deleted,
     * cleared, closed, or inactive. Never infer that an alert is inactive just
     * because its timestamp is old: the documented list endpoint is a log and
     * the current API response does not consistently include these state fields.
     */
    private static function isCloudAlertVisible(array $alert): bool
    {
        $isTrue = static function ($value): bool {
            if (is_bool($value)) {
                return $value;
            }
            if (is_int($value) || is_float($value)) {
                return (int) $value === 1;
            }
            if (is_string($value)) {
                return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'read', 'deleted', 'inactive', 'closed', 'cleared', 'resolved', 'recovered'], true);
            }
            return false;
        };
        $isFalse = static function ($value): bool {
            if (is_bool($value)) {
                return !$value;
            }
            if (is_int($value) || is_float($value)) {
                return (int) $value === 0;
            }
            if (is_string($value)) {
                return in_array(strtolower(trim($value)), ['0', 'false', 'no', 'n', 'unread', 'active'], true);
            }
            return false;
        };

        foreach (['isRead', 'is_read', 'read', 'readFlag', 'read_flag', 'isDeleted', 'is_deleted', 'deleted', 'deleteFlag', 'delete_flag'] as $field) {
            if (array_key_exists($field, $alert) && $isTrue($alert[$field])) {
                return false;
            }
        }
        foreach (['isUnread', 'is_unread', 'unread'] as $field) {
            if (array_key_exists($field, $alert) && $isFalse($alert[$field])) {
                return false;
            }
        }
        foreach (['readStatus', 'read_status', 'readState', 'read_state'] as $field) {
            if (!isset($alert[$field]) || !is_string($alert[$field])) {
                continue;
            }
            if (in_array(strtolower(trim($alert[$field])), ['read', 'readed', 'already_read'], true)) {
                return false;
            }
        }
        foreach (['isActive', 'is_active', 'active'] as $field) {
            if (array_key_exists($field, $alert) && $isFalse($alert[$field])) {
                return false;
            }
        }
        foreach (['status', 'alertStatus', 'alert_status', 'state', 'alertState', 'alert_state'] as $field) {
            if (!isset($alert[$field]) || !is_string($alert[$field])) {
                continue;
            }
            if (in_array(strtolower(trim($alert[$field])), ['read', 'deleted', 'cleared', 'closed', 'resolved', 'recovered', 'inactive', 'acknowledged'], true)) {
                return false;
            }
        }

        return true;
    }
}
