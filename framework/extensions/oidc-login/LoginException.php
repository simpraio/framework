<?php

declare(strict_types=1);

namespace extensions\oidc_login;

use RuntimeException;

final class LoginException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $detail = '')
    {
        parent::__construct($detail === '' ? $reason : $reason . ': ' . $detail);
    }
}
