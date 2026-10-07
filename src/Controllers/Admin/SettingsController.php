<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AnmeldungService;
use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\FachbereichService;
use App\Services\SettingsService;
use App\Services\UploadService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class SettingsController
{
    public function __construct(
        private readonly Twig $view,
        private readonly SettingsService $settings,
        private readonly UploadService $uploads,
        private readonly CsrfService $csrf,
        private readonly AuthService $auth,
        private readonly FachbereichService $fachbereiche,
        private readonly AnmeldungService $anmeldungen
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        $tab = (string) ($request->getQueryParams()['tab'] ?? 'darstellung');

        return $this->renderSettings($response, $flash, $error, null, $tab);
    }

    public function save(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles();
        $current = $this->settings->all();

        try {
            $image = $current['welcome_image'] ?? '';
            if (isset($files['welcome_image']) && $files['welcome_image']->getError() !== UPLOAD_ERR_NO_FILE) {
                $file = $files['welcome_image'];
                $tmp = tempnam(sys_get_temp_dir(), 'upl');
                if ($tmp === false) {
                    throw new \RuntimeException('Temporäre Datei fehlgeschlagen.');
                }
                $file->moveTo($tmp);
                $new = $this->uploads->storeImage([
                    'name' => $file->getClientFilename() ?? 'upload',
                    'type' => $file->getClientMediaType() ?? '',
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => $file->getSize() ?? 0,
                ], 'welcome');
                $this->uploads->delete($image);
                $image = $new;
            }
            if (!empty($data['remove_welcome_image'])) {
                $this->uploads->delete($image);
                $image = '';
            }

            $logo = $current['site_logo'] ?? '';
            if (isset($files['site_logo']) && $files['site_logo']->getError() !== UPLOAD_ERR_NO_FILE) {
                $file = $files['site_logo'];
                $tmp = tempnam(sys_get_temp_dir(), 'upl');
                if ($tmp === false) {
                    throw new \RuntimeException('Temporäre Datei fehlgeschlagen.');
                }
                $file->moveTo($tmp);
                $new = $this->uploads->storeImage([
                    'name' => $file->getClientFilename() ?? 'upload',
                    'type' => $file->getClientMediaType() ?? '',
                    'tmp_name' => $tmp,
                    'error' => UPLOAD_ERR_OK,
                    'size' => $file->getSize() ?? 0,
                ], 'logo');
                $this->uploads->delete($logo);
                $logo = $new;
            }
            if (!empty($data['remove_site_logo'])) {
                $this->uploads->delete($logo);
                $logo = '';
            }

            $impressumUrl = trim((string) ($data['impressum_url'] ?? ''));
            $datenschutzUrl = trim((string) ($data['datenschutz_url'] ?? ''));
            if ($impressumUrl !== '' && $this->settings->normalizeLegalUrl($impressumUrl) === '') {
                throw new \RuntimeException('Impressum-URL muss mit http:// oder https:// beginnen.');
            }
            if ($datenschutzUrl !== '' && $this->settings->normalizeLegalUrl($datenschutzUrl) === '') {
                throw new \RuntimeException('Datenschutz-URL muss mit http:// oder https:// beginnen.');
            }

            $this->settings->setMany([
                'welcome_text' => (string) ($data['welcome_text'] ?? ''),
                'welcome_image' => $image,
                'site_logo' => $logo,
                'registration_start' => trim((string) ($data['registration_start'] ?? '')),
                'registration_end' => trim((string) ($data['registration_end'] ?? '')),
                'mail_from' => trim((string) ($data['mail_from'] ?? '')),
                'mail_from_name' => trim((string) ($data['mail_from_name'] ?? '')),
                'mail_subject' => trim((string) ($data['mail_subject'] ?? '')),
                'impressum_url' => $this->settings->normalizeLegalUrl($impressumUrl),
                'impressum_text' => (string) ($data['impressum_text'] ?? ''),
                'datenschutz_url' => $this->settings->normalizeLegalUrl($datenschutzUrl),
                'datenschutz_text' => (string) ($data['datenschutz_text'] ?? ''),
            ]);

            $this->view->getEnvironment()->addGlobal('site_logo', $logo);
            $impressumHref = $this->settings->legalHref('impressum_url', 'impressum_text', '/impressum');
            $datenschutzHref = $this->settings->legalHref('datenschutz_url', 'datenschutz_text', '/datenschutz');
            $this->view->getEnvironment()->addGlobal('impressum_href', $impressumHref);
            $this->view->getEnvironment()->addGlobal('datenschutz_href', $datenschutzHref);
            $this->view->getEnvironment()->addGlobal('impressum_external', $this->settings->isExternalHref($impressumHref));
            $this->view->getEnvironment()->addGlobal('datenschutz_external', $this->settings->isExternalHref($datenschutzHref));

            $_SESSION['flash'] = 'Einstellungen gespeichert.';
            return $response
                ->withHeader('Location', '/administrator/einstellungen?tab=' . urlencode((string) ($data['active_tab'] ?? 'darstellung')))
                ->withStatus(302);
        } catch (\Throwable $e) {
            return $this->renderSettings(
                $response->withStatus(400),
                null,
                $e->getMessage(),
                array_merge($current, $data),
                (string) ($data['active_tab'] ?? 'darstellung')
            );
        }
    }

    public function createUser(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        try {
            $role = (string) ($data['role'] ?? AuthService::ROLE_ADMIN);
            $fbRaw = $data['fachbereich_id'] ?? '';
            $this->auth->createUser([
                'username' => trim((string) ($data['username'] ?? '')),
                'password' => (string) ($data['password'] ?? ''),
                'role' => $role,
                'fachbereich_id' => $fbRaw !== '' && $fbRaw !== null ? (int) $fbRaw : null,
            ]);
            $_SESSION['flash'] = 'Benutzer angelegt.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=benutzer')
            ->withStatus(302);
    }

    public function deleteUser(Request $request, Response $response, array $args): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->auth->deleteUser((int) $args['id']);
            $_SESSION['flash'] = 'Benutzer gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=benutzer')
            ->withStatus(302);
    }

    public function wipeData(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        $confirm = trim((string) ($data['confirm'] ?? ''));
        if ($confirm !== 'LÖSCHEN') {
            $_SESSION['flash_error'] = 'Bitte zur Bestätigung genau LÖSCHEN eingeben.';
            return $response
                ->withHeader('Location', '/administrator/einstellungen?tab=daten')
                ->withStatus(302);
        }

        try {
            $count = $this->anmeldungen->deleteAll();
            $_SESSION['flash'] = $count === 1
                ? '1 Anmeldung wurde gelöscht.'
                : $count . ' Anmeldungen wurden gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=daten')
            ->withStatus(302);
    }

    private function requireFullAdmin(Response $response): ?Response
    {
        if ($this->auth->canManageSettings()) {
            return null;
        }
        $_SESSION['flash'] = 'Keine Berechtigung für die Einstellungen.';
        return $response->withHeader('Location', '/administrator')->withStatus(302);
    }

    /**
     * @param array<string, mixed>|null $settingsOverride
     */
    private function renderSettings(
        Response $response,
        ?string $flash,
        ?string $error,
        ?array $settingsOverride = null,
        ?string $activeTab = null
    ): Response {
        $allowed = ['darstellung', 'zeitraum', 'email', 'rechtliches', 'benutzer', 'daten'];
        $tab = $activeTab ?? 'darstellung';
        if (!in_array($tab, $allowed, true)) {
            $tab = 'darstellung';
        }

        return $this->view->render($response, 'admin/settings.twig', [
            'settings' => $settingsOverride ?? $this->settings->all(),
            'users' => $this->auth->listUsers(),
            'fachbereiche' => $this->fachbereiche->all(),
            'anmeldungen_total' => $this->anmeldungen->countAll(),
            'flash' => $flash,
            'error' => $error,
            'active_tab' => $tab,
            'current_admin_id' => $this->auth->id(),
        ]);
    }
}
