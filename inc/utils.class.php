<?php
/**
 * GDMS Integration — PluginGdmsintegrationUtils
 */
class PluginGdmsintegrationUtils {

    public static function encrypt(string $value): string {
        return Toolbox::encrypt($value);
    }

    public static function decrypt(string $value): string {
        return Toolbox::decrypt($value);
    }

    public static function log(string $message): void {
        Toolbox::logInFile('gdmsintegration', $message . PHP_EOL);
    }
}
