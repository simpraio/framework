<?php

declare(strict_types=1);

namespace core\config\dto;

use core\config\Cast;
use core\config\RedactsSecrets;
use JsonSerializable;
use RuntimeException;
use SensitiveParameter;

final readonly class Database implements JsonSerializable
{
    use RedactsSecrets;

    /** @return list<string> */
    protected function secretKeys(): array
    {
        return ['password'];
    }

    /** @param array<int|string, mixed> $options */
    public function __construct(
        public string $driver,
        public string $hostname,
        public int $port,
        public string $database,
        public string $username,
        #[SensitiveParameter]
        public string $password,
        public string $charset,
        public array $options,
        public DatabaseSsl $ssl = new DatabaseSsl(),
        public int $connectTimeout = 10,
        public int $readTimeout = 300,
    ) {
    }

    /** @param array<string, mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $options = self::settings($raw, 'options');
        $ssl = self::settings($raw, 'ssl');
        $driver = Cast::string($raw['driver'] ?? null, 'database.driver');
        $hostname = Cast::string($raw['hostname'] ?? null, 'database.hostname');
        $name = Cast::string($raw['database'] ?? null, 'database.database');
        $username = Cast::string($raw['username'] ?? null, 'database.username');
        $blank = array_keys(array_filter(
            ['database.hostname' => $hostname, 'database.database' => $name, 'database.username' => $username],
            static fn(string $value): bool => trim($value) === '',
        ));
        if ($driver === 'mysql' && $blank !== []) {
            throw new RuntimeException(sprintf(
                'Config: %s must not be blank for the mysql driver; an empty hostname is read as localhost, the local '
                . 'socket, and an empty database or username connects without one.',
                implode(', ', $blank),
            ));
        }
        $port = Cast::int($raw['port'] ?? null, 'database.port');
        // On Unix-like systems mysqlnd takes the local socket for "localhost" and ignores the port, so
        // a port other than the default would silently reach whatever server listens on that socket.
        // On Windows it connects to localhost over TCP and uses the port.
        if (
            $driver === 'mysql'
            && PHP_OS_FAMILY !== 'Windows'
            && strtolower($hostname) === 'localhost'
            && !in_array($port, [0, 3306], strict: true)
        ) {
            throw new RuntimeException(
                "Config: database.port {$port} is ignored for hostname 'localhost', which on Unix-like systems "
                . 'connects through the local socket; use 127.0.0.1 to reach a TCP port, or leave database.port at 3306.',
            );
        }

        return new self(
            driver: $driver,
            hostname: $hostname,
            port: $port,
            database: $name,
            username: $username,
            password: Cast::string($raw['password'] ?? null, 'database.password'),
            charset: Cast::string($raw['charset'] ?? null, 'database.charset', 'utf8mb4'),
            options: $options,
            ssl: DatabaseSsl::fromArray($ssl),
            connectTimeout: self::seconds($raw['connect_timeout'] ?? null, 'database.connect_timeout', 300, 10),
            readTimeout: self::seconds($raw['read_timeout'] ?? null, 'database.read_timeout', 86_400, 300),
        );
    }

    /**
     * Absent, null and [] hold no settings. Anything else that is no map would be read as none, and
     * the connection sent without the TLS the operator believes is on. An integer key, such as a PDO
     * attribute constant, would never reach the connection either, so it is refused rather than dropped.
     *
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private static function settings(array $raw, string $key): array
    {
        /** @var mixed $value */
        $value = $raw[$key] ?? null;
        if ($value === null) {
            return [];
        }

        $problem = match (true) {
            !is_array($value) => get_debug_type($value),
            array_any(array_keys($value), static fn(int|string $name): bool => !is_string($name))
                => 'an array with integer keys. A list is no map, and integer-keyed PDO constants are not carried '
                . 'to the connection: remove them',
            default => null,
        };
        if ($problem !== null) {
            throw new RuntimeException("Config: 'database.{$key}' must be a map of settings, or [] for none; got {$problem}.");
        }

        /** @var array<string, mixed> */
        return $value;
    }

    private static function seconds(mixed $value, string $field, int $max, int $default): int
    {
        $seconds = Cast::int($value, $field, $default);
        if ($seconds < 1 || $seconds > $max) {
            throw new RuntimeException("Config: '{$field}' must be 1 to {$max} seconds, got {$seconds}.");
        }

        return $seconds;
    }
}
