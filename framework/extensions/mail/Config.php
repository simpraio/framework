<?php

declare(strict_types=1);

namespace extensions\mail;

use core\config\Cast;
use core\config\Config as CoreConfig;
use RuntimeException;

final readonly class Config
{
    public const string NAME = 'mail';
    public const string DISABLED = 'MAIL_DISABLED';

    public function __construct(
        public bool $enabled,
        public string $transport,
        public string $fromEmail,
        public string $fromName,
        public int $maxRecipients = 100,
        public int $maxMessageBytes = 26_214_400,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        return new self(
            enabled:   Cast::bool($raw['enabled'] ?? null, 'extensions.mail.enabled', false),
            transport: strtolower(Cast::trimmedString($raw['transport'] ?? null, 'extensions.mail.transport', 'smtp')),
            fromEmail: Cast::trimmedString($raw['from_email'] ?? null, 'extensions.mail.from_email'),
            fromName:  Cast::trimmedString($raw['from_name'] ?? null, 'extensions.mail.from_name'),
            maxRecipients: self::limit($raw['max_recipients'] ?? null, 'extensions.mail.max_recipients', 1, 1_000, 100),
            maxMessageBytes: self::limit(
                $raw['max_message_bytes'] ?? null,
                'extensions.mail.max_message_bytes',
                1_024,
                268_435_456,
                26_214_400,
            ),
        );
    }

    private static function limit(mixed $value, string $field, int $min, int $max, int $default): int
    {
        $limit = Cast::int($value, $field, $default);
        if ($limit < $min || $limit > $max) {
            throw new RuntimeException("Config: '{$field}' must be {$min} to {$max}, got {$limit}.");
        }

        return $limit;
    }

    public static function enabled(): self
    {
        return CoreConfig::enabledExtension(self::NAME, self::class, self::DISABLED);
    }
}
