<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\CsrfService;
use App\Services\FachbereichService;
use App\Services\UploadService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

final class FachbereichController
{
    public function __construct(
        private readonly Twig $view,
        private readonly FachbereichService $fachbereiche,
        private readonly UploadService $uploads,
        private readonly CsrfService $csrf
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $this->view->render($response, 'admin/fachbereiche/index.twig', [
            'fachbereiche' => $this->fachbereiche->withStats(),
            'flash' => $flash,
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'admin/fachbereiche/form.twig', [
            'fachbereich' => null,
            'schienen' => [],
            'error' => null,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles();
        try {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw new \RuntimeException('Name ist erforderlich.');
            }
            $bild = null;
            if (isset($files['teaser_bild']) && $files['teaser_bild']->getError() !== UPLOAD_ERR_NO_FILE) {
                $bild = $this->storeUploaded($files['teaser_bild']);
            }
            $id = $this->fachbereiche->create([
                'name' => $name,
                'teaser_text' => trim((string) ($data['teaser_text'] ?? '')),
                'teaser_bild' => $bild,
                'aktiv' => isset($data['aktiv']) ? 1 : 0,
                'sortierung' => (int) ($data['sortierung'] ?? 0),
            ]);
            $_SESSION['flash'] = 'Fachbereich angelegt.';
            return $response->withHeader('Location', '/administrator/fachbereiche/' . $id)->withStatus(302);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(400), 'admin/fachbereiche/form.twig', [
                'fachbereich' => $data,
                'schienen' => [],
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $fb = $this->fachbereiche->find((int) $args['id']);
        if ($fb === null) {
            return $response->withStatus(404);
        }
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return $this->view->render($response, 'admin/fachbereiche/form.twig', [
            'fachbereich' => $fb,
            'schienen' => $this->fachbereiche->schienenFor((int) $fb['id']),
            'error' => null,
            'flash' => $flash,
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $fb = $this->fachbereiche->find($id);
        if ($fb === null) {
            return $response->withStatus(404);
        }
        $data = (array) $request->getParsedBody();
        $files = $request->getUploadedFiles();
        try {
            $bild = $fb['teaser_bild'] ?? null;
            if (isset($files['teaser_bild']) && $files['teaser_bild']->getError() !== UPLOAD_ERR_NO_FILE) {
                $new = $this->storeUploaded($files['teaser_bild']);
                $this->uploads->delete($bild);
                $bild = $new;
            }
            if (!empty($data['remove_image'])) {
                $this->uploads->delete($bild);
                $bild = null;
            }
            $this->fachbereiche->update($id, [
                'name' => trim((string) ($data['name'] ?? '')),
                'teaser_text' => trim((string) ($data['teaser_text'] ?? '')),
                'teaser_bild' => $bild,
                'aktiv' => isset($data['aktiv']) ? 1 : 0,
                'sortierung' => (int) ($data['sortierung'] ?? 0),
            ]);
            $_SESSION['flash'] = 'Fachbereich gespeichert.';
            return $response->withHeader('Location', '/administrator/fachbereiche/' . $id)->withStatus(302);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(400), 'admin/fachbereiche/form.twig', [
                'fachbereich' => array_merge($fb, $data),
                'schienen' => $this->fachbereiche->schienenFor($id),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $this->fachbereiche->toggle((int) $args['id']);
        return $response->withHeader('Location', '/administrator/fachbereiche')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        try {
            $fb = $this->fachbereiche->find((int) $args['id']);
            $this->fachbereiche->delete((int) $args['id']);
            if ($fb) {
                $this->uploads->delete($fb['teaser_bild'] ?? null);
            }
            $_SESSION['flash'] = 'Fachbereich gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['flash'] = $e->getMessage();
        }
        return $response->withHeader('Location', '/administrator/fachbereiche')->withStatus(302);
    }

    public function addSchiene(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        try {
            $this->fachbereiche->addSchiene(
                $id,
                trim((string) ($data['name'] ?? '')),
                (int) ($data['kapazitaet'] ?? 20),
                (int) ($data['sortierung'] ?? 0)
            );
            $_SESSION['flash'] = 'Schiene hinzugefügt.';
        } catch (\Throwable $e) {
            $_SESSION['flash'] = $e->getMessage();
        }
        return $response->withHeader('Location', '/administrator/fachbereiche/' . $id)->withStatus(302);
    }

    public function updateSchiene(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        try {
            $this->fachbereiche->updateSchiene(
                (int) $args['schieneId'],
                trim((string) ($data['name'] ?? '')),
                (int) ($data['kapazitaet'] ?? 20),
                (int) ($data['sortierung'] ?? 0)
            );
            $_SESSION['flash'] = 'Schiene gespeichert.';
        } catch (\Throwable $e) {
            $_SESSION['flash'] = $e->getMessage();
        }
        return $response->withHeader('Location', '/administrator/fachbereiche/' . $id)->withStatus(302);
    }

    public function deleteSchiene(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        try {
            $this->fachbereiche->deleteSchiene((int) $args['schieneId']);
            $_SESSION['flash'] = 'Schiene gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['flash'] = $e->getMessage();
        }
        return $response->withHeader('Location', '/administrator/fachbereiche/' . $id)->withStatus(302);
    }

    private function storeUploaded(\Psr\Http\Message\UploadedFileInterface $file): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        if ($tmp === false) {
            throw new \RuntimeException('Temporäre Datei konnte nicht erstellt werden.');
        }
        $file->moveTo($tmp);
        return $this->uploads->storeImage([
            'name' => $file->getClientFilename() ?? 'upload',
            'type' => $file->getClientMediaType() ?? '',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => $file->getSize() ?? 0,
        ], 'fb');
    }
}
