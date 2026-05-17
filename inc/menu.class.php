<?php
/**
 * GDMS Integration — PluginGdmsintegrationMenu
 */
class PluginGdmsintegrationMenu extends PluginGdmsintegrationBaseGLPI {

    public static function getTypeName($nb = 0): string {
        return __('GDMS Integration', 'gdmsintegration');
    }

    public static function getMenuContent(): array {
        $menu = [];

        if (Session::haveRight('config', READ)) {
            global $CFG_GLPI;
            $_web = ($CFG_GLPI['root_doc'] ?? '') . substr(dirname(__DIR__), strlen(GLPI_ROOT));

            $menu['title'] = __('GDMS Integration', 'gdmsintegration');
            $menu['page']  = $_web . '/front/dashboard.php';
            $menu['icon']  = 'ti ti-network';

            $menu['options']['dashboard']['title'] = __('GDMS Dashboard', 'gdmsintegration');
            $menu['options']['dashboard']['page']  = $_web . '/front/dashboard.php';
            $menu['options']['dashboard']['icon']  = 'ti ti-dashboard';

            $menu['options']['config']['title'] = __('GDMS Configuration', 'gdmsintegration');
            $menu['options']['config']['page']  = $_web . '/front/config.form.php';
            $menu['options']['config']['icon']  = 'ti ti-settings';
        }

        return $menu;
    }

    /**
     * Tell GLPI which top-level menu to place this plugin under.
     * 'tools' = the existing Herramientas / Tools menu.
     */
    public static function getMenuName(): string {
        return __('GDMS Integration', 'gdmsintegration');
    }

    public static function displayMenuContent(): bool {
        return true;
    }
}
