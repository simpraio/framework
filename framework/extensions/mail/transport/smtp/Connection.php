<?php

declare(strict_types=1);

namespace extensions\mail\transport\smtp;

use core\tools\Silently;
use RuntimeException;
use SensitiveParameter;

final class Connection
{
    private const array TLS_CONTEXT = [
        'verify_peer' => true,
        'verify_peer_name' => true,
        'allow_self_signed' => false,
    ];

    private mixed $socket;

    private function __construct(
        private readonly int $timeout,
        mixed $socket,
    ) {
        $this->socket = $socket;
    }

    public static function open(Config $config): self
    {
        $errno = 0;
        $error = '';
        // STARTTLS starts on plain TCP, so certificate verification needs an explicit peer name.
        $context = stream_context_create(['ssl' => [...self::TLS_CONTEXT, 'peer_name' => $config->host]]);
        $warning = null;
        // The warning is kept, not raised, so a failure surfaces as SMTP_CONNECT_FAILED under the
        // framework's error handler too. An implicit TLS (ssl) failure leaves $error empty and
        // explains itself only in that warning.
        $socket = self::keepingFirstWarning($warning, static function () use ($config, $context, &$errno, &$error): mixed {
            return stream_socket_client(
                address: $config->address(),
                error_code: $errno,
                error_message: $error,
                timeout: $config->timeout,
                context: $context,
            );
        });

        if (!is_resource($socket)) {
            $error = is_string($error) ? trim($error) : '';
            $detail = trim(self::singleLine($error !== '' ? $error : $warning ?? '') ?? '');
            throw new RuntimeException('SMTP_CONNECT_FAILED' . ($detail === '' ? '' : ': ' . $detail));
        }

        stream_set_timeout($socket, $config->timeout);

        return new self($config->timeout, $socket);
    }

    /**
     * Commands and credentials alike, so the data stays out of traces: a failed write is reported
     * by fwrite's return value, never by a notice that would carry it.
     */
    public function write(#[SensitiveParameter] string $data): void
    {
        $socket = $this->stream();

        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $result = Silently::call(static fn(): int|false => fwrite($socket, substr($data, $written)));

            if ($result === false || $result === 0) {
                throw new RuntimeException('SMTP_WRITE_FAILED');
            }

            $written += $result;
        }
    }

    /** The socket is non-blocking while Reply reads, so the timeout can bound the whole reply. */
    public function read(): string
    {
        $socket = $this->stream();

        if (!stream_set_blocking($socket, enable: false)) {
            throw new RuntimeException('SMTP_READ_FAILED');
        }

        try {
            return Reply::read($socket, $this->timeout);
        } finally {
            stream_set_blocking($socket, enable: true);
        }
    }

    public function enableTls(): void
    {
        $socket = $this->stream();

        // Bytes already buffered arrived in plaintext; read after the handshake, they would pass
        // for the server's protected reply (STARTTLS response injection).
        if (stream_get_meta_data($socket)['unread_bytes'] > 0) {
            throw new RuntimeException('SMTP_TLS_FAILED: unread plaintext after STARTTLS reply');
        }

        $reason = null;
        $upgraded = self::keepingFirstWarning($reason, static fn(): bool|int => stream_socket_enable_crypto(
            $socket,
            enable: true,
            crypto_method: STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ));

        if ($upgraded !== true) {
            $reason ??= stream_get_meta_data($socket)['timed_out'] ? 'TLS handshake timed out' : 'TLS handshake did not complete';
            throw new RuntimeException('SMTP_TLS_FAILED: ' . (self::singleLine($reason) ?? 'TLS handshake failed'));
        }

        stream_set_timeout($socket, $this->timeout);
    }

    /**
     * Only for connecting and the handshake: their warnings carry the TLS diagnostic, never SMTP
     * commands or credentials. The request's handler is restored even when $operation throws.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private static function keepingFirstWarning(?string &$warning, callable $operation): mixed
    {
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning ??= $message;
            return true;
        });
        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }

    /** Bounded and on one line, so a diagnostic cannot flood or forge a log entry. */
    private static function singleLine(string $reason): ?string
    {
        return preg_replace('/[\x00-\x1F\x7F]+/', replacement: ' ', subject: substr($reason, offset: 0, length: 512));
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            $socket = $this->socket;
            $this->socket = null;
            // The connection is discarded either way, and a notice here must not fail a send the
            // server has already accepted.
            Silently::call(static fn(): bool => fclose($socket));
        }
    }

    /** @return resource */
    private function stream(): mixed
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP_NOT_CONNECTED');
        }

        return $this->socket;
    }
}
