<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class FachbereichService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $onlyActive = false): array
    {
        $sql = 'SELECT * FROM fachbereiche';
        if ($onlyActive) {
            $sql .= ' WHERE aktiv = 1';
        }
        $sql .= ' ORDER BY sortierung ASC, name ASC';
        return $this->pdo->query($sql)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM fachbereiche WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function schienenFor(int $fachbereichId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM anmeldungen a WHERE a.schiene_id = s.id AND a.status = \'aktiv\') AS belegt
             FROM schienen s
             WHERE s.fachbereich_id = :id
             ORDER BY s.sortierung ASC, s.name ASC'
        );
        $stmt->execute(['id' => $fachbereichId]);
        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function findSchiene(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, f.name AS fachbereich_name, f.aktiv AS fachbereich_aktiv,
                    (SELECT COUNT(*) FROM anmeldungen a WHERE a.schiene_id = s.id AND a.status = \'aktiv\') AS belegt
             FROM schienen s
             INNER JOIN fachbereiche f ON f.id = s.fachbereich_id
             WHERE s.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @param array{name:string,teaser_text:?string,teaser_bild:?string,aktiv:int,sortierung:int} $data */
    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO fachbereiche (name, teaser_text, teaser_bild, aktiv, sortierung)
             VALUES (:name, :teaser_text, :teaser_bild, :aktiv, :sortierung)'
        );
        $stmt->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    /** @param array{name:string,teaser_text:?string,teaser_bild:?string,aktiv:int,sortierung:int} $data */
    public function update(int $id, array $data): void
    {
        $data['id'] = $id;
        $stmt = $this->pdo->prepare(
            'UPDATE fachbereiche
             SET name = :name, teaser_text = :teaser_text, teaser_bild = :teaser_bild,
                 aktiv = :aktiv, sortierung = :sortierung
             WHERE id = :id'
        );
        $stmt->execute($data);
    }

    public function toggle(int $id): void
    {
        $this->pdo->prepare('UPDATE fachbereiche SET aktiv = IF(aktiv = 1, 0, 1) WHERE id = :id')
            ->execute(['id' => $id]);
    }

    public function delete(int $id): void
    {
        $check = $this->pdo->prepare(
            'SELECT COUNT(*) FROM anmeldungen a
             INNER JOIN schienen s ON s.id = a.schiene_id
             WHERE s.fachbereich_id = :id AND a.status = \'aktiv\''
        );
        $check->execute(['id' => $id]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Fachbereich hat noch aktive Anmeldungen und kann nicht gelöscht werden.');
        }
        $this->pdo->prepare('DELETE FROM fachbereiche WHERE id = :id')->execute(['id' => $id]);
    }

    public function addSchiene(int $fachbereichId, string $name, int $kapazitaet, int $sortierung = 0): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO schienen (fachbereich_id, name, kapazitaet, sortierung)
             VALUES (:fb, :name, :kap, :sort)'
        );
        $stmt->execute([
            'fb' => $fachbereichId,
            'name' => $name,
            'kap' => max(1, $kapazitaet),
            'sort' => $sortierung,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateSchiene(int $schieneId, string $name, int $kapazitaet, int $sortierung = 0): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE schienen SET name = :name, kapazitaet = :kap, sortierung = :sort WHERE id = :id'
        );
        $stmt->execute([
            'id' => $schieneId,
            'name' => $name,
            'kap' => max(1, $kapazitaet),
            'sort' => $sortierung,
        ]);
    }

    public function deleteSchiene(int $schieneId): void
    {
        $check = $this->pdo->prepare(
            'SELECT COUNT(*) FROM anmeldungen WHERE schiene_id = :id AND status = \'aktiv\''
        );
        $check->execute(['id' => $schieneId]);
        if ((int) $check->fetchColumn() > 0) {
            throw new RuntimeException('Schiene hat noch aktive Anmeldungen und kann nicht gelöscht werden.');
        }
        $this->pdo->prepare('DELETE FROM schienen WHERE id = :id')->execute(['id' => $schieneId]);
    }

    /** @return list<array<string, mixed>> */
    public function withStats(): array
    {
        $list = $this->all(false);
        foreach ($list as &$fb) {
            $fb['schienen'] = $this->schienenFor((int) $fb['id']);
        }
        return $list;
    }
}
