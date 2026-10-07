<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class SettingsService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $rows = $this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = (string) ($row['setting_value'] ?? '');
        }
        return $out;
    }

    public function get(string $key, string $default = ''): string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = :k');
        $stmt->execute(['k' => $key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    /** @param array<string, string|null> $values */
    public function setMany(array $values): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach ($values as $key => $value) {
            $stmt->execute(['k' => $key, 'v' => $value ?? '']);
        }
    }

    public function isRegistrationOpen(): bool
    {
        $start = $this->get('registration_start');
        $end = $this->get('registration_end');
        $now = time();

        if ($start !== '') {
            $startTs = strtotime($start);
            if ($startTs !== false && $now < $startTs) {
                return false;
            }
        }
        if ($end !== '') {
            $endTs = strtotime($end);
            if ($endTs !== false && $now > $endTs) {
                return false;
            }
        }
        return true;
    }

    /**
     * Resolved link for footer/banner: external URL wins, else internal path if text exists.
     */
    public function legalHref(string $urlKey, string $textKey, string $internalPath): string
    {
        $url = $this->normalizeLegalUrl($this->get($urlKey));
        if ($url !== '') {
            return $url;
        }
        if (trim($this->get($textKey)) !== '') {
            return $internalPath;
        }
        return '';
    }

    public function normalizeLegalUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) !== 1) {
            return '';
        }
        return $url;
    }

    public function isExternalHref(string $href): bool
    {
        return preg_match('#^https?://#i', $href) === 1;
    }
}
