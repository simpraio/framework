<?php

declare(strict_types=1);

namespace core\db;

use PDO;
use RuntimeException;

final class TransactionState
{
    // Counts callbacks until they unwind, even after the connection is discarded.
    public int $depth = 0;
    private bool $failed = false;

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

    public function leave(): void
    {
        $this->depth--;
        if ($this->depth === 0) {
            $this->failed = false;
        }
    }
}
