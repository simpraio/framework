<?php

declare(strict_types=1);

namespace extensions\mail\transport\smtp;

use extensions\mail\Envelope;
use extensions\mail\LineEnding;
use RuntimeException;
use SensitiveParameter;
use Throwable;

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
        $this->credential(base64_encode($this->config->username), 334);
        $this->credential(base64_encode($this->config->password), 235);
    }

    /** Kept out of stack traces: a failed AUTH would otherwise log the start of the secret. */
    private function credential(#[SensitiveParameter] string $line, int $expected): void
    {
        $this->connection->write($line . "\r\n");
        self::expect($this->connection->read(), $expected);
    }

    public function send(Envelope $envelope): void
    {
        if ($envelope->fromEmail === null) {
            throw new RuntimeException('SMTP_FROM_MISSING');
        }

        // Headers are ASCII-encoded; SMTPUTF8 is not negotiated.
        $this->command('MAIL FROM:<' . $envelope->fromEmail . '>', 250);

        foreach ($envelope->recipients() as $recipient) {
            $this->command('RCPT TO:<' . $recipient . '>', [250, 251]);
        }

        $this->command('DATA', 354);

        $this->connection->write(self::buildPayload($envelope) . "\r\n.\r\n");
        $this->expectDataVerdict();
    }

    /**
     * With the terminator written the server may already have committed the message, so anything
     * but its own reply leaves the delivery unknown rather than failed: a caller retrying it could
     * deliver the message twice. For the same reason any 2xx counts as accepted, though RFC 5321
     * answers the data with 250.
     */
    private function expectDataVerdict(): void
    {
        try {
            self::expect($this->connection->read(), range(start: 200, end: 299));
        } catch (Throwable $e) {
            if (str_starts_with($e->getMessage(), 'SMTP:')) {
                throw $e;
            }

            throw new RuntimeException('SMTP_DELIVERY_UNKNOWN: ' . $e->getMessage(), previous: $e);
        }
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

        if (preg_match('/^\d{3}([ -]|\r?\n)/', $response) !== 1) {
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

        // RFC 5321 4.5.2: transparency covers every line of DATA, headers included.
        return self::dotStuff(
            $headers . $sep
            . 'Subject: ' . $envelope->subject . $eol
            . 'To: ' . $envelope->recipientsLine() . $eol
            . $eol
            . LineEnding::crlf($envelope->body),
        );
    }

    private static function dotStuff(string $data): string
    {
        return (string)preg_replace(pattern: '/^\./m', replacement: '..', subject: $data);
    }

}
