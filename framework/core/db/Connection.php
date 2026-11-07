<?php

declare(strict_types=1);

namespace core\db;

use Closure;
use core\config\dto\Database;
use core\tools\Identifier;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Connection
{
    private ?PDO $pdo = null;
    private readonly TransactionState $transactions;
    private readonly LockState $locks;

    public function __construct(
        private readonly Database $config,
    ) {
        $this->transactions = new TransactionState(mysql: $config->driver === 'mysql');
        $this->locks = new LockState();
    }

    public function ping(): void
    {
        $this->pdo();
    }

    private function pdo(): PDO
    {
        $this->locks->assertIntact();
        try {
            $this->transactions->assertActive($this->pdo);
        } catch (Throwable $e) {
            $this->pdo = null;
            $this->transactions->abort();
            throw $e;
        }

        return $this->pdo ??= $this->connect();
    }

    /** @param array<int|string, mixed> $params */
    public function query(string $sql, array $params = []): PDOStatement
    {
        return $this->run($this->pdo(), $sql, $params);
    }

    /**
     * Runs a statement on $pdo without the checks pdo() makes, which releaseLock() must skip.
     *
     * @param array<int|string, mixed> $params
     */
    private function run(PDO $pdo, string $sql, array $params): PDOStatement
    {
        try {
            $stmt = $pdo->prepare($sql);
            if ($stmt === false) {
                [$sqlState, $code, $message] = $pdo->errorInfo() + [null, null, null];
                throw new RuntimeException("Failed to prepare statement [{$sqlState} {$code}]: {$message} | SQL: {$sql}");
            }
            $stmt->execute(array_map(self::bindable(...), $params));
        } catch (PDOException $e) {
            $this->failed($pdo, $e);
            throw $e;
        }
        return $stmt;
    }

    /**
     * Aborts the transaction an error ended, and drops a connection it lost, so that the next call
     * opens a new one instead of failing on the dead one for the rest of the process. A new
     * connection would run without the open transaction or the held Db::lock(), so until those
     * callbacks have unwound every call is refused instead.
     */
    private function failed(PDO $pdo, PDOException $e): void
    {
        if ($this->transactions->abortOn($e)) {
            $this->rollBackAborted($pdo);
        }
        if ($this->transactions->lostConnection($e)) {
            $this->pdo = null;
            $this->locks->connectionLost();
        }
    }

    /**
     * Ends the aborted transaction on the server too, which a lock wait timeout can leave open
     * with the work done before it.
     */
    private function rollBackAborted(PDO $pdo): void
    {
        try {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable) {
            $this->pdo = null;
        }
    }

    /**
     * execute() binds a float as a string written with the `precision` ini, 14 digits by default;
     * var_export() writes the shortest form that reads back as the same float.
     */
    private static function bindable(mixed $value): mixed
    {
        return is_float($value) ? var_export($value, return: true) : $value;
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
     * MySQL-only; acquisition errors and timeouts throw.
     *
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public function lock(string $name, int $timeout, Closure $callback): mixed
    {
        $this->locks->assertTimeout($timeout, $this->config->readTimeout);
        $name = $this->lockName($name);

        if ($this->pdo()->getAttribute(PDO::ATTR_PERSISTENT)) {
            throw new RuntimeException('Db::lock cannot be used with persistent connections');
        }
        $row = $this->row('SELECT GET_LOCK(?, ?) AS `got`', [$name, $timeout]);
        if ((int)($row['got'] ?? 0) !== 1) {
            throw new RuntimeException("Failed to acquire lock: {$name}");
        }
        $this->locks->enter();
        try {
            return $callback();
        } finally {
            $this->locks->leave();
            $this->releaseLock($name);
        }
    }

    /**
     * The lock belongs to the session, not to a transaction, so it is released even while an
     * aborted transaction refuses every other statement. Without a connection it is already gone,
     * so a release that loses the connection has nothing left to release, only a warning to log.
     */
    private function releaseLock(string $name): void
    {
        $pdo = $this->pdo;
        if ($pdo === null) {
            return;
        }
        try {
            $this->run($pdo, 'DO RELEASE_LOCK(?)', [$name]);
        } catch (PDOException $e) {
            if (!$this->transactions->lostConnection($e)) {
                throw $e;
            }
            $this->locks->releaseFoundConnectionLost($name);
        }
    }

    /**
     * GET_LOCK names are server-wide: keyed on the database, so every process of one application takes
     * the same lock whatever path its code runs from, and another database's application does not.
     */
    private function lockName(string $name): string
    {
        return Identifier::fastHash($this->config->database) . ':' . $name;
    }

    /**
     * Nested calls use savepoints, not independent transactions.
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
            // @mago-expect lint:no-empty-catch-clause -- preserve the original failure if rollback also fails.
            try {
                $this->txRollback();
            } catch (Throwable) {
            }
            throw $e;
        } finally {
            $this->transactions->leave();
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
        try {
            $this->transactions->depth === 0
                ? $pdo->beginTransaction()
                : $pdo->exec('SAVEPOINT level_' . $this->transactions->depth);
        } catch (PDOException $e) {
            $this->failed($pdo, $e);
            throw $e;
        }
        $this->transactions->depth++;
    }

    private function txCommit(): void
    {
        $pdo = $this->pdo();
        $depth = $this->transactions->depth;

        $depth === 1
            ? $pdo->commit()
            : $pdo->exec('RELEASE SAVEPOINT level_' . ($depth - 1));
    }

    private function txRollback(): void
    {
        $this->transactions->assertActive(null);
        $pdo = $this->pdo ?? throw new RuntimeException('Database transaction is aborted');
        $depth = $this->transactions->depth;

        try {
            if ($depth > 1) {
                $pdo->exec('ROLLBACK TO SAVEPOINT level_' . ($depth - 1));
                return;
            }

            // Only while one is open: a backend may have closed it already, on a deadlock
            // or a failed commit, and asking again raises a second error over the first.
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            // Never reconnect while an enclosing transaction callback can still run.
            $this->pdo = null;
            $this->transactions->abort();

            throw $e;
        }
    }

    private function connect(): PDO
    {
        return (new Connector($this->config))->open();
    }
}
