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

    public function registerList(Request $request, Response $response): Response
    {
        $gate = $this->registrationGate($response);
        if ($gate !== null) {
            return $gate;
        }

        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        return $this->view->render($response, 'public/register_list.twig', [
            'fachbereiche' => $this->activeFachbereicheWithSchienen(),
            'admin_test_mode' => !$open && $adminTest,
            'flash' => $flash,
        ]);
    }

    public function registerForm(Request $request, Response $response, array $args): Response
    {
        $gate = $this->registrationGate($response);
        if ($gate !== null) {
            return $gate;
        }

        $fachbereich = $this->findActiveFachbereichWithSchienen((int) $args['id']);
        if ($fachbereich === null) {
            $_SESSION['flash'] = 'Dieser Fachbereich ist nicht verfügbar.';
            return $response->withHeader('Location', '/anmelden')->withStatus(302);
        }

        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();

        return $this->view->render($response, 'public/register.twig', [
            'fachbereich' => $fachbereich,
            'error' => null,
            'old' => [],
            'admin_test_mode' => !$open && $adminTest,
        ]);
    }

    public function registerSubmit(Request $request, Response $response, array $args): Response
    {
        $gate = $this->registrationGate($response);
        if ($gate !== null) {
            return $gate;
        }

        $fachbereichId = (int) $args['id'];
        $fachbereich = $this->findActiveFachbereichWithSchienen($fachbereichId);
        if ($fachbereich === null) {
            $_SESSION['flash'] = 'Dieser Fachbereich ist nicht verfügbar.';
            return $response->withHeader('Location', '/anmelden')->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        $schieneId = (int) ($data['schiene_id'] ?? 0);

        try {
            $this->assertSchieneBelongsToFachbereich($schieneId, $fachbereichId);
            $anmeldung = $this->anmeldungen->register([
                'name' => (string) ($data['name'] ?? ''),
                'email' => (string) ($data['email'] ?? ''),
                'schiene_id' => $schieneId,
            ], !$open && $adminTest);
            return $response
                ->withHeader('Location', '/danke/' . $anmeldung['token'])
                ->withStatus(302);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(400), 'public/register.twig', [
                'fachbereich' => $fachbereich,
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

    private function registrationGate(Response $response): ?Response
    {
        $open = $this->settings->isRegistrationOpen();
        $adminTest = $this->auth->check();
        if (!$open && !$adminTest) {
            return $this->view->render($response, 'public/closed.twig');
        }
        return null;
    }

    /** @return list<array<string, mixed>> */
    private function activeFachbereicheWithSchienen(): array
    {
        $list = $this->fachbereiche->all(true);
        $withSchienen = [];
        foreach ($list as $fb) {
            $schienen = $this->fachbereiche->schienenFor((int) $fb['id']);
            $kapTotal = 0;
            $freiTotal = 0;
            foreach ($schienen as $schiene) {
                $kap = (int) $schiene['kapazitaet'];
                $belegt = (int) ($schiene['belegt'] ?? 0);
                $kapTotal += $kap;
                $freiTotal += max(0, $kap - $belegt);
            }
            $fb['schienen'] = $schienen;
            $fb['kap_total'] = $kapTotal;
            $fb['frei_total'] = $freiTotal;
            $withSchienen[] = $fb;
        }
        return $withSchienen;
    }

    /** @return array<string, mixed>|null */
    private function findActiveFachbereichWithSchienen(int $id): ?array
    {
        $fb = $this->fachbereiche->find($id);
        if ($fb === null || !(int) ($fb['aktiv'] ?? 0)) {
            return null;
        }
        $schienen = $this->fachbereiche->schienenFor($id);
        foreach ($schienen as &$schiene) {
            $kap = (int) $schiene['kapazitaet'];
            $belegt = (int) ($schiene['belegt'] ?? 0);
            $schiene['frei'] = max(0, $kap - $belegt);
        }
        unset($schiene);
        $fb['schienen'] = $schienen;
        return $fb;
    }

    private function assertSchieneBelongsToFachbereich(int $schieneId, int $fachbereichId): void
    {
        $schiene = $this->fachbereiche->findSchiene($schieneId);
        if ($schiene === null || (int) $schiene['fachbereich_id'] !== $fachbereichId) {
            throw new \RuntimeException('Bitte eine gültige Schiene für diesen Fachbereich wählen.');
        }
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
