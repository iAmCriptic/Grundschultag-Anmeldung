<?php

declare(strict_types=1);

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use PDO;

final class ExportService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $uploadDir
    ) {
    }

    /** @return list<array<string, string>> */
    public function rowsForFachbereich(int $fachbereichId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.name, a.email, s.name AS schiene
             FROM anmeldungen a
             INNER JOIN schienen s ON s.id = a.schiene_id
             WHERE s.fachbereich_id = :id AND a.status = \'aktiv\'
             ORDER BY s.sortierung ASC, s.name ASC, a.name ASC'
        );
        $stmt->execute(['id' => $fachbereichId]);
        return $stmt->fetchAll();
    }

    /** @return list<array<string, string>> */
    public function rowsForSchiene(int $schieneId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.name, a.email
             FROM anmeldungen a
             WHERE a.schiene_id = :id AND a.status = \'aktiv\'
             ORDER BY a.name ASC'
        );
        $stmt->execute(['id' => $schieneId]);
        return $stmt->fetchAll();
    }

    public function pdfForSchiene(
        string $fachbereichName,
        string $schieneName,
        int $schieneId,
        ?string $logoFilename = null
    ): string {
        $rows = $this->rowsForSchiene($schieneId);
        $safeFb = htmlspecialchars($fachbereichName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeSchiene = htmlspecialchars($schieneName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $datum = htmlspecialchars((new \DateTimeImmutable('now'))->format('d.m.Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $logoHtml = $this->logoImgHtml($logoFilename);

        $bodyRows = '';
        foreach ($rows as $row) {
            $bodyRows .= '<tr>'
                . '<td>' . htmlspecialchars((string) $row['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '<td>' . htmlspecialchars((string) $row['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>'
                . '</tr>';
        }
        if ($bodyRows === '') {
            $bodyRows = '<tr><td colspan="2">Keine aktiven Anmeldungen.</td></tr>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #222; }
    .header { margin-bottom: 18px; }
    .logo { max-height: 64px; max-width: 160px; margin-bottom: 10px; }
    h1 { font-size: 20px; margin: 0 0 12px; }
    .meta { margin: 0 0 16px; line-height: 1.55; }
    .meta strong { display: inline-block; min-width: 7rem; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #333; padding: 6px 8px; text-align: left; vertical-align: top; }
    th { background: #e8eee9; }
</style>
</head>
<body>
    <div class="header">
        {$logoHtml}
        <h1>Teilnehmerliste</h1>
        <p class="meta">
            <strong>Fachbereich:</strong> {$safeFb}<br>
            <strong>Schiene:</strong> {$safeSchiene}<br>
            <strong>Datum:</strong> {$datum}
        </p>
    </div>
    <table>
        <thead>
            <tr><th>Name</th><th>E-Mail</th></tr>
        </thead>
        <tbody>
            {$bodyRows}
        </tbody>
    </table>
</body>
</html>
HTML;

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output() ?? '';
    }

    private function logoImgHtml(?string $logoFilename): string
    {
        if ($logoFilename === null || $logoFilename === '') {
            return '';
        }
        $path = $this->uploadDir . '/' . basename($logoFilename);
        if (!is_file($path)) {
            return '';
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path) ?: '';
        if (!str_starts_with($mime, 'image/')) {
            return '';
        }

        // Dompdf benötigt GD für PNG; JPEG einbetten, falls möglich.
        $payload = $this->logoAsJpegDataUri($path, $mime);
        if ($payload === null) {
            return '';
        }

        return '<img class="logo" src="' . $payload . '" alt="">';
    }

    private function logoAsJpegDataUri(string $path, string $mime): ?string
    {
        if (!extension_loaded('gd')) {
            // Ohne GD: JPEG/GIF roh einbetten; PNG überspringen (sonst Absturz in Dompdf).
            if ($mime === 'image/jpeg' || $mime === 'image/gif') {
                $data = file_get_contents($path);
                return $data === false ? null : 'data:' . $mime . ';base64,' . base64_encode($data);
            }
            return null;
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            imagedestroy($image);
            return null;
        }
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagecopy($canvas, $image, 0, 0, 0, 0, $width, $height);
        imagedestroy($image);

        ob_start();
        imagejpeg($canvas, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($canvas);
        if ($jpeg === false || $jpeg === '') {
            return null;
        }

        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }
}
