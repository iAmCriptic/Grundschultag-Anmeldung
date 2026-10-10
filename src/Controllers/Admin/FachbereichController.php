<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Services\AuthService;
use App\Services\CsrfService;
use App\Services\FachbereichService;
use App\Services\HtmlContentService;
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
        private readonly CsrfService $csrf,
        private readonly AuthService $auth,
        private readonly HtmlContentService $html
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        $scopeId = $this->auth->isFullAdmin() ? null : ($this->auth->fachbereichId() ?? 0);

        return $this->view->render($response, 'admin/fachbereiche/index.twig', [
            'fachbereiche' => $this->fachbereiche->withStats($scopeId),
            'flash' => $flash,
            'admin_is_full' => $this->auth->isFullAdmin(),
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

        return $this->view->render($response, 'admin/fachbereiche/form.twig', [
            'fachbereich' => null,
            'schienen' => [],
            'error' => null,
            'admin_is_full' => true,
        ]);
    }

    public function create(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

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
                'teaser_text' => $this->html->sanitize((string) ($data['teaser_text'] ?? '')),
                'teaser_bild' => $bild,
                'email' => $this->normalizeEmail($data['email'] ?? null),
                'aktiv' => isset($data['aktiv']) ? 1 : 0,
            ]);
            $_SESSION['flash'] = 'Fachbereich angelegt.';
            return $response->withHeader('Location', '/administrator/fachbereiche/' . $id)->withStatus(302);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(400), 'admin/fachbereiche/form.twig', [
                'fachbereich' => $data,
                'schienen' => [],
                'error' => $e->getMessage(),
                'admin_is_full' => true,
            ]);
        }
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $denied = $this->requireFachbereichAccess($response, $id);
        if ($denied !== null) {
            return $denied;
        }

        $fb = $this->fachbereiche->find($id);
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
            'admin_is_full' => $this->auth->isFullAdmin(),
        ]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $denied = $this->requireFachbereichAccess($response, $id);
        if ($denied !== null) {
            return $denied;
        }

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
                'teaser_text' => $this->html->sanitize((string) ($data['teaser_text'] ?? '')),
                'teaser_bild' => $bild,
                'email' => $this->normalizeEmail($data['email'] ?? null),
                'aktiv' => isset($data['aktiv']) ? 1 : 0,
                'sortierung' => (int) ($fb['sortierung'] ?? 0),
            ]);
            $_SESSION['flash'] = 'Fachbereich gespeichert.';
            return $response->withHeader('Location', '/administrator/fachbereiche/' . $id)->withStatus(302);
        } catch (\Throwable $e) {
            return $this->view->render($response->withStatus(400), 'admin/fachbereiche/form.twig', [
                'fachbereich' => array_merge($fb, $data),
                'schienen' => $this->fachbereiche->schienenFor($id),
                'error' => $e->getMessage(),
                'admin_is_full' => $this->auth->isFullAdmin(),
            ]);
        }
    }

    public function toggle(Request $request, Response $response, array $args): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

        $this->fachbereiche->toggle((int) $args['id']);
        return $response->withHeader('Location', '/administrator/fachbereiche')->withStatus(302);
    }

    public function reorder(Request $request, Response $response): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
            if ($isAjax) {
                $response->getBody()->write(json_encode(['ok' => false, 'error' => 'Keine Berechtigung.'], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
            }
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        $order = $data['order'] ?? [];
        if (!is_array($order)) {
            $order = [];
        }

        try {
            $this->fachbereiche->reorder(array_map('intval', $order));
            $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
            if ($isAjax) {
                $response->getBody()->write(json_encode(['ok' => true], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json');
            }
            $_SESSION['flash'] = 'Reihenfolge gespeichert.';
        } catch (\Throwable $e) {
            $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
            if ($isAjax) {
                $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $_SESSION['flash'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/administrator/fachbereiche')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $denied = $this->requireFullAdmin($response);
        if ($denied !== null) {
            return $denied;
        }

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
        $denied = $this->requireFachbereichAccess($response, $id);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        try {
            $this->fachbereiche->addSchiene(
                $id,
                trim((string) ($data['name'] ?? '')),
                (int) ($data['kapazitaet'] ?? 20)
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
        $denied = $this->requireFachbereichAccess($response, $id);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        try {
            $this->fachbereiche->updateSchiene(
                (int) $args['schieneId'],
                trim((string) ($data['name'] ?? '')),
                (int) ($data['kapazitaet'] ?? 20)
            );
            $_SESSION['flash'] = 'Schiene gespeichert.';
        } catch (\Throwable $e) {
            $_SESSION['flash'] = $e->getMessage();
        }
        return $response->withHeader('Location', '/administrator/fachbereiche/' . $id)->withStatus(302);
    }

    public function reorderSchienen(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $denied = $this->requireFachbereichAccess($response, $id);
        if ($denied !== null) {
            return $denied;
        }

        $data = (array) $request->getParsedBody();
        $order = $data['order'] ?? [];
        if (!is_array($order)) {
            $order = [];
        }

        try {
            $this->fachbereiche->reorderSchienen($id, array_map('intval', $order));
            $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
            if ($isAjax) {
                $response->getBody()->write(json_encode(['ok' => true], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json');
            }
            $_SESSION['flash'] = 'Reihenfolge gespeichert.';
        } catch (\Throwable $e) {
            $isAjax = strtolower($request->getHeaderLine('X-Requested-With')) === 'xmlhttprequest';
            if ($isAjax) {
                $response->getBody()->write(json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_THROW_ON_ERROR));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $_SESSION['flash'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/administrator/fachbereiche/' . $id)->withStatus(302);
    }

    public function deleteSchiene(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $denied = $this->requireFachbereichAccess($response, $id);
        if ($denied !== null) {
            return $denied;
        }

        try {
            $this->fachbereiche->deleteSchiene((int) $args['schieneId']);
            $_SESSION['flash'] = 'Schiene gelöscht.';
        } catch (\Throwable $e) {
            $_SESSION['flash'] = $e->getMessage();
        }
        return $response->withHeader('Location', '/administrator/fachbereiche/' . $id)->withStatus(302);
    }

    private function requireFullAdmin(Response $response): ?Response
    {
        if ($this->auth->isFullAdmin()) {
            return null;
        }
        $_SESSION['flash'] = 'Keine Berechtigung für diese Aktion.';
        return $response->withHeader('Location', '/administrator/fachbereiche')->withStatus(302);
    }

    private function requireFachbereichAccess(Response $response, int $fachbereichId): ?Response
    {
        if ($this->auth->canAccessFachbereich($fachbereichId)) {
            return null;
        }
        $_SESSION['flash'] = 'Kein Zugriff auf diesen Fachbereich.';
        return $response->withHeader('Location', '/administrator/fachbereiche')->withStatus(302);
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

    private function normalizeEmail(mixed $value): ?string
    {
        $email = trim((string) ($value ?? ''));
        if ($email === '') {
            return null;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Bitte eine gültige E-Mail-Adresse für den Fachbereich angeben.');
        }
        return strtolower($email);
    }
}
