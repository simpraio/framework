<?php

declare(strict_types=1);

namespace core\db;

use core\Log;
use RuntimeException;
use Throwable;

/**
 * The Db::lock() callbacks running on a connection. Their locks belong to the server session, so
 * once that session is lost a new connection would let the callbacks carry on without them.
 */
final class LockState
{
    private int $held = 0;
    private bool $lost = false;

    /**
     * GET_LOCK sends nothing back while it waits, so a longer wait is cut off by the read timeout
     * as a lost connection; a negative timeout would wait without limit.
     */
    public function assertTimeout(int $timeout, int $readTimeout): void
    {
        $limit = $readTimeout - 1;
        if ($timeout < 0 || $timeout > $limit) {
            throw new RuntimeException(
                "Db::lock timeout must be 0 to {$limit} seconds, below database.read_timeout; got {$timeout}",
            );
        }
    }

    public function enter(): void
    {
        $this->held++;
    }

    public function leave(): void
    {
        $this->held--;
        if ($this->held === 0) {
            $this->lost = false;
        }
    }

    public function connectionLost(): void
    {
        $this->lost = $this->held > 0;
    }

    public function assertIntact(): void
    {
        if ($this->lost) {
            throw new RuntimeException('Database connection was lost while Db::lock() held its lock');
        }
    }

    /**
     * A release that finds the connection lost cannot tell when the session ended: a KILL or an idle
     * timeout during the callback dropped the lock while the callback still ran. The warning must not
     * replace the callback's result or exception, so a log that cannot be written is ignored.
     */
    public function releaseFoundConnectionLost(string $name): void
    {
        // @mago-expect lint:no-empty-catch-clause -- logging may be unavailable and must not fail Db::lock().
        try {
            Log::warning('Db::lock() found its connection lost at release: exclusivity may have lapsed while the callback ran', [
                'lock' => $name,
            ]);
        } catch (Throwable) {
        }
    }
}
