# Database

`core\db\Db` is a static facade over a PDO connection. It is initialized by the framework kernel and available anywhere in your application.

## Raw queries

```php
// Returns PDOStatement - useful when you need to stream rows or call rowCount()
$stmt = Db::query('SELECT id, name FROM users WHERE active = ?', [1]);

// Fetch one row as associative array, or null if not found
$user = Db::row('SELECT * FROM users WHERE id = ? LIMIT 1', [$id]);

// Fetch all rows as a list of associative arrays
$users = Db::select('SELECT * FROM users WHERE group_id = ?', [$groupId]);

// Execute DML (INSERT / UPDATE / DELETE / DDL) - returns affected row count
$affected = Db::execute('DELETE FROM sessions WHERE expires_at < ?', [time()]);
```

## Convenience methods

These accept a table name and an associative array for conditions. Table and column names are validated against `[A-Za-z_][A-Za-z0-9_]*` - identifiers with dots or other special characters are rejected.

```php
// Row count, optionally filtered
$total = Db::count('users');
$active = Db::count('users', ['active' => 1]);

// Single column value from the first matching row, or null
$email = Db::value('users', 'email', ['id' => $id]);

// Two-column key->value map (useful for building select lists, lookup tables)
$names = Db::values('users', 'id', 'name');
$names = Db::values('users', 'id', 'name', ['active' => 1]);

// Insert a row - created_at is left to its DEFAULT CURRENT_TIMESTAMP(6)
Db::insert('log', ['user_id' => $id, 'action' => 'login']);

// Replace (INSERT ... ON DUPLICATE KEY equivalent in MySQL)
Db::replace('settings', ['key' => 'theme', 'value' => 'dark']);

// Update rows - returns affected row count
Db::update('users', ['name' => $name], ['id' => $id]);

// Delete rows - returns affected row count
Db::delete('sessions', ['user_id' => $id]);
```

`update()` and `delete()` throw `RuntimeException` when `$where` is empty - an unbounded `UPDATE` or `DELETE` must be written as a raw `execute()` call to make the intent explicit.

### Condition values

The `$where` array maps a column to a value, joined with `AND`. The value type controls the operator:

- scalar -> `` `col` = ? ``
- `null` -> `` `col` IS NULL `` (a `null` does **not** become `= NULL`, which never matches anything)
- array -> `` `col` IN (?, ...) ``; an empty array matches nothing (`1 = 0`)

```php
Db::count('users', ['status' => 'active']);             // status = 'active'
Db::delete('sessions', ['user_id' => null]);            // user_id IS NULL
Db::values('users', 'id', 'name', ['id' => [1, 2, 3]]); // id IN (1, 2, 3)
```

### Affected vs matched rows

`update()` returns the number of rows **matched** by `$where`, not only those whose values changed (the MySQL connection sets `MYSQL_ATTR_FOUND_ROWS`). An `UPDATE` that matches a row but writes identical values returns `1`, not `0` - do not use the return value to detect whether the data actually changed.

## Last insert ID

```php
Db::insert('users', ['email' => $email]);
$newId = Db::lastInsertId();
```

## UTC datetime storage

MySQL connection sessions are pinned to `+00:00`; database defaults such as
`CURRENT_TIMESTAMP(6)` therefore write UTC. Keep instant columns as `DATETIME(6)` and prefer
their database defaults instead of stamping them with PHP's `date()`, which uses
`project.timezone` after the framework boots.

A `DATETIME` string carries no timezone metadata. Interpret a retrieved instant as UTC before
converting it to the display timezone:

```php
$stored = new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC'));
$display = $stored->setTimezone(new DateTimeZone(Config::project()->timezone));
```

Calendar labels such as a reporting day are not instants. Store those in the business timezone
required by the report instead of applying the UTC-instant rule blindly.

## Transactions

```php
$result = Db::transaction(function () use ($fromId, $toId, $amount): bool {
    $from = Db::row('SELECT balance FROM accounts WHERE id = ? FOR UPDATE', [$fromId]);
    $to   = Db::row('SELECT balance FROM accounts WHERE id = ? FOR UPDATE', [$toId]);

    if (!$from || !$to || $from['balance'] < $amount) {
        return false;
    }

    Db::update('accounts', ['balance' => $from['balance'] - $amount], ['id' => $fromId]);
    Db::update('accounts', ['balance' => $to['balance'] + $amount],   ['id' => $toId]);
    return true;
});
```

Throws on error and rolls back automatically. The callback's return value is passed through.

## Advisory locks

Wrap exclusive operations with a named lock to prevent concurrent execution across processes:

```php
$ran = Db::lock('invoice.generate.' . $invoiceId, timeout: 5, callback: function () use ($invoiceId): void {
    // only one process enters here at a time
    generateInvoice($invoiceId);
});
```

`timeout` is in seconds. Throws `RuntimeException` if the lock cannot be acquired within the timeout.

## Public API

| Call | Returns | Description |
| --- | --- | --- |
| `Db::query($sql, $params)` | `PDOStatement` | Execute a query and return the statement. |
| `Db::row($sql, $params)` | `array\|null` | Fetch the first row as an associative array, or `null`. |
| `Db::select($sql, $params)` | `array` | Fetch all rows as a list of associative arrays. |
| `Db::execute($sql, $params)` | `int` | Execute DML/DDL. Returns affected row count. |
| `Db::count($table, $where)` | `int` | Count rows, optionally filtered by equality conditions. |
| `Db::value($table, $col, $where)` | `mixed` | Fetch one column value from the first matching row. |
| `Db::values($table, $key, $val, $where)` | `array` | Fetch a key->value map from two columns. |
| `Db::insert($table, $data)` | `int` | Insert a row. Returns affected row count. |
| `Db::replace($table, $data)` | `int` | Replace a row. Returns affected row count. |
| `Db::update($table, $data, $where)` | `int` | Update rows. Refuses empty `$where`. Returns matched (not changed) rows. |
| `Db::delete($table, $where)` | `int` | Delete rows. Refuses empty `$where`. |
| `Db::lastInsertId()` | `string` | Last auto-increment ID as a string. |
| `Db::transaction($callback)` | `mixed` | Run callback in a transaction. Rolls back and re-throws on error. |
| `Db::lock($name, $timeout, $callback)` | `mixed` | Acquire a named advisory lock, run callback, release lock. |
| `Db::ping()` | `void` | Test that the database connection is alive. |

## Configuration

Database credentials are set via environment variables or `config/framework.local.php` - never in version-controlled config files. MySQL sessions are always UTC; `project.timezone` is only for user-facing input and display. See [Configuration](02-Configuration.md) for the full list of `SIMPRA_DB_*` environment variables and the local config format.
