<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\CsrfService;
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
        private readonly CsrfService $csrf
    ) {
    }

    public function form(Request $request, Response $response): Response
    {
        unset($_SESSION['flash']);
        return $this->view->render($response, 'admin/settings.twig', [
            'settings' => $this->settings->all(),
            'flash' => null,
            'error' => null,
        ]);
    }

    public function save(Request $request, Response $response): Response
    {
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

            $this->settings->setMany([
                'welcome_text' => (string) ($data['welcome_text'] ?? ''),
                'welcome_image' => $image,
                'registration_start' => trim((string) ($data['registration_start'] ?? '')),
                'registration_end' => trim((string) ($data['registration_end'] ?? '')),
                'mail_from' => trim((string) ($data['mail_from'] ?? '')),
                'mail_from_name' => trim((string) ($data['mail_from_name'] ?? '')),
                'mail_subject' => trim((string) ($data['mail_subject'] ?? '')),
            ]);

            return $this->view->render($response, 'admin/settings.twig', [
                'settings' => $this->settings->all(),
                'flash' => 'Einstellungen gespeichert.',
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(400), 'admin/settings.twig', [
                'settings' => array_merge($current, $data),
                'flash' => null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
