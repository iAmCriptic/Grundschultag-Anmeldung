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
    public function all(bool $onlyActive = false, ?int $onlyId = null): array
    {
        $sql = 'SELECT * FROM fachbereiche WHERE 1=1';
        $params = [];
        if ($onlyActive) {
            $sql .= ' AND aktiv = 1';
        }
        if ($onlyId !== null) {
            $sql .= ' AND id = :id';
            $params['id'] = $onlyId;
        }
        $sql .= ' ORDER BY sortierung ASC, name ASC';
        if ($params === []) {
            return $this->pdo->query($sql)->fetchAll();
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
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

    public function nextSortierung(): int
    {
        return (int) $this->pdo->query('SELECT COALESCE(MAX(sortierung), -1) FROM fachbereiche')->fetchColumn() + 1;
    }

    /**
     * @param array{
     *     name:string,
     *     teaser_text:?string,
     *     teaser_bild:?string,
     *     email:?string,
     *     aktiv:int,
     *     sortierung?:int
     * } $data
     */
    public function create(array $data): int
    {
        if (!isset($data['sortierung'])) {
            $data['sortierung'] = $this->nextSortierung();
        }
        if (!array_key_exists('email', $data)) {
            $data['email'] = null;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO fachbereiche (name, teaser_text, teaser_bild, email, aktiv, sortierung)
             VALUES (:name, :teaser_text, :teaser_bild, :email, :aktiv, :sortierung)'
        );
        $stmt->execute($data);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{
     *     name:string,
     *     teaser_text:?string,
     *     teaser_bild:?string,
     *     email:?string,
     *     aktiv:int,
     *     sortierung:int
     * } $data
     */
    public function update(int $id, array $data): void
    {
        $data['id'] = $id;
        if (!array_key_exists('email', $data)) {
            $data['email'] = null;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE fachbereiche
             SET name = :name, teaser_text = :teaser_text, teaser_bild = :teaser_bild,
                 email = :email, aktiv = :aktiv, sortierung = :sortierung
             WHERE id = :id'
        );
        $stmt->execute($data);
    }

    public function toggle(int $id): void
    {
        $this->pdo->prepare('UPDATE fachbereiche SET aktiv = IF(aktiv = 1, 0, 1) WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * @param list<int> $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $existing = $this->all();
        $existingIds = array_map(static fn (array $row): int => (int) $row['id'], $existing);
        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));

        if ($orderedIds === [] || count($orderedIds) !== count($existingIds)) {
            throw new RuntimeException('Ungültige Fachbereich-Reihenfolge.');
        }
        foreach ($orderedIds as $id) {
            if (!in_array($id, $existingIds, true)) {
                throw new RuntimeException('Ungültige Fachbereich-Reihenfolge.');
            }
        }

        $stmt = $this->pdo->prepare('UPDATE fachbereiche SET sortierung = :sort WHERE id = :id');
        foreach ($orderedIds as $index => $id) {
            $stmt->execute([
                'sort' => $index,
                'id' => $id,
            ]);
        }
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

        $users = $this->pdo->prepare('SELECT COUNT(*) FROM admins WHERE fachbereich_id = :id');
        $users->execute(['id' => $id]);
        if ((int) $users->fetchColumn() > 0) {
            throw new RuntimeException('Fachbereich hat zugewiesene Benutzer und kann nicht gelöscht werden.');
        }

        $this->pdo->prepare('DELETE FROM fachbereiche WHERE id = :id')->execute(['id' => $id]);
    }

    public function addSchiene(int $fachbereichId, string $name, int $kapazitaet, ?int $sortierung = null): int
    {
        if ($sortierung === null) {
            $maxStmt = $this->pdo->prepare(
                'SELECT COALESCE(MAX(sortierung), -1) FROM schienen WHERE fachbereich_id = :fb'
            );
            $maxStmt->execute(['fb' => $fachbereichId]);
            $sortierung = (int) $maxStmt->fetchColumn() + 1;
        }

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

    public function updateSchiene(int $schieneId, string $name, int $kapazitaet): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE schienen SET name = :name, kapazitaet = :kap WHERE id = :id'
        );
        $stmt->execute([
            'id' => $schieneId,
            'name' => $name,
            'kap' => max(1, $kapazitaet),
        ]);
    }

    /**
     * @param list<int> $orderedIds
     */
    public function reorderSchienen(int $fachbereichId, array $orderedIds): void
    {
        $existing = $this->schienenFor($fachbereichId);
        $existingIds = array_map(static fn (array $row): int => (int) $row['id'], $existing);
        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));

        if ($orderedIds === [] || count($orderedIds) !== count($existingIds)) {
            throw new RuntimeException('Ungültige Schienen-Reihenfolge.');
        }
        foreach ($orderedIds as $id) {
            if (!in_array($id, $existingIds, true)) {
                throw new RuntimeException('Ungültige Schienen-Reihenfolge.');
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE schienen SET sortierung = :sort WHERE id = :id AND fachbereich_id = :fb'
        );
        foreach ($orderedIds as $index => $id) {
            $stmt->execute([
                'sort' => $index,
                'id' => $id,
                'fb' => $fachbereichId,
            ]);
        }
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
    public function withStats(?int $onlyId = null): array
    {
        $list = $this->all(false, $onlyId);
        foreach ($list as &$fb) {
            $fb['schienen'] = $this->schienenFor((int) $fb['id']);
        }
        return $list;
    }
}
