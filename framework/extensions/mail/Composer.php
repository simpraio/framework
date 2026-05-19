<?php

declare(strict_types=1);

namespace extensions\mail;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class Composer
{
    private const string EOL = "\r\n";

    private const array RESERVED_HEADERS = [
        'from',
        'to',
        'subject',
        'mime-version',
        'content-type',
        'date',
        'message-id',
    ];

    public static function build(Message $message, Config $config): Envelope
    {
        if ($message->recipients === []) {
            throw new InvalidArgumentException('NO_RECIPIENT');
        }

        [$body, $contentType] = $message->attachments === []
            ? self::buildAlternative($message->html, $message->text)
            : self::buildMixed($message);

        $headers = [
            ...self::customHeaders($message->customHeaders),
            'From' => self::fromHeader($message, $config),
            'Date' => new DateTimeImmutable()->format(DATE_RFC2822),
            'Message-ID' => self::messageId($message, $config),
            'MIME-Version' => '1.0',
            'Content-Type' => $contentType,
        ];

        return new Envelope(
            recipients: $message->recipients,
            subject: self::encodeHeader($message->subject),
            body: $body,
            headers: $headers,
        );
    }

    /** @return array{0: string, 1: string} */
    private static function buildAlternative(string $html, string $text): array
    {
        $boundary = 'alt-' . bin2hex(random_bytes(16));

        return [
            implode(self::EOL, [
                '--' . $boundary,
                'Content-Type: text/plain; charset=utf-8',
                'Content-Transfer-Encoding: base64',
                '',
                self::encodeBody($text),
                '--' . $boundary,
                'Content-Type: text/html; charset=utf-8',
                'Content-Transfer-Encoding: base64',
                '',
                self::encodeBody($html),
                '--' . $boundary . '--',
            ]),
            sprintf('multipart/alternative; boundary="%s"', $boundary),
        ];
    }

    /** @return array{0: string, 1: string} */
    private static function buildMixed(Message $message): array
    {
        $mixedBoundary = 'mixed-' . bin2hex(random_bytes(16));
        $altBoundary = 'alt-' . bin2hex(random_bytes(16));

        $body = implode(self::EOL, [
            '--' . $mixedBoundary,
            sprintf('Content-Type: multipart/alternative; boundary="%s"', $altBoundary),
            '',
            '--' . $altBoundary,
            'Content-Type: text/plain; charset=utf-8',
            'Content-Transfer-Encoding: base64',
            '',
            self::encodeBody($message->text),
            '--' . $altBoundary,
            'Content-Type: text/html; charset=utf-8',
            'Content-Transfer-Encoding: base64',
            '',
            self::encodeBody($message->html),
            '--' . $altBoundary . '--',
        ]);

        foreach ($message->attachments as $att) {
            $body .= self::EOL . implode(self::EOL, [
                    '--' . $mixedBoundary,
                    sprintf(
                        'Content-Type: %s; name="%s"; name*=UTF-8\'\'%s',
                        $att['mimeType'],
                        $att['filename'],
                        $att['encodedFilename'],
                    ),
                    'Content-Transfer-Encoding: base64',
                    sprintf(
                        'Content-Disposition: attachment; filename="%s"; filename*=UTF-8\'\'%s',
                        $att['filename'],
                        $att['encodedFilename'],
                    ),
                    '',
                    chunk_split(base64_encode($att['data']), length: 76, separator: self::EOL),
                ]);
        }

        $body .= self::EOL . '--' . $mixedBoundary . '--';

        return [$body, sprintf('multipart/mixed; boundary="%s"', $mixedBoundary)];
    }

    private static function fromHeader(Message $message, Config $config): string
    {
        $email = self::resolveFromEmail($message->fromEmail, $config->fromEmail);
        $name = (string)preg_replace(
            pattern: '/[\r\n]+/',
            replacement: ' ',
            subject: trim(
            $message->fromName ?? $config->fromName
        )
        );

        return $name !== ''
            ? self::encodeHeader($name) . ' <' . $email . '>'
            : $email;
    }

    private static function resolveFromEmail(?string $messageEmail, string $configEmail): string
    {
        foreach ([$messageEmail !== null ? trim($messageEmail) : '', trim($configEmail)] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        throw new RuntimeException('MAIL_FROM_MISSING');
    }

    private static function messageId(Message $message, Config $config): string
    {
        $email = $message->fromEmail ?? $config->fromEmail;
        $at = strrpos(haystack: $email, needle: '@');
        $domain = $at !== false ? substr(string: $email, offset: $at + 1) : 'localhost.localdomain';

        return sprintf('<%s@%s>', bin2hex(random_bytes(16)), $domain);
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function customHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $name = trim($name);
            $value = trim($value);

            if (
                $name === ''
                || $value === ''
                || preg_match('/[\r\n]/', $name . $value) === 1
                || in_array(strtolower($name), self::RESERVED_HEADERS, strict: true)
            ) {
                continue;
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    private static function encodeHeader(string $value): string
    {
        $normalized = (string)preg_replace(pattern: '/[\r\n]+/', replacement: ' ', subject: $value);

        return preg_match('/[^\x20-\x7E]/', $normalized) === 1
            ? sprintf('=?UTF-8?B?%s?=', base64_encode($normalized))
            : $normalized;
    }

    private static function encodeBody(string $body): string
    {
        $body = str_replace(
            search: "\n",
            replace: self::EOL,
            subject: str_replace(search: ["\r\n", "\r"],
            replace: "\n",
            subject: $body)
        );

        return chunk_split(base64_encode($body), length: 76, separator: self::EOL);
    }
}
