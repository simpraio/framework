<?php

declare(strict_types=1);

namespace core\http;

use RuntimeException;
use Throwable;

final class HttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        string $message = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : self::defaultMessage($status), 0, $previous);
    }

    public function publicMessage(): string
    {
        return self::defaultMessage($this->status);
    }

    private static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            500 => 'Internal Server Error',
            default => "HTTP {$status}",
        };
    }
}
