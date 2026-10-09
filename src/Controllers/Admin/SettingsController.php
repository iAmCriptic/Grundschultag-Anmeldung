<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AnmeldungService;
use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\FachbereichService;
use App\Services\HtmlContentService;
use App\Services\SettingsService;
use App\Services\UpdateService;
use App\Services\UploadService;
use RuntimeException;
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
        private readonly AnmeldungService $anmeldungen,
        private readonly UpdateService $updater,
        private readonly HtmlContentService $html
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

            $siteTitle = trim((string) ($data['site_title'] ?? ''));
            if ($siteTitle === '') {
                throw new \RuntimeException('Bitte einen Seitentitel angeben.');
            }
            if (mb_strlen($siteTitle) > 150) {
                throw new \RuntimeException('Der Seitentitel darf höchstens 150 Zeichen lang sein.');
            }

            $aboutLabel = trim((string) ($data['about_link_label'] ?? ''));
            if ($aboutLabel === '') {
                $aboutLabel = 'Woher kommt diese Seite';
            }
            if (mb_strlen($aboutLabel) > 80) {
                throw new \RuntimeException('Der Linkname darf höchstens 80 Zeichen lang sein.');
            }

            $mailBody = (string) ($data['mail_body'] ?? '');
            // Normalize Windows newlines for plain-text mail templates
            $mailBody = str_replace("\r\n", "\n", $mailBody);

            $this->settings->setMany([
                'site_title' => $siteTitle,
                'welcome_text' => $this->html->sanitize((string) ($data['welcome_text'] ?? '')),
                'welcome_image' => $image,
                'site_logo' => $logo,
                'registration_start' => trim((string) ($data['registration_start'] ?? '')),
                'registration_end' => trim((string) ($data['registration_end'] ?? '')),
                'mail_from' => trim((string) ($data['mail_from'] ?? '')),
                'mail_from_name' => trim((string) ($data['mail_from_name'] ?? '')),
                'mail_subject' => trim((string) ($data['mail_subject'] ?? '')),
                'mail_body' => $mailBody,
                'impressum_url' => $this->settings->normalizeLegalUrl($impressumUrl),
                'impressum_text' => $this->html->sanitize((string) ($data['impressum_text'] ?? '')),
                'datenschutz_url' => $this->settings->normalizeLegalUrl($datenschutzUrl),
                'datenschutz_text' => $this->html->sanitize((string) ($data['datenschutz_text'] ?? '')),
                'about_link_label' => $aboutLabel,
                'about_text' => $this->html->sanitize((string) ($data['about_text'] ?? '')),
            ]);

            $this->view->getEnvironment()->addGlobal('app_name', $siteTitle);
            $this->view->getEnvironment()->addGlobal('site_logo', $logo);
            $impressumHref = $this->settings->legalHref('impressum_url', 'impressum_text', '/impressum');
            $datenschutzHref = $this->settings->legalHref('datenschutz_url', 'datenschutz_text', '/datenschutz');
            $aboutHref = trim($this->settings->get('about_text')) !== '' ? '/woher' : '';
            $this->view->getEnvironment()->addGlobal('impressum_href', $impressumHref);
            $this->view->getEnvironment()->addGlobal('datenschutz_href', $datenschutzHref);
            $this->view->getEnvironment()->addGlobal('about_href', $aboutHref);
            $this->view->getEnvironment()->addGlobal('about_link_label', $aboutLabel);
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

    public function saveUpdateSource(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        try {
            $this->updater->savePreferences(
                trim((string) ($data['update_repo_url'] ?? '')),
                trim((string) ($data['update_ref'] ?? UpdateService::DEFAULT_REF))
            );
            $_SESSION['flash'] = 'Update-Quelle gespeichert. Beim nächsten Admin-Login wird automatisch auf Updates geprüft.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=update')
            ->withStatus(302);
    }

    public function checkUpdate(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

        try {
            if (!$this->updater->isConfigured()) {
                throw new RuntimeException('Bitte zuerst die Update-Quelle speichern.');
            }
            $result = $this->updater->checkForUpdate(true);
            if ($result === null) {
                throw new RuntimeException('Remote-Version konnte nicht gelesen werden. URL und Branch/Tag prüfen.');
            }
            if ($result['available']) {
                $_SESSION['flash'] = 'Update verfügbar: ' . $result['current'] . ' → ' . $result['remote'] . '.';
            } else {
                $_SESSION['flash'] = 'Aktuell: Version ' . $result['current']
                    . ' (Remote: ' . $result['remote'] . ').';
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=update')
            ->withStatus(302);
    }

    public function runUpdate(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        $confirm = trim((string) ($data['confirm'] ?? ''));
        if ($confirm !== 'UPDATE') {
            $_SESSION['flash_error'] = 'Bitte zur Bestätigung genau UPDATE eingeben.';
            return $response
                ->withHeader('Location', '/administrator/einstellungen?tab=update')
                ->withStatus(302);
        }

        try {
            @set_time_limit(300);
            $result = $this->updater->applyConfigured();
            $_SESSION['flash'] = $result['message'];
            unset($_SESSION['update_notice']);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response
            ->withHeader('Location', '/administrator/einstellungen?tab=update')
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
        $allowed = ['darstellung', 'zeitraum', 'email', 'rechtliches', 'benutzer', 'daten', 'update'];
        $tab = $activeTab ?? 'darstellung';
        if (!in_array($tab, $allowed, true)) {
            $tab = 'darstellung';
        }

        $updateStatus = null;
        if ($tab === 'update') {
            try {
                $updateStatus = $this->updater->status();
            } catch (\Throwable $e) {
                $error = $error ?? $e->getMessage();
                $updateStatus = [
                    'repo_url' => $this->updater->configuredRepoUrl(),
                    'ref' => $this->updater->configuredRef(),
                    'current' => $this->updater->currentVersion(),
                    'remote' => null,
                    'up_to_date' => null,
                    'configured' => $this->updater->isConfigured(),
                    'download_url' => '',
                    'check_at' => '',
                ];
            }
        }

        $settings = $settingsOverride ?? $this->settings->all();
        if (trim((string) ($settings['mail_body'] ?? '')) === '') {
            $settings['mail_body'] = AnmeldungService::defaultMailBody();
        }

        return $this->view->render($response, 'admin/settings.twig', [
            'settings' => $settings,
            'mail_body_default' => AnmeldungService::defaultMailBody(),
            'users' => $this->auth->listUsers(),
            'fachbereiche' => $this->fachbereiche->all(),
            'anmeldungen_total' => $this->anmeldungen->countAll(),
            'flash' => $flash,
            'error' => $error,
            'active_tab' => $tab,
            'current_admin_id' => $this->auth->id(),
            'update_status' => $updateStatus,
        ]);
    }
}
