<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuthService;
use App\Services\ExportService;
use App\Services\FachbereichService;
use App\Services\MailService;
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
        private readonly AuthService $auth,
        private readonly MailService $mail
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $scopeId = $this->auth->isFullAdmin() ? null : ($this->auth->fachbereichId() ?? 0);
        $flash = $_SESSION['flash'] ?? null;
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash'], $_SESSION['flash_error']);

        return $this->view->render($response, 'admin/listen.twig', [
            'fachbereiche' => $this->fachbereiche->withStats($scopeId),
            'flash' => $flash,
            'error' => $error,
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

    public function downloadAll(Request $request, Response $response): Response
    {
        $scopeId = $this->auth->isFullAdmin() ? null : ($this->auth->fachbereichId() ?? 0);
        $fachbereiche = $this->fachbereiche->withStats($scopeId);

        $logo = $this->settings->get('site_logo');
        try {
            // Verhindert, dass PHP-Warnungen den Binärstream beschädigen.
            ob_start();
            $zip = $this->export->zipForFachbereiche(
                $fachbereiche,
                $logo !== '' ? $logo : null
            );
            ob_end_clean();
        } catch (\RuntimeException) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            return $response->withStatus(404);
        }

        $filename = 'teilnehmerlisten_' . (new \DateTimeImmutable('now'))->format('Y-m-d') . '.zip';

        $response->getBody()->write($zip);
        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Length', (string) strlen($zip))
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }

    public function sendToFachbereich(Request $request, Response $response, array $args): Response
    {
        $fbId = (int) $args['id'];
        if (!$this->auth->canAccessFachbereich($fbId)) {
            $_SESSION['flash_error'] = 'Kein Zugriff auf diesen Fachbereich.';
            return $response->withHeader('Location', '/administrator/listen')->withStatus(302);
        }

        $fb = $this->fachbereiche->find($fbId);
        if ($fb === null) {
            return $response->withStatus(404);
        }

        $to = strtolower(trim((string) ($fb['email'] ?? '')));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Für „' . $fb['name'] . '“ ist keine E-Mail hinterlegt.';
            return $response->withHeader('Location', '/administrator/listen')->withStatus(302);
        }

        $from = trim($this->settings->get('mail_from'));
        if ($from === '' || !filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Bitte zuerst unter Einstellungen → E-Mail eine Absender-Adresse hinterlegen.';
            return $response->withHeader('Location', '/administrator/listen')->withStatus(302);
        }
        $fromName = trim($this->settings->get('mail_from_name')) ?: 'Grundschultag';

        $schienen = $this->fachbereiche->schienenFor($fbId);
        if ($schienen === []) {
            $_SESSION['flash_error'] = 'Dieser Fachbereich hat keine Schienen.';
            return $response->withHeader('Location', '/administrator/listen')->withStatus(302);
        }

        $logo = $this->settings->get('site_logo');
        $attachments = [];
        foreach ($schienen as $schiene) {
            $pdf = $this->export->pdfForSchiene(
                (string) $fb['name'],
                (string) $schiene['name'],
                (int) $schiene['id'],
                $logo !== '' ? $logo : null
            );
            $filename = 'teilnehmerliste_'
                . preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $fb['name']) . '_'
                . preg_replace('/[^a-zA-Z0-9_-]+/', '_', (string) $schiene['name']) . '.pdf';
            $attachments[] = [
                'filename' => $filename,
                'content' => $pdf,
                'mime' => 'application/pdf',
            ];
        }

        $datum = (new \DateTimeImmutable('now'))->format('d.m.Y H:i');
        $subject = 'Teilnehmerlisten: ' . (string) $fb['name'];
        $body = "Guten Tag,\n\n"
            . "anbei die aktuellen Teilnehmerlisten (PDF) für den Fachbereich „{$fb['name']}“.\n\n"
            . "Erstellt am: {$datum}\n"
            . "Anzahl Schienen: " . count($attachments) . "\n\n"
            . "Mit freundlichen Grüßen\n"
            . $fromName . "\n";

        $ok = $this->mail->send($to, $subject, $body, $from, $fromName, false, $attachments);
        if (!$ok) {
            $_SESSION['flash_error'] = 'Versand an den Fachbereich ist fehlgeschlagen.';
            return $response->withHeader('Location', '/administrator/listen')->withStatus(302);
        }

        $_SESSION['flash'] = 'Listen wurden an den Fachbereich „' . $fb['name'] . '“ gesendet.';
        return $response->withHeader('Location', '/administrator/listen')->withStatus(302);
    }
}
