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
            // A failed commit leaves the transaction in an unknown state, so this rollback can
            // fail too. Its exception must never displace $e, which names what actually broke
            // the write.
            // @mago-expect lint:no-empty-catch-clause -- swallowing is the point: $e is rethrown
            // immediately below, and logging here would pull the log facade into the DB layer to
            // say nothing $e does not.
            try {
                $this->txRollback();
            } catch (Throwable) {
            }
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

    /**
     * Both closers drop the depth BEFORE issuing their statement: the level is closed either way,
     * and a decrement placed after a statement that throws would leave the connection permanently
     * one level deep, nesting every later SAVEPOINT into a transaction that no longer exists.
     */
    private function txCommit(): void
    {
        $pdo = $this->pdo();
        $depth = $this->txDepth;
        $this->txDepth--;

        $depth === 1
            ? $pdo->commit()
            : $pdo->exec('RELEASE SAVEPOINT level_' . ($depth - 1));
    }

    private function txRollback(): void
    {
        // Nothing open - the level was already closed, so there is no savepoint to roll back to
        // and issuing one would only raise a second, misleading error.
        if ($this->txDepth === 0) {
            return;
        }

        $pdo = $this->pdo();
        $depth = $this->txDepth;
        $this->txDepth--;

        $depth === 1
            ? $pdo->rollBack()
            : $pdo->exec('ROLLBACK TO SAVEPOINT level_' . ($depth - 1));
    }

    private function connect(): PDO
    {
        $defaults = [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if ($this->config->driver === 'mysql') {
            // Report matched rows (not just changed rows) from UPDATE rowCount(), so
            // Db::update() can tell "matched but unchanged" (>0) from "no match" (0).
            $foundRowsAttr = defined('Pdo\\Mysql::ATTR_FOUND_ROWS')
                ? (int)constant('Pdo\\Mysql::ATTR_FOUND_ROWS')
                : PDO::MYSQL_ATTR_FOUND_ROWS;
            $defaults[$foundRowsAttr] = true;

            if ($this->config->timezone !== '') {
                $initCommandAttr = defined('Pdo\\Mysql::ATTR_INIT_COMMAND')
                    ? (int)constant('Pdo\\Mysql::ATTR_INIT_COMMAND')
                    : PDO::MYSQL_ATTR_INIT_COMMAND;
                $defaults[$initCommandAttr] =
                    "SET time_zone = '" . self::tzOffset($this->config->timezone) . "'";
            }
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
