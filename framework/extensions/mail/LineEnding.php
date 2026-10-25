<?php

declare(strict_types=1);

namespace extensions\mail;

final class LineEnding
{
    /** SMTP and MIME require CRLF; text arrives with LF, CR or CRLF, or a mix of them. */
    public static function crlf(string $text): string
    {
        return str_replace(
            search: "\n",
            replace: "\r\n",
            subject: str_replace(search: ["\r\n", "\r"], replace: "\n", subject: $text),
        );
    }
}
