<?php

declare(strict_types=1);

namespace App\Services;

final class MailService
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(private array $settings)
    {
    }

    public function send(string $to, string $subject, string $body, ?string $from = null, ?string $fromName = null): bool
    {
        $from ??= (string) ($this->settings['mail']['from'] ?? '');
        $fromName ??= (string) ($this->settings['mail']['from_name'] ?? 'Grundschultag');

        if ($from === '') {
            return false;
        }

        $encodedFrom = sprintf('%s <%s>', $this->encodeHeader($fromName), $from);
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . $encodedFrom,
            'Reply-To: ' . $from,
            'X-Mailer: PHP/' . PHP_VERSION,
        ];

        return @mail(
            $to,
            $this->encodeHeader($subject),
            $body,
            implode("\r\n", $headers)
        );
    }

    public function sendTest(string $to, string $from, string $fromName): bool
    {
        $body = "Dies ist eine Testmail der Grundschultag-Anmeldung.\n\n"
            . "Wenn Sie diese Nachricht lesen, funktioniert der Versand.\n";
        return $this->send($to, 'Testmail – Grundschultag Anmeldung', $body, $from, $fromName);
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
