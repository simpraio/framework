<?php

declare(strict_types=1);

namespace extensions\mail;

final readonly class Envelope
{
    private string $recipientsLine;

    /**
     * @param list<string> $recipients
     * @param array<string, string> $headers
     */
    public function __construct(
        private array $recipients,
        public string $subject,
        public string $body,
        private array $headers,
    ) {
        $this->recipientsLine = implode(', ', $recipients);
    }

    /** @return list<string> */
    public function recipients(): array
    {
        return $this->recipients;
    }

    public function recipientsLine(): string
    {
        return $this->recipientsLine;
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    /** @param list<string> $except */
    public function headersBlock(array $except = []): string
    {
        $lines = [];

        foreach ($this->headers as $name => $value) {
            if (in_array($name, $except, strict: true)) {
                continue;
            }

            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines);
    }
}
