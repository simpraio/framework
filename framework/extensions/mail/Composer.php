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

        // Message-ID must derive from the validated sender to prevent header injection.
        $fromEmail = self::resolveFromEmail($message->fromEmail, $config->fromEmail);

        [$body, $contentType] = $message->attachments === []
            ? self::buildAlternative($message->html, $message->text)
            : self::buildMixed($message);

        $headers = [
            ...self::customHeaders($message->customHeaders),
            'From' => Header::from($message->fromName ?? $config->fromName, $fromEmail),
            'Date' => new DateTimeImmutable()->format(DATE_RFC2822),
            'Message-ID' => self::messageId($fromEmail),
            'MIME-Version' => '1.0',
            'Content-Type' => $contentType,
        ];

        return new Envelope(
            recipients: $message->recipients,
            subject: Header::encode($message->subject),
            body: $body,
            headers: $headers,
            fromEmail: $fromEmail,
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
                Header::attachment(
                    'Content-Type: ' . $att['mimeType'],
                    'name',
                    $att['filename'],
                    $att['encodedFilename'],
                ),
                'Content-Transfer-Encoding: base64',
                Header::attachment(
                    'Content-Disposition: attachment',
                    'filename',
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

    private static function resolveFromEmail(?string $messageEmail, string $configEmail): string
    {
        foreach ([$messageEmail !== null ? trim($messageEmail) : '', trim($configEmail)] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        throw new RuntimeException('MAIL_FROM_MISSING');
    }

    /**
     * @param string $fromEmail a validated address; its domain is inserted into a header.
     */
    private static function messageId(string $fromEmail): string
    {
        $domain = substr(string: $fromEmail, offset: (int)strrpos(haystack: $fromEmail, needle: '@') + 1);

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
            // Validate before trimming so edge-positioned controls cannot disappear.
            $name = trim($name, characters: " \t");

            if (
                $name === ''
                || $value === ''
            // Caller-supplied headers cannot contain folding or injected line breaks.
                || preg_match('/[\r\n]/', $name . $value) === 1
                || !HeaderRules::nameIsValid($name)
                || !HeaderRules::isRenderable($name, $value)
                || in_array(strtolower($name), self::RESERVED_HEADERS, strict: true)
            ) {
                continue;
            }

            $value = trim($value, characters: " \t");

            if ($value === '') {
                continue;
            }

            $normalized[$name] = $value;
        }

        return $normalized;
    }

    private static function encodeBody(string $body): string
    {
        return chunk_split(base64_encode(LineEnding::crlf($body)), length: 76, separator: self::EOL);
    }
}
