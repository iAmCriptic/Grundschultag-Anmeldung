<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\ExportService;
use App\Services\FachbereichService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class ExportController
{
    public function __construct(
        private readonly Twig $view,
        private readonly FachbereichService $fachbereiche,
        private readonly ExportService $export
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/listen.twig', [
            'fachbereiche' => $this->fachbereiche->withStats(),
        ]);
    }

    public function download(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $fb = $this->fachbereiche->find($id);
        if ($fb === null) {
            return $response->withStatus(404);
        }
        $csv = $this->export->csvForFachbereich($id);
        $filename = 'anmeldungen_' . preg_replace('/[^a-zA-Z0-9_-]+/', '_', $fb['name']) . '.csv';
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
