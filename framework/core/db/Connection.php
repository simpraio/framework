<?php

declare(strict_types=1);

namespace core\db;

use Closure;
use core\config\dto\Database;
use core\Instance;
use PDO;
use Pdo\Mysql;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Connection
{
    private ?PDO $pdo = null;
    private readonly TransactionState $transactions;

    public function __construct(
        private readonly Database $config,
    ) {
        $this->transactions = new TransactionState();
    }

    public function ping(): void
    {
        $this->pdo();
    }

    private function pdo(): PDO
    {
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
     * MySQL-only; acquisition errors and timeouts throw.
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
        $this->transactions->depth === 0
            ? $pdo->beginTransaction()
            : $pdo->exec('SAVEPOINT level_' . $this->transactions->depth);
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

    /**
     * TLS attributes use integer keys, which raw database.options does not preserve.
     *
     * @return array<int, mixed>
     */
    private function tlsOptions(): array
    {
        $ssl = $this->config->ssl;
        if (!$ssl->enabled()) {
            return [];
        }

        $options = [Mysql::ATTR_SSL_VERIFY_SERVER_CERT => $ssl->verifyServerCert];

        if ($ssl->ca !== '') {
            $options[Mysql::ATTR_SSL_CA] = $ssl->ca;
        }
        if ($ssl->cert !== '') {
            $options[Mysql::ATTR_SSL_CERT] = $ssl->cert;
            $options[Mysql::ATTR_SSL_KEY] = $ssl->key;
        }

        return $options;
    }

    private function connect(): PDO
    {
        return new PDO(
            dsn: $this->dsn(),
            username: $this->needsAuth() ? $this->config->username : null,
            password: $this->needsAuth() ? $this->config->password : null,
            options: $this->pdoOptions(),
        );
    }

    /**
     * @return array<int, mixed>
     */
    private function pdoOptions(): array
    {
        $defaults = [
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        $initCommandAttr = null;

        if ($this->config->driver === 'mysql') {
            // Db::update() returns matched rows, including unchanged values.
            $defaults[Mysql::ATTR_FOUND_ROWS] = true;
            $defaults += $this->tlsOptions();

            $initCommandAttr = Mysql::ATTR_INIT_COMMAND;
        }

        /** @var array<int, mixed> $options */
        $options = $this->config->options + $defaults;
        if ($initCommandAttr !== null) {
            // Reserve the init command for the UTC session pin.
            if (array_key_exists($initCommandAttr, $this->config->options)) {
                throw new RuntimeException(
                    'Config: database.options must not set the MySQL init command; it is reserved for '
                    . "pinning the session to UTC (SET time_zone = '+00:00').",
                );
            }

            $options[$initCommandAttr] = "SET time_zone = '+00:00'";
        }
        // Last, so database.options cannot weaken either of them.
        $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
        $options[PDO::ATTR_EMULATE_PREPARES] = false;

        return $options;
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
