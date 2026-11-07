<?php

declare(strict_types=1);

namespace core\db;

use core\config\dto\Database;
use PDO;
use Pdo\Mysql;
use RuntimeException;

/**
 * Opens the PDO connection database.* describes.
 */
final readonly class Connector
{
    public function __construct(
        private Database $config,
    ) {
    }

    public function open(): PDO
    {
        if ($this->config->driver !== 'mysql') {
            return $this->connect();
        }

        // mysqlnd has no attribute for its read timeout: it copies this ini into each connection as
        // the connection is created, and the ini's default of a day would hold the worker that long
        // on a server that stopped answering.
        $previous = ini_set('mysqlnd.net_read_timeout', (string)$this->config->readTimeout);
        try {
            return $this->connect();
        } finally {
            if ($previous !== false) {
                ini_set('mysqlnd.net_read_timeout', $previous);
            }
        }
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
            $defaults[PDO::ATTR_TIMEOUT] = $this->config->connectTimeout;
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
