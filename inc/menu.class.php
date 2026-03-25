<?php
/**
 * GDMS Integration — PluginGdmsintegrationMenu
 */
class PluginGdmsintegrationMenu extends CommonGLPI {

    static $rightname = 'config';

    public static function getTypeName($nb = 0): string {
        return __('GDMS Integration', 'gdmsintegration');
    }

    public static function getMenuContent(): array {
        $menu = [];

        if (Session::haveRight('config', READ)) {
            $menu['title'] = __('GDMS Integration', 'gdmsintegration');
            $menu['page']  = '/plugins/gdmsintegration/front/dashboard.php';
            $menu['icon']  = 'fas fa-network-wired';

            $menu['options']['dashboard']['title'] = __('GDMS Dashboard', 'gdmsintegration');
            $menu['options']['dashboard']['page']  = '/plugins/gdmsintegration/front/dashboard.php';
            $menu['options']['dashboard']['icon']  = 'fas fa-tachometer-alt';

            $menu['options']['config']['title'] = __('GDMS Configuration', 'gdmsintegration');
            $menu['options']['config']['page']  = '/plugins/gdmsintegration/front/config.form.php';
            $menu['options']['config']['icon']  = 'fas fa-cog';
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
