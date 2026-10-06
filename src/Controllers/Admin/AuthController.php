<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuthService;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class AuthController
{
    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly CsrfService $csrf
    ) {
    }

    public function loginForm(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $response->withHeader('Location', '/administrator')->withStatus(302);
        }
        return $this->view->render($response, 'admin/login.twig', ['error' => null]);
    }

    public function loginSubmit(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $ok = $this->auth->attempt(
            trim((string) ($data['username'] ?? '')),
            (string) ($data['password'] ?? '')
        );
        if (!$ok) {
            return $this->view->render($response->withStatus(401), 'admin/login.twig', [
                'error' => 'Benutzername oder Passwort ungültig.',
            ]);
        }
        return $response->withHeader('Location', '/administrator')->withStatus(302);
    }

    public function logout(Request $request, Response $response): Response
    {
        $this->auth->logout();
        return $response->withHeader('Location', '/administrator/login')->withStatus(302);
    }
}
