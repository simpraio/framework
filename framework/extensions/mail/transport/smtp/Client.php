<?php

declare(strict_types=1);

namespace extensions\mail\transport\smtp;

use extensions\mail\Envelope;
use RuntimeException;

final readonly class Client
{
    private string $helloHost;

    private function __construct(
        private Config $config,
        private Connection $connection,
    ) {
        $host = gethostname();
        $this->helloHost = is_string($host) ? $host : 'localhost';
    }

    public static function connect(Config $config): self
    {
        $connection = Connection::open($config);
        $response = $connection->read();
        self::expect($response, 220);

        return new self($config, $connection);
    }

    public function hello(): void
    {
        try {
            $this->command('EHLO ' . $this->helloHost, 250);
        } catch (RuntimeException $e) {
            if (!str_starts_with($e->getMessage(), 'SMTP:')) {
                throw $e;
            }

            $this->command('HELO ' . $this->helloHost, 250);
        }
    }

    public function startTlsIfNeeded(): void
    {
        if ($this->config->encryption !== SmtpEncryption::Tls) {
            return;
        }

        $this->command('STARTTLS', 220);
        $this->connection->enableTls();
        $this->hello();
    }

    public function authenticateIfNeeded(): void
    {
        if (!$this->config->auth) {
            return;
        }

        if ($this->config->encryption === SmtpEncryption::None) {
            throw new RuntimeException('SMTP_AUTH_REQUIRES_ENCRYPTION');
        }

        if ($this->config->username === '' || $this->config->password === '') {
            throw new RuntimeException('SMTP_AUTH_CREDENTIALS_MISSING');
        }

        $this->command('AUTH LOGIN', 334);
        $this->command(base64_encode($this->config->username), 334);
        $this->command(base64_encode($this->config->password), 235);
    }

    public function send(Envelope $envelope): void
    {
        $from = self::extractAddress($envelope->headers()['From'] ?? '');

        if ($from === '') {
            throw new RuntimeException('SMTP_FROM_MISSING');
        }

        $this->command('MAIL FROM:<' . $from . '>', 250);

        foreach ($envelope->recipients() as $recipient) {
            $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
        }

        $this->command('DATA', 354);

        $this->connection->write(self::buildPayload($envelope) . "\r\n.\r\n");
        self::expect($this->connection->read(), 250);
    }

    public function quit(): void
    {
        $this->command('QUIT', 221);
    }

    public function close(): void
    {
        $this->connection->close();
    }

    private function command(string $command, int|array $expected): void
    {
        $this->connection->write($command . "\r\n");
        self::expect($this->connection->read(), $expected);
    }

    private static function expect(string $response, int|array $expected): void
    {
        if ($response === '') {
            throw new RuntimeException('SMTP_EMPTY_RESPONSE');
        }

        if (preg_match('/^\d{3}[ -]/', $response) !== 1) {
            throw new RuntimeException('SMTP_INVALID_RESPONSE');
        }

        $code = (int)substr(string: $response, offset: 0, length: 3);

        if (!in_array($code, (array)$expected, strict: true)) {
            throw new RuntimeException('SMTP: ' . trim($response));
        }
    }

    private static function buildPayload(Envelope $envelope): string
    {
        $eol = "\r\n";
        $headers = $envelope->headersBlock(['Subject', 'To']);
        $sep = $headers !== '' ? $eol : '';

        return $headers . $sep
            . 'Subject: ' . $envelope->subject . $eol
            . 'To: ' . $envelope->recipientsLine() . $eol
            . $eol
            . self::dotStuff(self::normalizeEol($envelope->body));
    }

    private static function normalizeEol(string $body): string
    {
        return str_replace(
            search: "\n",
            replace: "\r\n",
            subject: str_replace(search: ["\r\n", "\r"],
                replace: "\n",
                subject: $body)
        );
    }

    private static function dotStuff(string $body): string
    {
        return (string)preg_replace(pattern: '/^\./m', replacement: '..', subject: $body);
    }

    private static function extractAddress(string $header): string
    {
        $match = [];

        return preg_match('/<([^>]+)>/', $header, $match) === 1 ? $match[1] : trim($header);
    }
}
