<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AnmeldungService;
use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\FachbereichService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class PublicController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SettingsService $settings,
        private readonly FachbereichService $fachbereiche,
        private readonly AnmeldungService $anmeldungen,
        private readonly CsrfService $csrf,
        private readonly AuthService $auth
    ) {
    }

    public function home(Request $request, Response $response): Response
    {
        $all = $this->settings->all();
        return $this->view->render($response, 'public/home.twig', [
            'welcome_text' => $all['welcome_text'] ?? '',
            'welcome_image' => $all['welcome_image'] ?? '',
            'open' => $this->settings->isRegistrationOpen(),
            'registration_start' => $all['registration_start'] ?? '',
            'registration_end' => $all['registration_end'] ?? '',
            'admin_logged_in' => $this->auth->check(),
        ]);
    }

    public function registerForm(Request $request, Response $response): Response
    {
        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        if (!$open && !$adminTest) {
            return $this->view->render($response, 'public/closed.twig');
        }

        return $this->view->render($response, 'public/register.twig', [
            'fachbereiche' => $this->activeFachbereicheWithSchienen(),
            'selected_fb' => isset($request->getQueryParams()['fb']) ? (int) $request->getQueryParams()['fb'] : null,
            'error' => null,
            'old' => [],
            'admin_test_mode' => !$open && $adminTest,
        ]);
    }

    public function registerSubmit(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();

        if (!$open && !$adminTest) {
            return $this->view->render($response, 'public/closed.twig');
        }

        try {
            $anmeldung = $this->anmeldungen->register([
                'name' => (string) ($data['name'] ?? ''),
                'email' => (string) ($data['email'] ?? ''),
                'schiene_id' => (int) ($data['schiene_id'] ?? 0),
            ], !$open && $adminTest);
            return $response
                ->withHeader('Location', '/danke/' . $anmeldung['token'])
                ->withStatus(302);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(400), 'public/register.twig', [
                'fachbereiche' => $this->activeFachbereicheWithSchienen(),
                'selected_fb' => isset($data['fachbereich_id']) ? (int) $data['fachbereich_id'] : null,
                'error' => $e->getMessage(),
                'old' => $data,
                'admin_test_mode' => !$open && $adminTest,
            ]);
        }
    }

    public function thanks(Request $request, Response $response, array $args): Response
    {
        $anmeldung = $this->anmeldungen->findByToken((string) $args['token']);
        if ($anmeldung === null) {
            $response->getBody()->write('Anmeldung nicht gefunden.');
            return $response->withStatus(404);
        }
        return $this->view->render($response, 'public/thanks.twig', [
            'anmeldung' => $anmeldung,
        ]);
    }

    public function cancelForm(Request $request, Response $response, array $args): Response
    {
        $anmeldung = $this->anmeldungen->findByToken((string) $args['token']);
        if ($anmeldung === null) {
            $response->getBody()->write('Anmeldung nicht gefunden.');
            return $response->withStatus(404);
        }
        return $this->view->render($response, 'public/cancel.twig', [
            'anmeldung' => $anmeldung,
        ]);
    }

    public function cancelSubmit(Request $request, Response $response, array $args): Response
    {
        $token = (string) $args['token'];
        try {
            $this->anmeldungen->cancel($token);
            $anmeldung = $this->anmeldungen->findByToken($token);
            return $this->view->render($response, 'public/cancel.twig', [
                'anmeldung' => $anmeldung,
                'done' => true,
            ]);
        } catch (\Throwable $e) {
            $anmeldung = $this->anmeldungen->findByToken($token);
            return $this->view->render($response->withStatus(400), 'public/cancel.twig', [
                'anmeldung' => $anmeldung,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function impressum(Request $request, Response $response): Response
    {
        return $this->renderLegalPage(
            $response,
            'Impressum',
            'public/impressum.twig',
            'impressum_url',
            'impressum_text'
        );
    }

    public function datenschutz(Request $request, Response $response): Response
    {
        return $this->renderLegalPage(
            $response,
            'Datenschutz',
            'public/datenschutz.twig',
            'datenschutz_url',
            'datenschutz_text'
        );
    }

    public function about(Request $request, Response $response): Response
    {
        $label = trim($this->settings->get('about_link_label'));
        if ($label === '') {
            $label = 'Woher kommt diese Seite';
        }
        $text = trim($this->settings->get('about_text'));

        if ($text !== '') {
            return $this->view->render($response, 'public/about.twig', [
                'page_title' => $label,
                'body' => $text,
                'missing' => false,
            ]);
        }

        return $this->view->render($response->withStatus(404), 'public/about.twig', [
            'page_title' => $label,
            'body' => '',
            'missing' => true,
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function activeFachbereicheWithSchienen(): array
    {
        $list = $this->fachbereiche->all(true);
        $withSchienen = [];
        foreach ($list as $fb) {
            $fb['schienen'] = $this->fachbereiche->schienenFor((int) $fb['id']);
            $withSchienen[] = $fb;
        }
        return $withSchienen;
    }

    private function renderLegalPage(
        Response $response,
        string $title,
        string $template,
        string $urlKey,
        string $textKey
    ): Response {
        $text = trim($this->settings->get($textKey));
        $url = $this->settings->normalizeLegalUrl($this->settings->get($urlKey));

        if ($text !== '') {
            return $this->view->render($response, $template, [
                'title' => $title,
                'body' => $text,
                'missing' => false,
            ]);
        }

        if ($url !== '') {
            return $response
                ->withHeader('Location', $url)
                ->withStatus(302);
        }

        return $this->view->render($response->withStatus(404), $template, [
            'title' => $title,
            'body' => '',
            'missing' => true,
        ]);
    }
}
