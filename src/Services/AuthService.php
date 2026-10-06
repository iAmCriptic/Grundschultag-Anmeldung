<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class AuthService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function attempt(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM admins WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($password, $row['password_hash'])) {
            return false;
        }
        $_SESSION['admin_id'] = (int) $row['id'];
        $_SESSION['admin_username'] = $username;
        return true;
    }

    public function logout(): void
    {
        unset($_SESSION['admin_id'], $_SESSION['admin_username']);
    }

    public function check(): bool
    {
        return isset($_SESSION['admin_id']);
    }

    public function username(): ?string
    {
        return $_SESSION['admin_username'] ?? null;
    }
}
