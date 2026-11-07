<?php

declare(strict_types=1);

namespace core\db;

use PDO;
use PDOException;
use RuntimeException;

final class TransactionState
{
    /**
     * MySQL errors after which the connection is gone: lost (2006, 2013), killed (1927), or closed
     * by the server for idling past wait_timeout (4031, which MySQL 8.0.24+ sends where older
     * servers and MariaDB leave the client to report 2006 or 2013).
     */
    private const array CONNECTION_LOST = [1927, 2006, 2013, 4031];

    /**
     * MySQL errors after which no part of the transaction may be committed. InnoDB rolls the whole
     * transaction back on a deadlock (1213) or an exhausted lock table (1206); after a lock wait
     * timeout (1205) or a MariaDB snapshot-isolation conflict (1020) it is rolled back whether or not
     * the server did; a lost or killed connection takes it along.
     */
    private const array ENDING_ERRORS = [1020, 1205, 1206, 1213, ...self::CONNECTION_LOST];

    // Counts callbacks until they unwind, even after the connection is discarded.
    public int $depth = 0;
    private bool $failed = false;

    // The error codes are MySQL's; another driver may use the same numbers for something else.
    public function __construct(
        private readonly bool $mysql,
    ) {
    }

    public function assertActive(?PDO $pdo): void
    {
        if ($this->depth > 0 && $pdo !== null && !$pdo->inTransaction()) {
            $this->failed = true;
        }
        if ($this->failed) {
            throw new RuntimeException('Database transaction is aborted');
        }
    }

    public function abort(): void
    {
        $this->failed = true;
    }

    /**
     * Aborts the open transaction when a MySQL statement error ended it, and says whether it did.
     * The error reply carries no transaction status, so PDO still reports the transaction open,
     * and a callback that swallowed the error would run its remaining statements in autocommit.
     * Any other error undoes only its own statement and leaves the transaction usable.
     */
    public function abortOn(PDOException $e): bool
    {
        if ($this->depth === 0 || !$this->mysql || !in_array($e->errorInfo[1] ?? null, self::ENDING_ERRORS, strict: true)) {
            return false;
        }
        $this->failed = true;

        return true;
    }

    public function lostConnection(PDOException $e): bool
    {
        return $this->mysql && in_array($e->errorInfo[1] ?? null, self::CONNECTION_LOST, strict: true);
    }

    public function leave(): void
    {
        $this->depth--;
        if ($this->depth === 0) {
            $this->failed = false;
        }
    }
}
