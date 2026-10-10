<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AnmeldungService;
use App\Services\AuthService;
use App\Services\FachbereichService;
use App\Services\HtmlContentService;
use App\Services\MailService;
use App\Services\SettingsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class MailController
{
    /** @param array<string, mixed> $appSettings */
    public function __construct(
        private readonly Twig $view,
        private readonly AuthService $auth,
        private readonly FachbereichService $fachbereiche,
        private readonly AnmeldungService $anmeldungen,
        private readonly MailService $mail,
        private readonly SettingsService $settings,
        private readonly HtmlContentService $html,
        private readonly array $appSettings
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        return $this->renderForm($response, $flash, $error);
    }

    public function send(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $subjectTemplate = trim((string) ($data['subject'] ?? ''));
        $bodyRaw = (string) ($data['body'] ?? '');
        $bodyTemplate = $this->html->sanitize($bodyRaw);
        $scope = (string) ($data['scope'] ?? 'all');
        $includeFb = !empty($data['include_fachbereich']);

        try {
            if ($subjectTemplate === '') {
                throw new \RuntimeException('Bitte einen Betreff angeben.');
            }
            if (trim(strip_tags($bodyTemplate)) === '') {
                throw new \RuntimeException('Bitte einen E-Mail-Text angeben.');
            }

            $from = trim($this->settings->get('mail_from'));
            if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Bitte zuerst unter Einstellungen → E-Mail eine Absender-Adresse hinterlegen.');
            }
            $fromName = trim($this->settings->get('mail_from_name')) ?: 'Grundschultag';

            [$recipients, $previewLabel] = $this->resolveRecipients($scope, $includeFb);

            if ($recipients === []) {
                throw new \RuntimeException('Keine Empfänger für den gewählten Bereich gefunden.');
            }

            $baseUrl = rtrim((string) ($this->appSettings['app_url'] ?? ''), '/');
            if ($baseUrl === '') {
                $baseUrl = $this->guessBaseUrl();
            }
            $sent = 0;
            $failed = 0;
            $seen = [];

            foreach ($recipients as $recipient) {
                $to = strtolower(trim((string) ($recipient['email'] ?? '')));
                if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || isset($seen[$to])) {
                    continue;
                }
                $seen[$to] = true;

                [$subject, $bodyHtml] = $this->applyPlaceholders(
                    $subjectTemplate,
                    $bodyTemplate,
                    $recipient,
                    $fromName,
                    $baseUrl
                );

                if ($this->mail->send($to, $subject, $bodyHtml, $from, $fromName, true)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }

            if ($sent === 0) {
                throw new \RuntimeException('Der Versand ist fehlgeschlagen. Bitte Mail-Einstellungen prüfen.');
            }

            $msg = sprintf(
                'E-Mail versendet an %d Empfänger (%s).',
                $sent,
                $previewLabel
            );
            if ($failed > 0) {
                $msg .= sprintf(' %d Zustellung(en) fehlgeschlagen.', $failed);
            }
            $_SESSION['flash'] = $msg;
            return $response->withHeader('Location', '/administrator/email')->withStatus(302);
        } catch (\Throwable $e) {
            return $this->renderForm($response->withStatus(400), null, $e->getMessage(), [
                'subject' => $subjectTemplate,
                'body' => $bodyRaw,
                'scope' => $scope,
                'include_fachbereich' => $includeFb,
            ]);
        }
    }

    /**
     * @param array{subject?:string,body?:string,scope?:string,include_fachbereich?:bool}|null $old
     */
    private function renderForm(
        Response $response,
        ?string $flash,
        ?string $error,
        ?array $old = null
    ): Response {
        $scopeId = $this->auth->isFullAdmin() ? null : ($this->auth->fachbereichId() ?? 0);
        $fachbereiche = $scopeId === null
            ? $this->fachbereiche->all()
            : ($scopeId > 0 ? $this->fachbereiche->all(false, $scopeId) : []);

        $counts = [];
        foreach ($fachbereiche as $fb) {
            $id = (int) $fb['id'];
            $counts[$id] = count($this->anmeldungen->activeEmails($id));
        }
        $allCount = $this->auth->isFullAdmin()
            ? count($this->anmeldungen->activeEmails(null))
            : ($scopeId > 0 ? ($counts[$scopeId] ?? 0) : 0);

        return $this->view->render($response, 'admin/email.twig', [
            'fachbereiche' => $fachbereiche,
            'recipient_counts' => $counts,
            'all_count' => $allCount,
            'admin_is_full' => $this->auth->isFullAdmin(),
            'flash' => $flash,
            'error' => $error,
            'old' => $old ?? [],
        ]);
    }

    /**
     * @return array{0:list<array<string,string>>,1:string}
     */
    private function resolveRecipients(string $scope, bool $includeFachbereich): array
    {
        if ($scope === 'all') {
            if (!$this->auth->isFullAdmin()) {
                throw new \RuntimeException('Keine Berechtigung für alle Fachbereiche.');
            }

            $recipients = $this->anmeldungen->activeRecipients(null);
            if ($includeFachbereich) {
                foreach ($this->fachbereiche->all() as $fb) {
                    $extra = $this->fachbereichRecipient($fb);
                    if ($extra !== null) {
                        $recipients[] = $extra;
                    }
                }
            }

            return [$recipients, 'alle angemeldeten Personen'];
        }

        if (!str_starts_with($scope, 'fb:')) {
            throw new \RuntimeException('Bitte einen gültigen Empfängerkreis wählen.');
        }

        $fachbereichId = (int) substr($scope, 3);
        if ($fachbereichId < 1 || !$this->auth->canAccessFachbereich($fachbereichId)) {
            throw new \RuntimeException('Kein Zugriff auf diesen Fachbereich.');
        }

        $fb = $this->fachbereiche->find($fachbereichId);
        if ($fb === null) {
            throw new \RuntimeException('Fachbereich nicht gefunden.');
        }

        $recipients = $this->anmeldungen->activeRecipients($fachbereichId);
        if ($includeFachbereich) {
            $extra = $this->fachbereichRecipient($fb);
            if ($extra === null) {
                throw new \RuntimeException(
                    'Für diesen Fachbereich ist keine E-Mail hinterlegt. Bitte zuerst unter Fachbereiche eine Adresse speichern.'
                );
            }
            $recipients[] = $extra;
        }

        return [$recipients, (string) $fb['name']];
    }

    /**
     * @param array<string, mixed> $fb
     * @return array{email:string,name:string,fachbereich_name:string,schiene_name:string,token:string}|null
     */
    private function fachbereichRecipient(array $fb): ?array
    {
        $email = strtolower(trim((string) ($fb['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $name = (string) ($fb['name'] ?? 'Fachbereich');
        return [
            'email' => $email,
            'name' => $name,
            'fachbereich_name' => $name,
            'schiene_name' => '',
            'token' => '',
        ];
    }

    /**
     * @param array{email:string,name:string,fachbereich_name:string,schiene_name:string,token:string} $recipient
     * @return array{0:string,1:string}
     */
    private function applyPlaceholders(
        string $subjectTemplate,
        string $bodyTemplate,
        array $recipient,
        string $fromName,
        string $baseUrl
    ): array {
        $token = (string) ($recipient['token'] ?? '');
        $cancelUrl = ($baseUrl !== '' && $token !== '') ? $baseUrl . '/stornieren/' . $token : '';
        $thanksUrl = ($baseUrl !== '' && $token !== '') ? $baseUrl . '/danke/' . $token : '';

        $raw = [
            '{name}' => (string) ($recipient['name'] ?? ''),
            '{email}' => (string) ($recipient['email'] ?? ''),
            '{fachbereich}' => (string) ($recipient['fachbereich_name'] ?? ''),
            '{schiene}' => (string) ($recipient['schiene_name'] ?? ''),
            '{cancel_url}' => $cancelUrl,
            '{thanks_url}' => $thanksUrl,
            '{from_name}' => $fromName,
            '{logo}' => '',
        ];

        $html = [];
        foreach ($raw as $key => $value) {
            $html[$key] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        $html['{logo}'] = $this->logoImgHtml($baseUrl, $fromName);

        $subject = strtr($subjectTemplate, $raw);
        $bodyHtml = strtr($this->html->toSafeHtml($bodyTemplate), $html);

        return [$subject, $bodyHtml];
    }

    private function logoImgHtml(string $baseUrl, string $alt): string
    {
        $logo = trim($this->settings->get('site_logo'));
        if ($logo === '' || $baseUrl === '') {
            return '';
        }

        $filename = basename(str_replace('\\', '/', $logo));
        if ($filename === '' || $filename === '.' || $filename === '..') {
            return '';
        }

        $src = $baseUrl . '/uploads/' . rawurlencode($filename);
        return '<img src="' . htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' alt="' . htmlspecialchars($alt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
            . ' style="display:block;border:0;max-width:180px;max-height:72px;height:auto;" />';
    }

    private function guessBaseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}
