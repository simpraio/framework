<?php

declare(strict_types=1);

namespace extensions\mail;

use core\config\Cast;
use core\config\Config as CoreConfig;

final readonly class Config
{
    public const string NAME = 'mail';
    public const string DISABLED = 'MAIL_DISABLED';

    public function __construct(
        public bool $enabled,
        public string $transport,
        public string $fromEmail,
        public string $fromName,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled:   Cast::bool($raw['enabled'] ?? null, 'extensions.mail.enabled', true),
            transport: strtolower(trim(Cast::string($raw['transport'] ?? null, 'extensions.mail.transport', 'smtp'))),
            fromEmail: trim(Cast::string($raw['from_email'] ?? null, 'extensions.mail.from_email')),
            fromName:  trim(Cast::string($raw['from_name'] ?? null, 'extensions.mail.from_name')),
        );
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
