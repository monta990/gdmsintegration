<?php

namespace GlpiPlugin\Gdmsintegration\Controller;

use Glpi\Controller\AbstractController;
use GlpiPlugin\Gdmsintegration\Service\AlertService;
use GlpiPlugin\Gdmsintegration\Service\BulkService;
use GlpiPlugin\Gdmsintegration\Service\ClientService;
use GlpiPlugin\Gdmsintegration\Service\ConfigService;
use GlpiPlugin\Gdmsintegration\Service\DashboardService;
use GlpiPlugin\Gdmsintegration\Service\FirmwareService;
use GlpiPlugin\Gdmsintegration\Service\HistoryExportService;
use GlpiPlugin\Gdmsintegration\Service\NetworkLocationService;
use GlpiPlugin\Gdmsintegration\Service\PortService;
use GlpiPlugin\Gdmsintegration\Service\SyncService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * HTTP entry point for GDMS Integration.
 *
 * Controllers own HTTP concerns (routing, request and response). Business and
 * integration logic lives in the service layer; no legacy endpoint files are
 * included or executed from here.
 */
final class GdmsController extends AbstractController
{
    #[Route('/dashboard', name: 'gdmsintegration_dashboard', methods: ['GET'])]
    public function dashboard(Request $request): Response
    {
        return DashboardService::render($request);
    }

    #[Route('/config', name: 'gdmsintegration_config', methods: ['GET', 'POST'])]
    public function config(Request $request): Response
    {
        return ConfigService::handle($request, rtrim($request->getPathInfo(), '/'));
    }

    #[Route('/alerts', name: 'gdmsintegration_alerts', methods: ['GET'])]
    public function alerts(Request $request): Response
    {
        return AlertService::list($request);
    }

    #[Route('/bulk', name: 'gdmsintegration_bulk', methods: ['GET', 'POST'])]
    public function bulk(Request $request): Response
    {
        return BulkService::reboot($request);
    }

    #[Route('/clients', name: 'gdmsintegration_clients', methods: ['GET'])]
    public function clients(Request $request): Response
    {
        return ClientService::list($request);
    }

    #[Route('/firmware', name: 'gdmsintegration_firmware', methods: ['GET', 'POST'])]
    public function firmware(Request $request): Response
    {
        return FirmwareService::handle($request);
    }

    #[Route('/history/export', name: 'gdmsintegration_history_export', methods: ['GET'])]
    public function historyExport(Request $request): Response
    {
        return HistoryExportService::export($request);
    }

    #[Route('/network-location', name: 'gdmsintegration_network_location', methods: ['GET', 'POST'])]
    public function networkLocation(Request $request): Response
    {
        return NetworkLocationService::save($request);
    }

    #[Route('/ports', name: 'gdmsintegration_ports', methods: ['GET'])]
    public function ports(Request $request): Response
    {
        return PortService::status($request);
    }

    #[Route('/sync', name: 'gdmsintegration_sync', methods: ['GET', 'POST'])]
    public function sync(Request $request): Response
    {
        return SyncService::run($request);
    }
}
