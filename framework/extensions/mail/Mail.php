<?php

declare(strict_types=1);

namespace extensions\mail;

use core\config\Config as CoreConfig;
use extensions\mail\transport\Native;
use extensions\mail\transport\Smtp;
use extensions\mail\transport\smtp\Config as SmtpConfig;
use RuntimeException;

final class Mail
{
    public static function message(): Message
    {
        Config::enabled();
        return new Message();
    }

    public static function send(Message $message): void
    {
        $config = Config::enabled();
        $raw = CoreConfig::extension(Config::NAME);
        $envelope = Composer::build($message, $config);

        self::transport($config, $raw)->deliver($envelope);
    }

    /** @param array<string, mixed> $raw */
    private static function transport(Config $config, array $raw): MailTransport
    {
        return match ($config->transport) {
            'smtp' => new Smtp(SmtpConfig::fromArray($raw)),
            'native' => new Native(),
            default => throw new RuntimeException('INVALID_MAIL_TRANSPORT: ' . $config->transport),
        };
    }
}
