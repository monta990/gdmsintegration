<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use GlpiPlugin\Gdmsintegration\API;
use GlpiPlugin\Gdmsintegration\Config;
use GlpiPlugin\Gdmsintegration\Device;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class PortService
{
    public static function status(Request $request): JsonResponse
    {
        global $DB;

        if (!\Session::haveRight('config', READ) && !\Session::haveRight('networking', READ)) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }

        $entities_id = AccessService::entityId($request);
        AccessService::requireEntityAccess($entities_id);
        if ((string) $request->query->get('action', 'status') !== 'status') {
            return new JsonResponse(['error' => 'Unknown action'], 400);
        }

        $config = (new Config())->getConfigByEntity($entities_id);
        if (empty($config['gwn_client_id']) || empty($config['gwn_client_secret'])) {
            return new JsonResponse([]);
        }

        $device_obj = new Device();
        $all = $device_obj->find(AccessService::entityCriteria($device_obj->getTable(), $entities_id));
        $result = [];
        $missing = [];

        foreach ($all as $row) {
            $mac = strtolower(trim((string) ($row['mac'] ?? '')));
            if ($mac === '') {
                continue;
            }

            $stored = (string) ($row['wan_ports_json'] ?? '');
            if ($stored !== '') {
                // Both a populated JSON array and the explicit negative-cache value
                // "[]" count as a completed lookup. This is important for APs/phones,
                // whose models do not expose router/switch port information.
                $result[$mac] = is_array(json_decode($stored, true)) ? json_decode($stored, true) : [];
                continue;
            }

            if (($row['status'] ?? '') !== 'online') {
                continue;
            }

            $model = trim((string) ($row['model'] ?? ''));
            $kind = null;
            if (preg_match('/^GWN700[123]/i', $model)) {
                $kind = 'router';
            } elseif (preg_match('/^GWN78|^GSS/i', $model)) {
                $kind = 'switch';
            }

            // Do not call device/info for APs, phones or PBX devices. That endpoint
            // is only useful for GWN700x routers and used to be requested for every
            // other device with an empty cache, slowing down each dashboard load.
            if ($kind === null) {
                $result[$mac] = [];
                continue;
            }

            $network_id = (int) ($row['network_id'] ?? 0);
            if ($network_id <= 0) {
                $result[$mac] = [];
                self::storePortSnapshot((int) ($row['id'] ?? 0), $entities_id, []);
                continue;
            }

            $key = strtolower((string) preg_replace('/[^0-9a-f]/i', '', $mac));
            $missing[$key] = [
                'id'        => (int) ($row['id'] ?? 0),
                'mac'       => $mac,
                'networkId' => $network_id,
                'type'      => $kind,
            ];
        }

        if ($missing === []) {
            return new JsonResponse($result);
        }

        $batchRequests = [];
        foreach ($missing as $item) {
            $batchRequests[] = [
                'mac'       => $item['mac'],
                'networkId' => $item['networkId'],
                'type'      => $item['type'],
            ];
        }
        $apiResults = API::gwnGetPortInfoBatch($config, $batchRequests);

        foreach ($missing as $key => $item) {
            $entry = $apiResults[$key] ?? null;
            $ports = [];
            if (is_array($entry) && !empty($entry['success'])) {
                if ($item['type'] === 'switch') {
                    $rawPorts = $entry['data'] ?? [];
                    if (is_array($rawPorts)) {
                        foreach ($rawPorts as $port) {
                            if (!is_array($port)) {
                                continue;
                            }
                            $ports[] = [
                                'id'         => $port['portId'] ?? $port['silkScreenPort'] ?? '',
                                'name'       => $port['portName'] ?? '',
                                'silk'       => $port['silkScreenPort'] ?? '',
                                'role'       => 0,
                                'link'       => (int) ($port['linkStatus'] ?? 0),
                                'speed'      => (int) ($port['portSpeed'] ?? 0),
                                'type'       => ($port['type'] ?? 0) == 1 ? 'SFP' : 'GE',
                                'customName' => $port['portCustomName'] ?? '',
                                'desc'       => $port['portDesc'] ?? '',
                                'txBytes'    => (int) ($port['aggregate']['txBytes'] ?? 0),
                                'rxBytes'    => (int) ($port['aggregate']['rxBytes'] ?? 0),
                                'vlan'       => (int) ($port['vlan'] ?? 0),
                            ];
                        }
                    }
                    usort($ports, static fn(array $a, array $b): int => strcmp((string) ($a['silk'] ?: $a['id']), (string) ($b['silk'] ?: $b['id'])));
                } else {
                    $portData = $entry['data']['portInfo'] ?? [];
                    if (is_array($portData)) {
                        foreach ($portData as $port) {
                            if (!is_array($port)) {
                                continue;
                            }
                            $role = (int) ($port['role'] ?? 0);
                            $ipv4 = is_array($port['ipv4Info'] ?? null) ? $port['ipv4Info'] : [];
                            $isWan = $role === 1
                                || trim((string) ($port['wanName'] ?? '')) !== ''
                                || array_key_exists('wanId', $port);
                            $ports[] = [
                                'id'              => $port['portId'] ?? $port['silkScreenPort'] ?? '',
                                'name'            => $port['portName'] ?? '',
                                'silk'            => $port['silkScreenPort'] ?? '',
                                'silkNum'         => $port['silkNum'] ?? '',
                                'role'            => $isWan ? 1 : 0,
                                'link'            => (int) ($port['linkStatus'] ?? 0),
                                'speed'           => (int) ($port['portSpeed'] ?? 0),
                                'type'            => ($port['type'] ?? 0) == 1 ? 'SFP' : 'GE',
                                'desc'            => $port['portDesc'] ?? '',
                                'customName'      => $port['portCustomName'] ?? '',
                                'wanName'         => $port['wanName'] ?? '',
                                'connectDuration' => (int) ($port['connectDuration'] ?? 0),
                                'ip'              => $ipv4['ip4Address'] ?? '',
                                'connectStatus'   => isset($ipv4['connectStatus']) ? (int) $ipv4['connectStatus'] : -1,
                                'wanType'         => isset($ipv4['type']) ? (int) $ipv4['type'] : -1,
                                'gateway'         => $ipv4['gateway'] ?? '',
                                'txBytes'         => (int) ($port['aggregate']['txBytes'] ?? 0),
                                'rxBytes'         => (int) ($port['aggregate']['rxBytes'] ?? 0),
                            ];
                        }
                    }
                    usort($ports, static fn(array $a, array $b): int => (($b['role'] - $a['role']) ?: strcmp((string) ($a['silk'] ?: $a['id']), (string) ($b['silk'] ?: $b['id']))));
                }
            }

            // Cache both successful snapshots and empty/error results. A failed
            // fetch is retried by the next real sync (cron or Sync now), rather than
            // on every dashboard page load. Existing snapshots were handled above
            // and are never overwritten by a transient failure.
            $result[$item['mac']] = $ports;
            self::storePortSnapshot($item['id'], $entities_id, $ports);
        }

        return new JsonResponse($result);
    }

    private static function storePortSnapshot(int $id, int $entities_id, array $ports): void
    {
        global $DB;
        if ($id <= 0) {
            return;
        }
        $json = json_encode($ports);
        if (!is_string($json)) {
            return;
        }
        $DB->update(Device::getTable(), ['wan_ports_json' => $json], [
            'id'          => $id,
            'entities_id' => $entities_id,
        ]);
    }
}
