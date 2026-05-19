<?php

declare(strict_types=1);

namespace core\db;

use Closure;
use core\config\dto\Database;
use core\Instance;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Connection
{
    private ?PDO $pdo = null;
    private int $txDepth = 0;

    public function __construct(
        private readonly Database $config,
    ) {
    }

    /** Trigger lazy connection without exposing the underlying PDO. */
    public function ping(): void
    {
        $this->pdo();
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /** @param array<int|string, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $pdo = $this->pdo();
        $stmt = $pdo->prepare($sql);
        if ($stmt === false) {
            [$sqlState, $code, $message] = $pdo->errorInfo() + [null, null, null];
            throw new RuntimeException("Failed to prepare statement [{$sqlState} {$code}]: {$message} | SQL: {$sql}");
        }
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function row(string $sql, array $params = []): ?array
    {
        /** @var array<string, mixed>|false $row */
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        /** @var list<array<string, mixed>> */
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param array<int|string, mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Acquires a MySQL named lock, runs the callback, releases the lock.
     * Throws on acquisition failure (timeout or error). MySQL only.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function lock(string $name, int $timeout, Closure $callback): mixed
    {
        $name = $this->lockName($name);

        if ($this->pdo()->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new RuntimeException('Db::lock cannot be used with persistent connections');
        }
        $row = $this->row('SELECT GET_LOCK(?, ?) AS `got`', [$name, $timeout]);
        if ((int)($row['got'] ?? 0) !== 1) {
            throw new RuntimeException("Failed to acquire lock: {$name}");
        }
        try {
            return $callback();
        } finally {
            $this->execute('DO RELEASE_LOCK(?)', [$name]);
        }
    }

    private function lockName(string $name): string
    {
        return Instance::prefix() . $name;
    }

    /**
     * Wraps the callback in a transaction. Nested calls use SAVEPOINTs so
     * an inner failure rolls back only the nested work, leaving the outer
     * transaction intact unless the exception propagates further.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed
    {
        $this->txBegin();
        try {
            $result = $callback();
            $this->txCommit();
        } catch (Throwable $e) {
            $this->txRollback();
            throw $e;
        }
        return $result;
    }

    public function lastInsertId(): string
    {
        $id = $this->pdo()->lastInsertId();
        return $id === false ? '' : $id;
    }

    private function txBegin(): void
    {
        $pdo = $this->pdo();
        $this->txDepth === 0
            ? $pdo->beginTransaction()
            : $pdo->exec("SAVEPOINT level_{$this->txDepth}");
        $this->txDepth++;
    }

    private function txCommit(): void
    {
        $pdo = $this->pdo();
        $this->txDepth === 1
            ? $pdo->commit()
            : $pdo->exec('RELEASE SAVEPOINT level_' . ($this->txDepth - 1));
        $this->txDepth--;
    }

    private function txRollback(): void
    {
        $pdo = $this->pdo();
        $this->txDepth === 1
            ? $pdo->rollBack()
            : $pdo->exec('ROLLBACK TO SAVEPOINT level_' . ($this->txDepth - 1));
        $this->txDepth--;
    }

    private function connect(): PDO
    {
        $defaults = [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($this->config->driver === 'mysql' && $this->config->timezone !== '') {
            $defaults[PDO::MYSQL_ATTR_INIT_COMMAND] =
                "SET time_zone = '" . self::tzOffset($this->config->timezone) . "'";
        }

        /** @var array<int, mixed> $options */
        $options = $this->config->options + $defaults;
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_EMULATE_PREPARES] = false;

        return new PDO(
            dsn: $this->dsn(),
            username: $this->needsAuth() ? $this->config->username : null,
            password: $this->needsAuth() ? $this->config->password : null,
            options: $options,
        );
    }

    private static function tzOffset(string $timezone): string
    {
        return new DateTimeImmutable('now', new DateTimeZone($timezone))->format('P');
    }

    private function dsn(): string
    {
        $c = $this->config;

        if ($c->driver === 'sqlite') {
            return 'sqlite:' . $c->database;
        }

        return sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $c->driver,
            $c->hostname,
            $c->port,
            $c->database,
            $c->charset,
        );
    }

    private function needsAuth(): bool
    {
        return $this->config->driver !== 'sqlite';
    }
}
