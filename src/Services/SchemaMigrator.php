<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Lightweight schema upgrades for existing installations.
 */
final class SchemaMigrator
{
    public function migrate(PDO $pdo): void
    {
        try {
            $this->ensureAdminRoleColumns($pdo);
        } catch (\Throwable) {
            // Tables may not exist yet (e.g. during setup) – ignore
        }
    }

    private function ensureAdminRoleColumns(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, 'admins') || $this->columnExists($pdo, 'admins', 'role')) {
            return;
        }

        $pdo->exec(
            "ALTER TABLE admins
             ADD COLUMN role ENUM('admin','fachbereich') NOT NULL DEFAULT 'admin' AFTER password_hash"
        );
        $pdo->exec(
            "ALTER TABLE admins
             ADD COLUMN fachbereich_id INT UNSIGNED NULL AFTER role"
        );

        try {
            $pdo->exec(
                "ALTER TABLE admins
                 ADD CONSTRAINT fk_admins_fachbereich
                 FOREIGN KEY (fachbereich_id) REFERENCES fachbereiche(id) ON DELETE RESTRICT"
            );
        } catch (\Throwable) {
            // Constraint may already exist – ignore
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
