<?php

declare(strict_types=1);

namespace extensions\mail\transport\smtp;

use core\tools\Silently;
use RuntimeException;

/**
 * Reads one SMTP reply within a size and a time budget. A blocking read restarts its idle timeout
 * on every byte, so a server trickling one would hold it forever; this waits on a deadline instead.
 */
final class Reply
{
    /** Far above any legitimate reply; bounds what a server streaming continuation lines costs. */
    private const int MAX_BYTES = 65_536;

    /** @param resource $socket a non-blocking stream */
    public static function read(mixed $socket, int $timeout): string
    {
        $deadline = (int)hrtime(true) + $timeout * 1_000_000_000;
        $response = '';
        $line = '';

        while (($chunk = self::chunk($socket, $deadline)) !== false) {
            $response .= $chunk;
            $line .= $chunk;

            if (strlen($response) > self::MAX_BYTES) {
                throw new RuntimeException('SMTP_RESPONSE_TOO_LARGE');
            }

            // A chunk can be part of a line; only a line's first bytes can mark it final.
            if (!str_ends_with($line, "\n")) {
                continue;
            }

            // RFC 5321 4.2: "NNN-" continues a reply; the final line is "NNN text" or a bare "NNN".
            if (in_array($line[3] ?? '', [' ', "\r", "\n"], strict: true)) {
                break;
            }

            $line = '';
        }

        return $response;
    }

    /**
     * @param resource $socket
     * @return string|false false once the server has closed the connection
     */
    private static function chunk(mixed $socket, int $deadline): string|false
    {
        while (true) {
            $remaining = intdiv(num1: $deadline - (int)hrtime(true), num2: 1000);

            if ($remaining <= 0) {
                throw new RuntimeException('SMTP_TIMEOUT');
            }

            $read = [$socket];
            $write = null;
            $except = null;
            $ready = Silently::call(static fn(): int|false => stream_select(
                $read,
                $write,
                $except,
                seconds: 0,
                microseconds: min(999_999, $remaining),
            ));

            if ($ready === false) {
                throw new RuntimeException('SMTP_READ_FAILED');
            }

            $chunk = fgets(stream: $socket, length: 515);

            if ($chunk !== false || feof($socket)) {
                return $chunk;
            }
        }
    }
}
