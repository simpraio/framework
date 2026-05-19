<?php

declare(strict_types=1);

namespace extensions\mail\transport\smtp;

use RuntimeException;

final class Connection
{
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
        $socket = stream_socket_client(
            address: $config->address(),
            error_code: $errno,
            error_message: $error,
            timeout: $config->timeout,
            context: stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]),
        );

        if (!is_resource($socket)) {
            throw new RuntimeException('SMTP_CONNECT_FAILED: ' . trim(is_string($error) ? $error : ''));
        }

        stream_set_timeout($socket, $config->timeout);

        return new self($config->timeout, $socket);
    }

    public function write(string $data): void
    {
        $socket = $this->stream();

        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $result = fwrite($socket, substr($data, $written));

            if ($result === false || $result === 0) {
                throw new RuntimeException('SMTP_WRITE_FAILED');
            }

            $written += $result;
        }
    }

    public function read(): string
    {
        $socket = $this->stream();

        $response = '';

        while (($line = fgets(stream: $socket, length: 515)) !== false) {
            $response .= $line;

            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        $metadata = stream_get_meta_data($socket);

        if ($metadata['timed_out'] === true) {
            throw new RuntimeException('SMTP_TIMEOUT');
        }

        return $response;
    }

    public function enableTls(): void
    {
        $socket = $this->stream();

        if (!stream_socket_enable_crypto($socket, enable: true, crypto_method: STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            throw new RuntimeException('SMTP_TLS_FAILED');
        }

        stream_set_timeout($socket, $this->timeout);
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
            $this->socket = null;
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
