<?php
/**
 * GDMS Integration — PluginGdmsintegrationDevice
 * Tracks last-known status per MAC to detect state transitions.
 */
class PluginGdmsintegrationDevice extends CommonDBTM {

    static $rightname = 'config';

    public static function getTypeName($nb = 0): string {
        return 'GDMS Device State';
    }

    public function getState(string $mac): ?string {
        $rows = $this->find(['mac' => $mac]);
        if (empty($rows)) {
            return null;
        }
        return reset($rows)['status'] ?? null;
    }

    public function saveState(string $mac, string $status): bool {
        $rows = $this->find(['mac' => $mac]);
        if (!empty($rows)) {
            return (bool) $this->update([
                'id'     => array_key_first($rows),
                'status' => $status,
            ]);
        }
        return (bool) $this->add([
            'mac'    => $mac,
            'status' => $status,
        ]);
    }
}
