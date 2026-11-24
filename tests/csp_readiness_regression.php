<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(!is_file($root . '/src/Twig.php'), 'The plugin-specific standalone Twig environment must be removed.');

$dashboardService = file_get_contents($root . '/src/Service/DashboardService.php');
$configService = file_get_contents($root . '/src/Service/ConfigService.php');
$dashboardTemplate = file_get_contents($root . '/templates/dashboard.html.twig');
$configTemplate = file_get_contents($root . '/templates/config_form.html.twig');
$assert(is_string($dashboardService) && str_contains($dashboardService, "TemplateRenderer::getInstance()->render('@gdmsintegration/dashboard.html.twig'"), 'Dashboard must render through GLPI TemplateRenderer namespace.');
$assert(is_string($configService) && str_contains($configService, "TemplateRenderer::getInstance()->render('@gdmsintegration/config_form.html.twig'"), 'Configuration must render through GLPI TemplateRenderer namespace.');
$assert(!str_contains((string) $dashboardService, 'Twig::get()'), 'Dashboard must not use the standalone Twig environment.');
$assert(!str_contains((string) $configService, 'Twig::get()'), 'Configuration must not use the standalone Twig environment.');

$dashboardScripts = preg_match_all('/<script\b/i', (string) $dashboardTemplate);
$configScripts = preg_match_all('/<script\b/i', (string) $configTemplate);
$assert($dashboardScripts === 5, 'Dashboard script tags should remain under core TemplateRenderer control.');
$assert($configScripts === 1, 'Configuration script tag should remain under core TemplateRenderer control.');

foreach ([$dashboardTemplate, $configTemplate] as $template) {
    $markupOnly = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', (string) $template) ?? (string) $template;
    $assert(!preg_match('/<[a-z][a-z0-9:-]*\b[^>]*\bon[a-z]+\s*=/i', $markupOnly), 'Templates must not contain inline HTML event-handler attributes.');
    $assert(!preg_match('/javascript\s*:/i', $markupOnly), 'Templates must not contain javascript: URLs.');
}

$setup = file_get_contents($root . '/setup.php');
$initStart = strpos((string) $setup, 'function plugin_init_gdmsintegration');
$initEnd = strpos((string) $setup, "\n}", $initStart === false ? 0 : $initStart);
$initBody = $initStart !== false && $initEnd !== false ? substr((string) $setup, $initStart, $initEnd - $initStart) : '';
$assert(!str_contains($initBody, 'plugin_gdmsintegration_ensure_cron_task'), 'Plugin initialization must not query/repair CronTask on each request.');

fwrite(STDOUT, "PASS: 11 CSP and initialization regression assertions.\n");
