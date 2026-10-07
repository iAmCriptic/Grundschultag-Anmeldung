<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class AuthService
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_FACHBEREICH = 'fachbereich';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function attempt(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, role, fachbereich_id
             FROM admins WHERE username = :u LIMIT 1'
        );
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }

        $_SESSION['admin_id'] = (int) $row['id'];
        $_SESSION['admin_username'] = (string) $row['username'];
        $_SESSION['admin_role'] = (string) ($row['role'] ?? self::ROLE_ADMIN);
        $_SESSION['admin_fachbereich_id'] = $row['fachbereich_id'] !== null
            ? (int) $row['fachbereich_id']
            : null;

        return true;
    }

    public function logout(): void
    {
        unset(
            $_SESSION['admin_id'],
            $_SESSION['admin_username'],
            $_SESSION['admin_role'],
            $_SESSION['admin_fachbereich_id']
        );
    }

    public function check(): bool
    {
        return isset($_SESSION['admin_id']);
    }

    public function id(): ?int
    {
        return isset($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
    }

    public function username(): ?string
    {
        return $_SESSION['admin_username'] ?? null;
    }

    public function role(): string
    {
        return (string) ($_SESSION['admin_role'] ?? self::ROLE_ADMIN);
    }

    public function isFullAdmin(): bool
    {
        return $this->check() && $this->role() === self::ROLE_ADMIN;
    }

    public function fachbereichId(): ?int
    {
        $id = $_SESSION['admin_fachbereich_id'] ?? null;
        return $id !== null ? (int) $id : null;
    }

    public function canAccessFachbereich(int $fachbereichId): bool
    {
        if (!$this->check()) {
            return false;
        }
        if ($this->isFullAdmin()) {
            return true;
        }
        return $this->fachbereichId() === $fachbereichId;
    }

    public function canManageSettings(): bool
    {
        return $this->isFullAdmin();
    }

    /** @return list<array<string, mixed>> */
    public function listUsers(): array
    {
        return $this->pdo->query(
            'SELECT a.id, a.username, a.role, a.fachbereich_id, a.created_at, f.name AS fachbereich_name
             FROM admins a
             LEFT JOIN fachbereiche f ON f.id = a.fachbereich_id
             ORDER BY a.username ASC'
        )->fetchAll();
    }

    /**
     * @param array{username:string,password:string,role:string,fachbereich_id:?int} $data
     */
    public function createUser(array $data): int
    {
        $username = trim($data['username']);
        $password = $data['password'];
        $role = $data['role'];
        $fachbereichId = $data['fachbereich_id'];

        if ($username === '' || strlen($username) > 100) {
            throw new RuntimeException('Bitte einen gültigen Benutzernamen angeben.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Das Passwort muss mindestens 8 Zeichen haben.');
        }
        if (!in_array($role, [self::ROLE_ADMIN, self::ROLE_FACHBEREICH], true)) {
            throw new RuntimeException('Ungültige Rolle.');
        }
        if ($role === self::ROLE_FACHBEREICH) {
            if ($fachbereichId === null || $fachbereichId < 1) {
                throw new RuntimeException('Bitte einen Fachbereich für diesen Nutzer wählen.');
            }
            $check = $this->pdo->prepare('SELECT id FROM fachbereiche WHERE id = :id');
            $check->execute(['id' => $fachbereichId]);
            if (!$check->fetch()) {
                throw new RuntimeException('Der gewählte Fachbereich existiert nicht.');
            }
        } else {
            $fachbereichId = null;
        }

        $exists = $this->pdo->prepare('SELECT id FROM admins WHERE username = :u');
        $exists->execute(['u' => $username]);
        if ($exists->fetch()) {
            throw new RuntimeException('Dieser Benutzername ist bereits vergeben.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO admins (username, password_hash, role, fachbereich_id)
             VALUES (:u, :p, :role, :fb)'
        );
        $stmt->execute([
            'u' => $username,
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'role' => $role,
            'fb' => $fachbereichId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function deleteUser(int $id): void
    {
        if ($this->id() === $id) {
            throw new RuntimeException('Sie können Ihren eigenen Account nicht löschen.');
        }

        $stmt = $this->pdo->prepare('SELECT id, role FROM admins WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException('Benutzer nicht gefunden.');
        }

        if ($row['role'] === self::ROLE_ADMIN) {
            $adminCount = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM admins WHERE role = 'admin'"
            )->fetchColumn();
            if ($adminCount <= 1) {
                throw new RuntimeException('Der letzte Administrator kann nicht gelöscht werden.');
            }
        }

        $this->pdo->prepare('DELETE FROM admins WHERE id = :id')->execute(['id' => $id]);
    }

    public function countUsersForFachbereich(int $fachbereichId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM admins WHERE fachbereich_id = :id'
        );
        $stmt->execute(['id' => $fachbereichId]);
        return (int) $stmt->fetchColumn();
    }
}
