<?php
/**
 * GDMS Integration — compatibility shim for $rightname type declaration.
 * GLPI 12 added `public static string $rightname` to CommonGLPI (inherited by CommonDBTM).
 * PHP enforces type invariance: child must match parent's type declaration.
 * This conditional intermediate class resolves the mismatch on GLPI 11.
 */
if ((int) GLPI_VERSION >= 12) {
    abstract class PluginGdmsintegrationBaseTM extends CommonDBTM {
        public static string $rightname = 'config';
    }
} else {
    abstract class PluginGdmsintegrationBaseTM extends CommonDBTM {
        public static $rightname = 'config';
    }
}
