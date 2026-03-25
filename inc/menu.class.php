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
            $menu['page']  = Plugin::getWebDir('gdmsintegration', true) . '/front/dashboard.php';
            $menu['icon']  = 'fas fa-network-wired';

            $menu['options']['dashboard']['title'] = __('GDMS Dashboard', 'gdmsintegration');
            $menu['options']['dashboard']['page']  = Plugin::getWebDir('gdmsintegration', true) . '/front/dashboard.php';
            $menu['options']['dashboard']['icon']  = 'fas fa-tachometer-alt';

            $menu['options']['config']['title'] = __('GDMS Configuration', 'gdmsintegration');
            $menu['options']['config']['page']  = Plugin::getWebDir('gdmsintegration', true) . '/front/config.form.php';
            $menu['options']['config']['icon']  = 'fas fa-cog';
        }

        return $menu;
    }

    public static function displayMenuContent(): bool {
        return true;
    }
}
