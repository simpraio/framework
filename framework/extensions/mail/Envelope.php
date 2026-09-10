<?php

declare(strict_types=1);

namespace extensions\mail;

use InvalidArgumentException;

final readonly class Envelope
{
    private string $recipientsLine;

    /** @var list<string> */
    private array $recipients;

    /** @var array<string, string> */
    private array $headers;

    public ?string $fromEmail;

    /**
     * @param list<string> $recipients
     * @param array<string, string> $headers
     * @param string|null $fromEmail explicit SMTP envelope sender. When omitted, a valid final
     *                              address in the From header is used.
     */
    public function __construct(
        array $recipients,
        public string $subject,
        public string $body,
        array $headers,
        ?string $fromEmail = null,
    ) {
        $normalized = Recipients::normalize($recipients);
        $sender = $fromEmail !== null ? trim($fromEmail) : self::senderFromHeader($headers);

        if ($sender !== null && filter_var($sender, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('INVALID_ENVELOPE_SENDER');
        }

        HeaderRules::assertRenderable('Subject', $subject);

        foreach ($headers as $name => $value) {
            HeaderRules::assertName($name);
            HeaderRules::assertRenderable($name, $value);
        }

        $this->recipients = $normalized;
        $this->headers = $headers;
        $this->fromEmail = $sender;
        $this->recipientsLine = Recipients::fold($normalized);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return $this->recipients;
    }

    /**
     * The addresses as a `To:` header value, folded between addresses so no line passes
     * Header::MAX_LINE_BYTES.
     *
     * Both transports want this form: the SMTP client writes the header itself, and mail() writes
     * "To: <this>" verbatim and preserves the folding.
     */
    public function recipientsLine(): string
    {
        return $this->recipientsLine;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @param list<string> $except header names to omit, compared case-insensitively */
    public function headersBlock(array $except = []): string
    {
        $omit = array_map(strtolower(...), $except);
        $lines = [];

        foreach ($this->headers as $name => $value) {
            if (in_array(strtolower($name), $omit, strict: true)) {
                continue;
            }

            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines);
    }

    /** @param array<string, string> $headers */
    private static function senderFromHeader(array $headers): ?string
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $value = trim($headers['from'] ?? '');

        if ($value === '') {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
            return $value;
        }

        $match = [];

        if (
            preg_match('/<([^<>\r\n]+)>\s*$/D', $value, $match) === 1
            && filter_var(trim($match[1]), FILTER_VALIDATE_EMAIL) !== false
        ) {
            return trim($match[1]);
        }

        return null;
    }
}
