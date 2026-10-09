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

    public function send(
        string $to,
        string $subject,
        string $body,
        ?string $from = null,
        ?string $fromName = null,
        bool $isHtml = false
    ): bool {
        $from ??= (string) ($this->settings['mail']['from'] ?? '');
        $fromName ??= (string) ($this->settings['mail']['from_name'] ?? 'Grundschultag');

        if ($from === '') {
            return false;
        }

        $encodedFrom = sprintf('%s <%s>', $this->encodeHeader($fromName), $from);
        $headers = [
            'MIME-Version: 1.0',
            'From: ' . $encodedFrom,
            'Reply-To: ' . $from,
            'X-Mailer: PHP/' . PHP_VERSION,
        ];

        if ($isHtml) {
            $boundary = 'b_' . bin2hex(random_bytes(12));
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';
            $plain = $this->htmlToPlainText($body);
            $payload = "--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $plain . "\r\n\r\n"
                . "--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $this->wrapHtmlDocument($body) . "\r\n\r\n"
                . "--{$boundary}--\r\n";
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $payload = $body;
        }

        return @mail(
            $to,
            $this->encodeHeader($subject),
            $payload,
            implode("\r\n", $headers)
        );
    }

    public function sendTest(string $to, string $from, string $fromName): bool
    {
        $body = "Dies ist eine Testmail der Grundschultag-Anmeldung.\n\n"
            . "Wenn Sie diese Nachricht lesen, funktioniert der Versand.\n";
        return $this->send($to, 'Testmail – Grundschultag Anmeldung', $body, $from, $fromName);
    }

    private function wrapHtmlDocument(string $bodyHtml): string
    {
        return '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>E-Mail</title></head><body style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;'
            . 'font-size:16px;line-height:1.5;color:#222;">'
            . $bodyHtml
            . '</body></html>';
    }

    private function htmlToPlainText(string $html): string
    {
        $withBreaks = preg_replace(
            ['#<(br|BR)\s*/?>#', '#</(p|div|h[1-6]|li|tr)\s*>#i', '#<(li)\b[^>]*>#i'],
            ["\n", "\n", "• "],
            $html
        ) ?? $html;
        $text = html_entity_decode(strip_tags($withBreaks), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text) . "\n";
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
