<?php

namespace GlpiPlugin\Gdmsintegration;
/**
 * GDMS Integration — compatibility shim for $rightname type declaration.
 * GLPI 12 added `public static string $rightname` to \CommonGLPI (inherited by CommonDBTM).
 * PHP enforces type invariance: child must match parent's type declaration.
 * This conditional intermediate class resolves the mismatch on GLPI 11.
 */
if ((int) GLPI_VERSION >= 12) {
    abstract class BaseTM extends \CommonDBTM {
        public static string $rightname = 'config';
        /**
         * Keep sensitive plugin configuration fields out of GLPI REST responses.
         * GLPI 12 declares this inherited property as `array`; use the same type here.
         */
        public static array $undisclosedFields = [
            'password',
            'client_secret',
            'gwn_client_secret',
            'gdms_access_token',
            'gdms_refresh_token',
            'gwn_access_token',
        ];
    }
} else {
    abstract class BaseTM extends \CommonDBTM {
        public static $rightname = 'config';
        /**
         * GLPI 11 leaves this inherited property untyped. Keep it untyped here
         * to preserve PHP's property invariance across both supported versions.
         */
        public static $undisclosedFields = [
            'password',
            'client_secret',
            'gwn_client_secret',
            'gdms_access_token',
            'gdms_refresh_token',
            'gwn_access_token',
        ];
    }
}
