<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class ExportService
{
    public function __construct(private readonly PDO $pdo)
    {
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

    public function csvForFachbereich(int $fachbereichId): string
    {
        $rows = $this->rowsForFachbereich($fachbereichId);
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return '';
        }
        // UTF-8 BOM for Excel
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, ['Name', 'E-Mail', 'Schiene'], ';');
        foreach ($rows as $row) {
            fputcsv($fh, [$row['name'], $row['email'], $row['schiene']], ';');
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);
        return $csv;
    }
}
