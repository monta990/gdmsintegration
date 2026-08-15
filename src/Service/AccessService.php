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
}
