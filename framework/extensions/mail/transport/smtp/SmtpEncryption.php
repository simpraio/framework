<?php

declare(strict_types=1);

namespace extensions\mail\transport\smtp;

enum SmtpEncryption: string
{
    case None = 'none';
    case Ssl  = 'ssl';
    case Tls  = 'tls';
}
