# Database

`core\db\Db` is a static facade over a PDO connection. It is initialized by the framework kernel and available anywhere in your application.

For remote MySQL servers, configure verified TLS using
[`database.ssl`](02-Configuration.md#database) in local configuration.

On Unix-like systems, for `database.hostname` `localhost` the MySQL driver connects through the
local Unix socket and ignores `database.port`, so it could reach a different server than the port
names. There a port other than 3306 with `localhost` is refused when the configuration is read;
use `127.0.0.1` to connect to a TCP port. On Windows the driver connects to `localhost` over TCP
and uses the port, so any port is accepted.

`tools/test-mysql-tls.sh` and `tools/test-wsl.sh` below are framework development tools: they
are in the framework repository's checkout only, not in a published release or a project.

From a framework checkout on Linux/WSL, `bash tools/test-mysql-tls.sh` verifies real
TLS handshakes, transaction recovery and the deploy migration runner (`tools/migrate.php`,
also against a server started with `autocommit=0`) using disposable, loopback-only MariaDB
instances and test certificates. It requires MariaDB server/client tools including
`mariadb-dump`, gzip, OpenSSL, and PHP with `pdo_mysql`, and does not alter an existing database. Pass `php tests/run_all.php`
after the script name to run the full suite with this fixture available.

### Faster WSL runs from a Windows checkout

From PowerShell in this framework checkout:

```powershell
wsl.exe -d SimpraUbuntu -- bash tools/test-wsl.sh
```

Replace `SimpraUbuntu` with your distribution name. The wrapper copies the current working
tree, including staged edits, unstaged edits, untracked files and local configuration, into
a fresh owner-only directory under WSL's `/tmp`. It does not read Git's index or `HEAD`, so
deleted files stay deleted. Generated caches and logs, Git/editor metadata and `node_modules`
are excluded. Links that resolve outside the temporary copy are refused.

Xdebug is disabled for this process tree only, and an inherited `SIMPRA_BUNDLE_DIR` is
cleared so tests do not write to the caller's cache. The default command is
`bash tools/test-mysql-tls.sh php tests/run_all.php`: all discovered generic suites run with
the disposable database fixtures available. The existing prerequisites above apply, plus
Bash, GNU tar and the usual Linux utilities including `setsid`.

To run a single suite or another explicit command in the copy:

```powershell
wsl.exe -d SimpraUbuntu -- bash tools/test-wsl.sh php tests/core/router.php
```

The command's exit status is preserved; success, failure and cancellation remove the copy.
The wrapper does not change the checkout or permanently change PHP settings. It is not a
sandbox: local configuration and database environment variables still name the same servers
as a normal test run, and generic database suites can write there. Use a test database.
External-service and deployment probes remain explicitly invoked, as with `tests/run_all.php`.
Avoid editing files while the initial copy is being made. Use the normal runner directly
when collecting Xdebug coverage.

## Timeouts

A MySQL connection gives up on a server that stops answering instead of holding the PHP worker:

```php
'database' => [
    // Other database settings stay unchanged.
    'connect_timeout' => 10,  // seconds for the TCP connect; 1 to 300
    'read_timeout'    => 300, // seconds for any single read from the server; 1 to 86400
],
```

`read_timeout` bounds every wait for the server, including the login handshake and the whole of
one statement: a statement that takes longer fails with a lost connection (error 2006), and the
server may still finish it - a write in autocommit can still be committed. Set it above your
longest query, and run long maintenance statements on a connection configured with a longer one.
Without it mysqlnd waits up to a day (`mysqlnd.net_read_timeout`), and PHP's
`max_execution_time` does not count time spent waiting on the network on Linux. The framework
applies it to its own connection only; the process-wide ini value is left as it was.

After a lost or killed connection (2006, 2013, 1927), including one that MySQL 8.0.24+ closed for
idling past `wait_timeout` (4031), the statement's exception reaches your code and the dead
connection is dropped, so the next database call opens a new one instead of failing
on the dead link for the rest of the process: a long-running worker loses the one call. The new
connection starts a fresh session - session variables, temporary tables and advisory locks of the
old one are gone. Inside `Db::transaction()` or a `Db::lock()` callback nothing reconnects until
the callback has returned; see the two sections below.

These settings apply to MySQL. For SQLite, `PDO::ATTR_TIMEOUT` is the busy timeout and keeps its
driver default.

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

Parameters are sent as strings. A float is written in the shortest form that reads back as the
same float (`0.1 + 0.2` is sent as `0.30000000000000004`), not cut to the `precision` ini's 14
digits. For an exact decimal such as an amount of money, pass a string and use a `DECIMAL` column.

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

Nested calls use savepoints. If rollback fails or the backend ends the whole transaction,
all enclosing callbacks are aborted: further database operations and commit are refused,
even if an inner exception is caught. Reconnection is allowed after those callbacks exit.
Retry the whole transaction from outside it, not just the last statement.

On MySQL these statement errors abort the transaction the same way, and it is rolled back at
once: a deadlock (1213), an exhausted lock table (1206), a lock wait timeout (1205), a MariaDB
snapshot-isolation conflict (1020) and a lost or killed connection (2006, 2013, 1927, 4031). InnoDB has already rolled back
the whole transaction after a deadlock, so catching the exception and carrying on would
otherwise commit each later statement on its own. The exception itself reaches your code
unchanged, so you can tell a deadlock and retry. Any other error, such as a duplicate key
(1062), undoes only the failing statement: you may catch it and continue the transaction.
An advisory lock taken with `Db::lock()` inside the transaction is still released.

## Advisory locks

Wrap exclusive operations with a named lock to prevent concurrent execution across processes:

```php
$ran = Db::lock('invoice.generate.' . $invoiceId, timeout: 5, callback: function () use ($invoiceId): void {
    // only one process enters here at a time
    generateInvoice($invoiceId);
});
```

`timeout` is in seconds. Throws `RuntimeException` if the lock cannot be acquired within the timeout.
The server sends nothing while it waits, so `timeout` must be shorter than `database.read_timeout`;
a longer or negative timeout is refused before the lock is requested.

The lock belongs to the connection. If that connection is lost while the callback runs, the lock
goes with it - the server releases it when it ends that session, which after a read timeout is
only once the timed-out statement has finished there - so every later database call in the callback throws
`RuntimeException` (`Database connection was lost while Db::lock() held its lock`) instead of
reconnecting and carrying on unprotected. The next call after the callback returns reconnects.
If the release finds the connection lost, `Db::lock()` does not throw for it: the lock went with
the session, so it returns the callback's result - or lets the callback's own exception through -
and the next call reconnects. The release cannot tell when that session ended, though. A callback
that does not touch the database for a while sees nothing when its connection is killed (1927) or
closed for idling past `wait_timeout` (4031), so the lock may have been dropped - and taken by another
process - while the callback was still running. `Db::lock()` therefore logs a warning naming the lock
and saying that exclusivity may have lapsed while the callback ran (when the log is initialized, as
in a booted application). Keep a locked callback's idle time well below `wait_timeout`. Inside a
`Db::transaction()` that loss aborts the transaction, as any lost connection does. Any other
error from the release still throws.

MySQL lock names are server-wide, so the framework prefixes yours with a hash of `database.database`: every process of the application takes the same lock - an old and a new release directory during a deployment, servers with the code at different paths - while an application using another database on the same server does not. The prefix takes 17 of MySQL's 64 characters, leaving 47 for the name.

## Deploy migrations

`tools/migrate.php` applies a release's schema changes before the release is published. It is a
standalone script: it does not load the framework and needs only PHP with `pdo_mysql` and the MariaDB
client tools `mariadb` and `mariadb-dump`, plus `gzip`, on `PATH`. It supports MariaDB only; on a MySQL
server it refuses before it creates its table, dumps or runs any migration SQL. The runner targets a
Linux deploy host with MariaDB and is not tested end to end on Windows.

It is not part of the vendored `core/` and `extensions/`, and `tools/verify-vendored.php` does not
check it: copy `tools/migrate.php` from the framework release into your project's `tools/`, and copy it
again on every upgrade.

```sh
php tools/migrate.php <release> <shared> <tag>
```

| Argument | Meaning |
|---|---|
| `<release>` | The new release's application root. Migrations are read from `migrations/*.sql`, the database from the release's own `config/framework.local.php`, else `framework.local.php`. `SIMPRA_DB_*` is deliberately not read: in a deploy hook the environment belongs to whoever pushed. |
| `<shared>` | A directory that outlives releases. Dumps go to `<shared>/db-dumps/`. |
| `<tag>` | A label for the dump file name, such as the commit being deployed. |

**The local config file must hold the complete `database` section** the application connects with. The
runner reads nothing else: not `config/*.php`, not `SIMPRA_DB_*`. If the application takes any database
setting from elsewhere - a host or password from the PHP-FPM environment, `ssl` from committed config -
the runner connects to what the local file alone says, which can be another database, or the right one
without TLS.

Exit code `0` means publish: nothing was pending, or every pending file was applied. The runner prints
a line for each file it applied, or one line saying why there was nothing to migrate. `1` means do not
publish: the run was refused or a migration failed, and the message says which and why. `2` is a usage
error. A site without a MySQL-driver database publishes as long as it has no migration files.

Not finding migrations is never taken to mean there are none: a `<release>` that is not a directory, a
`migrations/` directory that cannot be listed, and a file ending in `.sql` in another letter case, such
as `.SQL`, are refused. A present `migrations` path must be a directory, and each `.sql` entry
must be a regular file or a symlink to one; neither a broken symlink nor a directory named `.sql` is
skipped.

**How files are applied.** Files run in file-name order, each once, recorded in the `deploy_migration`
table, which the first run creates. A file sorting before one already applied is still applied, so name
files so that sorting them gives the order they were written in, for example with a date prefix. A name
may use letters, digits, dots, dashes and underscores, is at most 190 characters, and must not differ
from another only in letter case. Never edit an applied file: the run refuses a changed checksum, so add
a new file instead.

Before the first pending file, the whole database is dumped to `<shared>/db-dumps/`, readable by its
owner only and in `utf8mb4` whatever the application's charset, so no stored character is lost. The five
newest of the runner's own dumps are kept; other files in the directory, such as a backup an operator
put there, are never deleted, and a dump that fails leaves no partial file behind. Each file then runs in
its own client session, in UTC and with autocommit on, and counts as applied only if its end is reached
and it leaves no transaction open. MariaDB commits DDL as it goes, so a file that fails part-way may be
partly applied. A failing `SOURCE` inside a file stops it there: nothing after it runs, and the file
is recorded as failed. A UTF-8 byte order mark at the start of a file is ignored. Client errors are
passed on as the client prints them: the line it names (`ERROR 1064 (42000) at line 12:`) is the line in
the file where the failing statement starts, and a `near '...' at line N` from the server counts lines
within the statement.

One deployment migrates a database at a time: a second waits up to 60 seconds for the first, then
refuses. The clients must reach the same server as the runner's own connection, and are refused before
the dump if they do not. For `localhost` on Unix-like systems, PDO connects through PHP's
`pdo_mysql.default_socket` and ignores the port, so the clients are given that socket too. On Windows
PDO connects to `localhost` over TCP on the configured port, and so do the clients.

**When a migration fails.** A file recorded as `failed` or interrupted (`running`) blocks every
deployment until it is resolved, even after the file is removed from the release. Either change the file
so that it works on the database as it is now (a changed file runs again), or restore the dump the
message names and clear the entry:

```sql
DELETE FROM deploy_migration WHERE name = '<file>' AND status IN ('failed', 'running');
```

A file whose client could not connect at all, for example on "Too many connections", ran none of its
statements. The run stops and leaves the ledger as it found it - no entry for a new file, the earlier
`failed` or `running` entry for a changed one, which keeps blocking as above - and the next deployment
runs the file.

The same holds when the client cannot record that the file started: a trigger on `deploy_migration`
that refuses the update stops the run with its own error, and one that silently ignores it with
`Migration start mark was not recorded`. Whether a file started is read from the ledger alone, never
from the client's output, which a file's own text can reach. So if the ledger row is removed while the
run is under way, the run stops and says that the ledger cannot show whether any of the file ran:
check the database before deploying again.

**Adopting the runner on an existing database.** The first run that finds a migration file creates
`deploy_migration` and applies every file in `migrations/`. If some of them are already in the database,
because they were applied by hand, record them as applied before the first deployment, or they run again.
Create the table exactly as the runner would - it refuses a `name` column that is not `ascii_bin` - and
add one row per applied file, with the SHA-256 of the file exactly as the release ships it
(`sha256sum migrations/<file>`):

```sql
CREATE TABLE deploy_migration (
    name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    checksum CHAR(64) CHARACTER SET ascii NOT NULL,
    status VARCHAR(8) CHARACTER SET ascii NOT NULL,
    updated_at DATETIME(6) NOT NULL,
    message TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO deploy_migration (name, checksum, status, updated_at)
VALUES ('<file>', '<sha256 of the file>', 'applied', UTC_TIMESTAMP(6));
```

A checksum that does not match the shipped file is refused as an edited migration on the next run,
so a mistake here stops the deployment rather than running the file.

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
