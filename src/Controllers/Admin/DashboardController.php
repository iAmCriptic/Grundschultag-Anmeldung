<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AnmeldungService;
use App\Services\FachbereichService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(
        private readonly Twig $view,
        private readonly FachbereichService $fachbereiche,
        private readonly AnmeldungService $anmeldungen
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/dashboard.twig', [
            'fachbereiche' => $this->fachbereiche->withStats(),
            'active_count' => $this->anmeldungen->countActive(),
            'admin_username' => $_SESSION['admin_username'] ?? '',
        ]);
    }
}
