<?php

declare(strict_types=1);

namespace core;

use Closure;
use core\db\Connection;
use core\http\Request as HttpRequest;
use core\http\Router;
use core\log\Writer as LogWriter;
use core\session\Store as SessionStore;
use RuntimeException;

final class Container
{
    /** @var array<string, Closure(self): mixed> */
    private array $bindings = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    public function bind(string $id, Closure $factory): void
    {
        $this->bindings[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function instance(string $id, mixed $value): void
    {
        $this->instances[$id] = $value;
    }

    public function get(string $id): mixed
    {
        if (array_key_exists($id, $this->instances)) {
            return $this->instances[$id];
        }

        if (array_key_exists($id, $this->bindings)) {
            return $this->instances[$id] = ($this->bindings[$id])($this);
        }

        throw new RuntimeException("Class not found: {$id}");
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->instances)
            || array_key_exists($id, $this->bindings);
    }

    public function db(): Connection
    {
        /** @var Connection */
        return $this->get(Connection::class);
    }

    public function session(): SessionStore
    {
        /** @var SessionStore */
        return $this->get(SessionStore::class);
    }

    public function log(): LogWriter
    {
        /** @var LogWriter */
        return $this->get(LogWriter::class);
    }

    public function request(): HttpRequest
    {
        /** @var HttpRequest */
        return $this->get(HttpRequest::class);
    }

    public function router(): Router
    {
        /** @var Router */
        return $this->get(Router::class);
    }

    public function view(): View
    {
        /** @var View */
        return $this->get(View::class);
    }

    public function paths(): Paths
    {
        /** @var Paths */
        return $this->get(Paths::class);
    }

    public function extensions(): Extensions
    {
        /** @var Extensions */
        return $this->get(Extensions::class);
    }
}
