<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuthService;
use App\Services\ExportService;
use App\Services\FachbereichService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ExportController
{
    public function __construct(
        private readonly Twig $view,
        private readonly FachbereichService $fachbereiche,
        private readonly ExportService $export,
        private readonly SettingsService $settings,
        private readonly AuthService $auth
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $scopeId = $this->auth->isFullAdmin() ? null : ($this->auth->fachbereichId() ?? 0);

        return $this->view->render($response, 'admin/listen.twig', [
            'fachbereiche' => $this->fachbereiche->withStats($scopeId),
        ]);
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $fbId = (int) $args['id'];
        $schieneId = (int) $args['schieneId'];

        if (!$this->auth->canAccessFachbereich($fbId)) {
            return $response->withStatus(403);
        }

        $fb = $this->fachbereiche->find($fbId);
        if ($fb === null) {
            return $response->withStatus(404);
        }

        $schiene = $this->fachbereiche->findSchiene($schieneId);
        if ($schiene === null || (int) $schiene['fachbereich_id'] !== $fbId) {
            return $response->withStatus(404);
        }

        $logo = $this->settings->get('site_logo');
        $pdf = $this->export->pdfForSchiene(
            (string) $fb['name'],
            (string) $schiene['name'],
            $schieneId,
            $logo !== '' ? $logo : null
        );

        $filename = 'teilnehmerliste_'
            . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $fb['name']) . '_'
            . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $schiene['name']) . '.pdf';

        $response->getBody()->write($pdf);
        return $response
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
