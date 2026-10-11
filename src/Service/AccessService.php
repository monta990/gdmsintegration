<?php

namespace GlpiPlugin\Gdmsintegration\Service;

use Symfony\Component\HttpFoundation\Request;
use Glpi\Exception\Http\AccessDeniedHttpException;

final class AccessService
{
    public static function entityId(Request $request): int
    {
        $entities_id = $request->query->get('entities_id');
        if ($entities_id === null) {
            $entities_id = $request->request->get('entities_id');
        }
        return $entities_id !== null ? (int) $entities_id : (int) \Session::getActiveEntity();
    }

    public static function requireEntityAccess(int $entities_id): void
    {
        if (!\Session::haveAccessToEntity($entities_id)) {
            throw new AccessDeniedHttpException();
        }
    }

    /**
     * Return entity criteria suitable for both native GLPI tables and plugin-owned tables.
     *
     * Native GLPI item tables generally expose an `is_recursive` column, which allows
     * getEntitiesRestrictCriteria() to decide whether descendants are visible. Plugin
     * state/history tables do not have that column, so passing them to the GLPI helper
     * with recursion enabled produces invalid SQL (`table.is_recursive`).
     *
     * For tables without `is_recursive`, use the entities currently active in the
     * session. This preserves GLPI recursive-entity behavior because glpiactiveentities
     * already contains the selected entity and the descendants granted by the profile.
     */
    public static function entityCriteria(string $table, int $entities_id, string $field = 'entities_id'): array
    {
        global $DB;

        // Native GLPI entity-aware tables can use the standard recursive restriction.
        if ($DB->fieldExists($table, 'is_recursive')) {
            return \getEntitiesRestrictCriteria($table, $field, $entities_id, true);
        }

        // Plugin-owned tables without `is_recursive` must be constrained by the
        // entity IDs already granted to the current session. Include the requested
        // entity explicitly, then add only descendants present in the active list.
        $allowed_entities = [$entities_id];
        $active_entities = \Session::getActiveEntities();

        if ($active_entities !== []) {
            $descendants = \getSonsOf('glpi_entities', $entities_id);
            $allowed_entities = array_values(array_unique(array_merge(
                [$entities_id],
                array_intersect($descendants, $active_entities)
            )));
        }

        return \getEntitiesRestrictCriteria($table, $field, $allowed_entities, false);
    }
}
