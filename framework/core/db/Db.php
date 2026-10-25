<?php

declare(strict_types=1);

namespace core\db;

use Closure;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Db
{
    /** @var Closure(): Connection */
    private static Closure $resolver;
    private static ?Connection $connection = null;

    /** @param Connection|Closure(): Connection $connection */
    public static function init(Connection|Closure $connection): void
    {
        if ($connection instanceof Connection) {
            self::$connection = $connection;
            self::$resolver = static fn(): Connection => $connection;
            return;
        }

        self::$connection = null;
        self::$resolver = $connection;
    }

    public static function ping(): void
    {
        self::connection()->ping();
    }

    /** @param array<int|string, mixed> $params */
    public static function query(string $sql, array $params = []): PDOStatement
    {
        return self::connection()->query($sql, $params);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public static function row(string $sql, array $params = []): ?array
    {
        return self::connection()->row($sql, $params);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public static function select(string $sql, array $params = []): array
    {
        return self::connection()->select($sql, $params);
    }

    /** @param array<int|string, mixed> $params */
    public static function execute(string $sql, array $params = []): int
    {
        return self::connection()->execute($sql, $params);
    }

    /** @param array<string, mixed> $where */
    public static function count(string $table, array $where = []): int
    {
        $sql = 'SELECT COUNT(*) FROM ' . self::ident($table);
        $params = [];
        if ($where) {
            [$clause, $params] = self::whereClause($where);
            $sql .= ' WHERE ' . $clause;
        }
        /** @var mixed $value */
        $value = self::connection()->query($sql, $params)->fetchColumn();
        return $value === false ? 0 : (int)$value;
    }

    /** @param array<string, mixed> $where */
    public static function value(string $table, string $column, array $where): mixed
    {
        if (!$where) {
            throw new RuntimeException('value(): where is empty');
        }
        [$clause, $params] = self::whereClause($where);
        $sql = 'SELECT ' . self::ident($column)
            . ' FROM ' . self::ident($table)
            . ' WHERE ' . $clause
            . ' LIMIT 1';
        /** @var mixed $row */
        $row = self::connection()->query($sql, $params)->fetch(PDO::FETCH_NUM);
        if (!is_array($row) || !array_key_exists(0, $row)) {
            return null;
        }

        return $row[0];
    }

    /**
     * @param array<string, mixed> $where
     * @return array<array-key, mixed>
     */
    public static function values(string $table, string $keyColumn, string $valueColumn, array $where = []): array
    {
        $sql = 'SELECT ' . self::ident($keyColumn) . ', ' . self::ident($valueColumn)
            . ' FROM ' . self::ident($table);
        $params = [];
        if ($where) {
            [$clause, $params] = self::whereClause($where);
            $sql .= ' WHERE ' . $clause;
        }

        return self::connection()->query($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @param array<string, mixed> $data */
    public static function insert(string $table, array $data): int
    {
        return self::write('INSERT', $table, $data);
    }

    /** @param array<string, mixed> $data */
    public static function replace(string $table, array $data): int
    {
        return self::write('REPLACE', $table, $data);
    }

    /**
     * Returns matched rows, not changed rows.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public static function update(string $table, array $data, array $where): int
    {
        if (!$data) {
            throw new RuntimeException('update(): data is empty');
        }
        if (!$where) {
            throw new RuntimeException('update(): where is empty (refusing unbounded UPDATE)');
        }
        $set = array_map(static fn(int|string $c): string => self::ident((string)$c) . ' = ?', array_keys($data));
        [$clause, $whereParams] = self::whereClause($where);
        $sql = 'UPDATE ' . self::ident($table)
            . ' SET ' . implode(', ', $set)
            . ' WHERE ' . $clause;

        return self::connection()->execute($sql, [...array_values($data), ...$whereParams]);
    }

    /** @param array<string, mixed> $where */
    public static function delete(string $table, array $where): int
    {
        if (!$where) {
            throw new RuntimeException('delete(): where is empty (refusing unbounded DELETE)');
        }
        [$clause, $params] = self::whereClause($where);
        $sql = 'DELETE FROM ' . self::ident($table) . ' WHERE ' . $clause;

        return self::connection()->execute($sql, $params);
    }

    public static function lastInsertId(): string
    {
        return self::connection()->lastInsertId();
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     */
    public static function lock(string $name, int $timeout, Closure $callback): mixed
    {
        return self::connection()->lock($name, $timeout, $callback);
    }

    /**
     * @template T
     * @param Closure(): T $callback
     * @return T
     * @throws Throwable
     */
    public static function transaction(Closure $callback): mixed
    {
        return self::connection()->transaction($callback);
    }

    private static function write(string $type, string $table, array $data): int
    {
        if (!$data) {
            throw new RuntimeException($type . ' INTO ' . $table . '(): data is empty');
        }
        $cols = array_keys($data);
        $sql = $type . ' INTO ' . self::ident($table)
            . ' (' . implode(', ', array_map(static fn(int|string $c): string => self::ident((string)$c), $cols)) . ')'
            . ' VALUES (' . implode(', ', array_fill(start_index: 0, count: count($cols), value: '?')) . ')';

        return self::connection()->execute($sql, array_values($data));
    }

    private static function connection(): Connection
    {
        return self::$connection ??= (self::$resolver)();
    }

    /**
     * Null means IS NULL; arrays mean IN; an empty array matches nothing.
     *
     * @param array<string, mixed> $where
     * @return array{0: string, 1: list<mixed>}
     */
    private static function whereClause(array $where): array
    {
        $conditions = [];
        $params = [];

        /** @var mixed $value */
        foreach ($where as $column => $value) {
            $col = self::ident($column);

            if ($value === null) {
                $conditions[] = $col . ' IS NULL';
                continue;
            }

            if (is_array($value)) {
                if ($value === []) {
                    $conditions[] = '1 = 0';
                    continue;
                }
                $conditions[] = $col . ' IN (' . implode(', ', array_fill(start_index: 0, count: count($value), value: '?')) . ')';
                $params = array_merge($params, array_values($value));
                continue;
            }

            $conditions[] = $col . ' = ?';
            $params[] = $value;
        }

        return [implode(' AND ', $conditions), $params];
    }

    private static function ident(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) {
            throw new RuntimeException("Invalid identifier: {$name}");
        }
        return '`' . $name . '`';
    }
}
