<?php

namespace GlpiPlugin\Gdmsintegration;
/**
 * GDMS Integration — compatibility shim for $rightname type declaration.
 * GLPI 12 added `public static string $rightname` to CommonGLPI.
 * PHP enforces type invariance: child must match parent's type declaration.
 * This conditional intermediate class resolves the mismatch on GLPI 11.
 */
if ((int) GLPI_VERSION >= 12) {
    abstract class BaseGLPI extends \CommonGLPI {
        public static string $rightname = 'config';
    }
} else {
    abstract class BaseGLPI extends \CommonGLPI {
        public static $rightname = 'config';
    }
}
