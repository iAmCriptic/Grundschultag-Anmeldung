<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AnmeldungService;
use App\Services\AuthService;
use App\Services\FachbereichService;
use App\Services\UpdateService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(
        private readonly Twig $view,
        private readonly FachbereichService $fachbereiche,
        private readonly AnmeldungService $anmeldungen,
        private readonly AuthService $auth,
        private readonly UpdateService $updater
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $scopeId = $this->scopedFachbereichId();

        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $updateNotice = null;
        if ($this->auth->canManageSettings()) {
            $updateNotice = $_SESSION['update_notice'] ?? null;
            if ($updateNotice === null && $this->updater->isConfigured()) {
                $updateNotice = $this->updater->updateNotice(false);
                if ($updateNotice !== null) {
                    $_SESSION['update_notice'] = $updateNotice;
                }
            }
        }

        return $this->view->render($response, 'admin/dashboard.twig', [
            'fachbereiche' => $this->fachbereiche->withStats($scopeId),
            'active_count' => $this->anmeldungen->countActive(
                $this->auth->isFullAdmin() ? null : ($scopeId ?? 0)
            ),
            'admin_username' => $this->auth->username() ?? '',
            'admin_is_full' => $this->auth->isFullAdmin(),
            'flash' => $flash,
            'update_notice' => $updateNotice,
        ]);
    }

    /**
     * null = alle Fachbereiche (Admin), sonst nur die eigene ID.
     * 0 = Fachbereich-Nutzer ohne gültige Zuordnung (leere Liste).
     */
    private function scopedFachbereichId(): ?int
    {
        if ($this->auth->isFullAdmin()) {
            return null;
        }
        return $this->auth->fachbereichId() ?? 0;
    }
}
