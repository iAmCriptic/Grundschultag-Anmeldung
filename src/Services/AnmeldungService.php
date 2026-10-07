<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

final class AnmeldungService
{
    /**
     * @param array<string, mixed> $appSettings
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SettingsService $settings,
        private readonly MailService $mail,
        private readonly array $appSettings
    ) {
    }

    /**
     * @param array{name:string,email:string,schiene_id:int} $data
     * @param bool $allowWhenClosed Admin-Testmodus darf trotz geschlossenem Zeitraum anmelden
     * @return array<string, mixed>
     */
    public function register(array $data, bool $allowWhenClosed = false): array
    {
        if (!$allowWhenClosed && !$this->settings->isRegistrationOpen()) {
            throw new RuntimeException('Die Anmeldung ist derzeit geschlossen.');
        }

        $name = trim($data['name']);
        $email = trim($data['email']);
        $schieneId = (int) $data['schiene_id'];

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Bitte Name und gültige E-Mail-Adresse angeben.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT s.*, f.name AS fachbereich_name, f.aktiv AS fachbereich_aktiv
                 FROM schienen s
                 INNER JOIN fachbereiche f ON f.id = s.fachbereich_id
                 WHERE s.id = :id
                 FOR UPDATE'
            );
            $stmt->execute(['id' => $schieneId]);
            $schiene = $stmt->fetch();
            if (!$schiene || !(int) $schiene['fachbereich_aktiv']) {
                throw new RuntimeException('Die gewählte Schiene ist nicht verfügbar.');
            }

            $countStmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM anmeldungen WHERE schiene_id = :id AND status = \'aktiv\''
            );
            $countStmt->execute(['id' => $schieneId]);
            $belegt = (int) $countStmt->fetchColumn();
            if ($belegt >= (int) $schiene['kapazitaet']) {
                throw new RuntimeException('Diese Schiene ist leider ausgebucht.');
            }

            $token = bin2hex(random_bytes(32));
            $insert = $this->pdo->prepare(
                'INSERT INTO anmeldungen (schiene_id, name, email, token, status)
                 VALUES (:sid, :name, :email, :token, \'aktiv\')'
            );
            $insert->execute([
                'sid' => $schieneId,
                'name' => $name,
                'email' => $email,
                'token' => $token,
            ]);
            $id = (int) $this->pdo->lastInsertId();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $anmeldung = $this->findByToken($token);
        if ($anmeldung === null) {
            throw new RuntimeException('Anmeldung konnte nicht geladen werden.');
        }

        $this->sendConfirmationMail($anmeldung);
        return $anmeldung;
    }

    /** @return array<string, mixed>|null */
    public function findByToken(string $token): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*, s.name AS schiene_name, s.kapazitaet,
                    f.name AS fachbereich_name, f.id AS fachbereich_id
             FROM anmeldungen a
             INNER JOIN schienen s ON s.id = a.schiene_id
             INNER JOIN fachbereiche f ON f.id = s.fachbereich_id
             WHERE a.token = :token'
        );
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function cancel(string $token): void
    {
        $anmeldung = $this->findByToken($token);
        if ($anmeldung === null) {
            throw new RuntimeException('Anmeldung nicht gefunden.');
        }
        if ($anmeldung['status'] === 'storniert') {
            return;
        }
        $stmt = $this->pdo->prepare(
            'UPDATE anmeldungen SET status = \'storniert\', cancelled_at = NOW() WHERE token = :token AND status = \'aktiv\''
        );
        $stmt->execute(['token' => $token]);
    }

    public function countActive(?int $fachbereichId = null): int
    {
        if ($fachbereichId === null) {
            return (int) $this->pdo->query(
                "SELECT COUNT(*) FROM anmeldungen WHERE status = 'aktiv'"
            )->fetchColumn();
        }

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM anmeldungen a
             INNER JOIN schienen s ON s.id = a.schiene_id
             WHERE a.status = 'aktiv' AND s.fachbereich_id = :fb"
        );
        $stmt->execute(['fb' => $fachbereichId]);
        return (int) $stmt->fetchColumn();
    }

    public function countAll(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM anmeldungen')->fetchColumn();
    }

    public function deleteAll(): int
    {
        $count = $this->countAll();
        $this->pdo->exec('DELETE FROM anmeldungen');
        return $count;
    }

    /** @param array<string, mixed> $anmeldung */
    private function sendConfirmationMail(array $anmeldung): void
    {
        $baseUrl = rtrim((string) ($this->appSettings['app_url'] ?? ''), '/');
        if ($baseUrl === '') {
            $baseUrl = $this->guessBaseUrl();
        }
        $cancelUrl = $baseUrl . '/stornieren/' . $anmeldung['token'];
        $thanksUrl = $baseUrl . '/danke/' . $anmeldung['token'];

        $subject = $this->settings->get(
            'mail_subject',
            (string) ($this->appSettings['mail']['subject'] ?? 'Ihre Anmeldung zum Grundschultag')
        );
        $from = $this->settings->get('mail_from', (string) ($this->appSettings['mail']['from'] ?? ''));
        $fromName = $this->settings->get(
            'mail_from_name',
            (string) ($this->appSettings['mail']['from_name'] ?? 'Grundschultag')
        );

        $body = "Hallo {$anmeldung['name']},\n\n"
            . "vielen Dank für Ihre Anmeldung zum Grundschultag.\n\n"
            . "Fachbereich: {$anmeldung['fachbereich_name']}\n"
            . "Schiene: {$anmeldung['schiene_name']}\n"
            . "E-Mail: {$anmeldung['email']}\n\n"
            . "Bestätigung anzeigen: {$thanksUrl}\n\n"
            . "Falls Sie die Anmeldung stornieren möchten:\n{$cancelUrl}\n\n"
            . "Freundliche Grüße\n"
            . $fromName . "\n";

        if ($from !== '') {
            $this->mail->send((string) $anmeldung['email'], $subject, $body, $from, $fromName);
        }
    }

    private function guessBaseUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['SERVER_PORT'] ?? null) == 443);
        $scheme = $https ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $scheme . '://' . $host;
    }
}
