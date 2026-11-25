<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$autoloadProvided = isset($argv[1]) && is_file($argv[1]);
if ($autoloadProvided) {
    require_once $argv[1];
} elseif (getenv('GDMS_REQUIRE_GLPI_RENDERER') === '1') {
    throw new RuntimeException('GLPI Composer autoload is required for the TemplateRenderer integration test.');
}

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

$unconfiguredStart = strpos((string) $dashboardService, 'if (!$is_configured) {');
$configuredLoadStart = strpos((string) $dashboardService, '// Load GDMS-managed devices', $unconfiguredStart === false ? 0 : $unconfiguredStart);
$unconfiguredBranch = $unconfiguredStart !== false && $configuredLoadStart !== false
    ? substr((string) $dashboardService, $unconfiguredStart, $configuredLoadStart - $unconfiguredStart)
    : '';
$assert($unconfiguredBranch !== '', 'Dashboard must retain an early return for an unconfigured GDMS account.');
$assert(!str_contains($unconfiguredBranch, '$js_json'), 'Unconfigured dashboard rendering must not prepare unused JavaScript JSON.');
$assert(!str_contains($unconfiguredBranch, '$nodes'), 'Unconfigured dashboard rendering must not access device nodes before they are loaded.');
$assert(!str_contains($unconfiguredBranch, '$edges'), 'Unconfigured dashboard rendering must not access topology edges before they are loaded.');
$assert(
    str_contains($unconfiguredBranch, "'is_configured' => false")
        && str_contains($unconfiguredBranch, "'config_url'"),
    'Unconfigured dashboard rendering must preserve the setup-link context.',
);
$assert(
    str_contains((string) $dashboardTemplate, '{% if not is_configured %}')
        && str_contains((string) $dashboardTemplate, 'href="{{ config_url }}"')
        && str_contains((string) $dashboardTemplate, 'Set up GDMS'),
    'The unconfigured dashboard template must continue to display its setup link.',
);
$assert(!str_contains((string) $dashboardTemplate, 'sprintf('), 'Dashboard must not call the removed plugin-only sprintf Twig function.');
$assert(substr_count((string) $dashboardTemplate, '|format(chart_days)') === 2, 'Both dashboard chart labels must use Twig format filter.');

if ($autoloadProvided) {
    $assert(
        class_exists(\Glpi\Application\View\TemplateRenderer::class)
            && class_exists(\Twig\Environment::class)
            && class_exists(\Twig\Loader\FilesystemLoader::class),
        'GLPI autoload must expose the core TemplateRenderer and Twig runtime classes.',
    );

    // Exercise the real GLPI TemplateRenderer class and its FilesystemLoader
    // namespace. The container bridge avoids booting GLPI's database-backed app kernel.
    $loader = new \Twig\Loader\FilesystemLoader($root . '/templates');
    $loader->addPath($root . '/templates', 'gdmsintegration');
    $twig = new \Twig\Environment($loader, [
        'autoescape' => 'html',
        'strict_variables' => false,
        'cache' => false,
    ]);
    $twig->addFunction(new \Twig\TwigFunction(
        '__',
        static fn(string $message, ?string $domain = null): string => $message,
    ));

    $_SESSION['glpi_use_mode'] = 'normal';
    $renderer = new \Glpi\Application\View\TemplateRenderer($twig);
    $container = new class($renderer) {
        public function __construct(private object $renderer) {}

        public function get(string $serviceId): object
        {
            if ($serviceId !== \Glpi\Application\View\TemplateRenderer::class) {
                throw new RuntimeException('Unexpected GLPI service requested by TemplateRenderer.');
            }

            return $this->renderer;
        }
    };
    $GLOBALS['kernel'] = new class($container) {
        public function __construct(private object $container) {}

        public function getContainer(): object
        {
            return $this->container;
        }
    };

    $coreRenderer = \Glpi\Application\View\TemplateRenderer::getInstance();
    $assert($coreRenderer === $renderer, 'TemplateRenderer::getInstance() must resolve the configured core renderer service.');

    $renderedDashboard = $coreRenderer->render('@gdmsintegration/dashboard.html.twig', [
        'is_configured' => true,
        'chart_days' => 7,
        'rows' => [],
        'cards' => [],
        'summary' => [],
        'net_stats' => [],
        'online' => 0,
        'offline' => 0,
        'total' => 0,
        'pct' => 0,
        'total_networks' => 0,
        'critical_devices' => [],
        'ops_summary' => [],
        'chart_datasets' => [['label' => 'test', 'data' => []]],
        'chart_labels' => [],
        'nodes' => [],
        'edges' => [],
        'show_topology' => false,
        'has_gwn_config' => false,
        'fp_lang' => 'en',
        'fp_region' => 'US',
        'js_json' => [],
    ]);
    $assert(
        str_contains($renderedDashboard, 'Based on availability and synchronization — last 7 days.')
            && str_contains($renderedDashboard, 'Online % — last 7 days'),
        'GLPI TemplateRenderer must compile and render both formatted dashboard labels.',
    );

    $renderedUnconfiguredDashboard = $coreRenderer->render('@gdmsintegration/dashboard.html.twig', [
        'is_configured' => false,
        'config_url' => '/plugins/gdmsintegration/config',
    ]);
    $assert(
        str_contains($renderedUnconfiguredDashboard, 'href="/plugins/gdmsintegration/config"')
            && str_contains($renderedUnconfiguredDashboard, 'Set up GDMS'),
        'GLPI TemplateRenderer must render the setup link without device or JavaScript dashboard data.',
    );

    $renderedConfig = $coreRenderer->render('@gdmsintegration/config_form.html.twig', [
        'cur' => [],
        'entities_id' => 42,
        'entity_dropdown' => '<select name="entities_id"><option value="42">Test entity</option></select>',
        'has_gdms' => false,
        'has_gwn' => false,
        'refresh_interval' => 300,
        'chart_days' => 60,
        'show_topology' => 1,
        'ip_version' => 'ipv4',
        'ticket_requester_id' => 0,
        'ticket_requesttype_id' => 0,
        'ticket_requesttype_options' => [],
        'ticket_category_network_id' => 0,
        'ticket_category_telephony_id' => 0,
        'ticket_category_options' => [],
        'wan_debounce_seconds' => 120,
        'wan_tickets_enabled' => 1,
        'gwn_alert_tickets_enabled' => 1,
        'max_xlsx_size_mb' => 5,
        'history_retention_days' => 30,
        'tickets_phone' => 1,
        'tickets_router' => 1,
        'tickets_switch' => 1,
        'tickets_ap' => 1,
        'tickets_pbx' => 1,
        'users_options' => [],
        'dashboard_url' => '/plugins/gdmsintegration/dashboard',
        'plugin_update' => ['update_available' => false, 'latest_version' => null, 'current_version' => '1.6.9'],
        'csrf_main' => 'csrf-main',
        'csrf_history' => 'csrf-history',
        'csrf_export' => 'csrf-export',
    ]);
    $assert(
        str_contains($renderedConfig, '<select name="entities_id">')
            && str_contains($renderedConfig, 'name="_glpi_csrf_token"'),
        'GLPI TemplateRenderer must compile and render the configuration template and form tokens.',
    );
}

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

fwrite(STDOUT, "PASS: GLPI TemplateRenderer/Twig integration regression checks.\n");
