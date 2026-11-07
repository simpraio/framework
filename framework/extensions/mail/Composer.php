<?php

declare(strict_types=1);

namespace extensions\mail;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final class Composer
{
    private const string EOL = "\r\n";

    /** Cc and Bcc too: SMTP sends no RCPT for them, so a Bcc would only expose its address. */
    private const array RESERVED_HEADERS = [
        'from',
        'to',
        'cc',
        'bcc',
        'subject',
        'mime-version',
        'content-type',
        'content-transfer-encoding',
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

        [$contentHeaders, $body] = $message->attachments === []
            ? self::buildContent($message->html, $message->text)
            : self::buildMixed($message);

        $headers = [
            ...self::customHeaders($message->customHeaders),
            'From' => Header::from($message->fromName ?? $config->fromName, $fromEmail),
            'Date' => new DateTimeImmutable()->format(DATE_RFC2822),
            'Message-ID' => self::messageId($fromEmail),
            'MIME-Version' => '1.0',
            ...$contentHeaders,
        ];

        return new Envelope(
            recipients: $message->recipients,
            subject: Header::encode($message->subject),
            body: $body,
            headers: $headers,
            fromEmail: $fromEmail,
        );
    }

    /**
     * A client renders the last alternative it supports (RFC 2046 5.1.4), so an empty part is
     * never offered: it would be chosen, and the message would display blank.
     *
     * @return array{0: array<string, string>, 1: string} the entity's content headers and its body
     */
    private static function buildContent(string $html, string $text): array
    {
        if ($html === '') {
            return self::buildSinglePart('text/plain', $text);
        }

        return $text === ''
            ? self::buildSinglePart('text/html', $html)
            : self::buildAlternative($html, $text);
    }

    /** @return array{0: array<string, string>, 1: string} */
    private static function buildSinglePart(string $type, string $content): array
    {
        return [
            ['Content-Type' => $type . '; charset=utf-8', 'Content-Transfer-Encoding' => 'base64'],
            self::encodeBody($content),
        ];
    }

    /** @return array{0: array<string, string>, 1: string} */
    private static function buildAlternative(string $html, string $text): array
    {
        $boundary = 'alt-' . bin2hex(random_bytes(16));

        return [
            ['Content-Type' => sprintf('multipart/alternative; boundary="%s"', $boundary)],
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
        ];
    }

    /** @return array{0: array<string, string>, 1: string} */
    private static function buildMixed(Message $message): array
    {
        $mixedBoundary = 'mixed-' . bin2hex(random_bytes(16));
        [$contentHeaders, $content] = self::buildContent($message->html, $message->text);

        $body = implode(self::EOL, [
            '--' . $mixedBoundary,
            ...array_map(
                static fn(string $name, string $value): string => $name . ': ' . $value,
                array_keys($contentHeaders),
                $contentHeaders,
            ),
            '',
            $content,
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

        return [['Content-Type' => sprintf('multipart/mixed; boundary="%s"', $mixedBoundary)], $body];
    }

    private static function resolveFromEmail(?string $messageEmail, string $configEmail): string
    {
        foreach ([$messageEmail !== null ? trim($messageEmail) : '', trim($configEmail)] as $candidate) {
            if (Recipients::isAddress($candidate)) {
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
