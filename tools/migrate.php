<?php

declare(strict_types=1);

use Pdo\Mysql;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function fail(string $message, int $code = 1): never
{
    fwrite(STDERR, $message . "\n");
    exit($code);
}

/**
 * @param non-empty-list<string> $command
 * @param resource|list{'file', non-empty-string, 'r'} $input
 * @param array<string, string> $environment
 * @return array{int, string} exit code and standard error
 */
function run(array $command, $input, array $environment): array
{
    $pipes = [];
    $process = proc_open(
        $command,
        [0 => $input, 1 => ['file', '/dev/null', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        cwd: null,
        env_vars: $environment,
    );
    if ($process === false) {
        return [127, "Cannot start {$command[0]}.\n"];
    }
    $stream = $pipes[2] ?? null;
    $error = is_resource($stream) ? stream_get_contents($stream) : false;
    if (is_resource($stream)) {
        fclose($stream);
    }

    return [proc_close($process), is_string($error) ? $error : ''];
}

/**
 * @return array<array-key, mixed>
 */
function databaseSection(mixed $config): array
{
    return is_array($config) && is_array($config['database'] ?? null) ? $config['database'] : [];
}

/**
 * The database section of the deployment's own local config file, the first of the two paths the
 * framework checks. SIMPRA_DB_* is deliberately not read: in a deploy hook the environment belongs to
 * whoever pushed, not to the application, so it could point migrations at another database.
 *
 * @return array<array-key, mixed>
 */
function databaseConfig(string $root): array
{
    foreach ([$root . '/config/framework.local.php', $root . '/framework.local.php'] as $file) {
        if (is_file($file)) {
            try {
                return databaseSection((static fn (string $path): mixed => require $path)($file));
            } catch (Throwable $exception) {
                fail("Cannot read the local config {$file}: {$exception->getMessage()}; no migration was run.");
            }
        }
    }

    return [];
}

/**
 * Same rules as the framework's Cast::string.
 *
 * @param array<array-key, mixed> $section
 */
function configString(array $section, string $key, string $field): string
{
    if (!array_key_exists($key, $section) || $section[$key] === null) {
        return '';
    }
    if (is_string($section[$key])) {
        return $section[$key];
    }
    if (is_int($section[$key]) || is_float($section[$key])) {
        return (string) $section[$key];
    }
    fail("Config: '{$field}' must be a string, got " . get_debug_type($section[$key]) . '.');
}

/**
 * Same rules as the framework's Cast::bool: a blank or unreadable value is an error, never false.
 *
 * @param array<array-key, mixed> $section
 */
function configBool(array $section, string $key, string $field, bool $default): bool
{
    if (!array_key_exists($key, $section) || $section[$key] === null) {
        return $default;
    }
    if (is_bool($section[$key])) {
        return $section[$key];
    }
    if (is_string($section[$key]) && trim($section[$key]) === '') {
        fail("Config: '{$field}' must be a boolean, got a blank string.");
    }
    $coerced = filter_var($section[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
    if ($coerced === null) {
        fail("Config: '{$field}' must be a boolean, got " . get_debug_type($section[$key]) . '.');
    }

    return $coerced;
}

/**
 * @param array<array-key, mixed> $database
 * @return array{host: string, port: int, name: string, user: string, password: string, charset: string, ca: string, cert: string, key: string, verify: bool}
 */
function databaseSettings(array $database): array
{
    if (array_key_exists('ssl', $database) && $database['ssl'] !== null && !is_array($database['ssl'])) {
        fail("Config: 'database.ssl' must be a map.");
    }
    $ssl = is_array($database['ssl'] ?? null) ? $database['ssl'] : [];
    $port = configString($database, key: 'port', field: 'database.port');
    if ($port !== '' && !ctype_digit($port)) {
        fail("Config: 'database.port' must be a number.");
    }
    $charset = configString($database, key: 'charset', field: 'database.charset');
    $settings = [
        'host' => configString($database, key: 'hostname', field: 'database.hostname'),
        'port' => $port === '' ? 3306 : (int) $port,
        'name' => configString($database, key: 'database', field: 'database.database'),
        'user' => configString($database, key: 'username', field: 'database.username'),
        'password' => configString($database, key: 'password', field: 'database.password'),
        'charset' => $charset === '' ? 'utf8mb4' : $charset,
        'ca' => configString($ssl, key: 'ca', field: 'database.ssl.ca'),
        'cert' => configString($ssl, key: 'cert', field: 'database.ssl.cert'),
        'key' => configString($ssl, key: 'key', field: 'database.ssl.key'),
        'verify' => configBool($ssl, key: 'verify_server_cert', field: 'database.ssl.verify_server_cert', default: true),
    ];
    foreach (['host', 'name', 'user'] as $required) {
        if ($settings[$required] === '') {
            fail("The database configuration has no {$required}.");
        }
    }
    if (($settings['cert'] === '') !== ($settings['key'] === '')) {
        fail('Config: database.ssl.cert and database.ssl.key must be set together.');
    }

    return $settings;
}

/**
 * @param array<array-key, mixed> $section
 */
function text(array $section, string $key): string
{
    return is_scalar($section[$key] ?? null) ? (string) $section[$key] : '';
}

/**
 * @param array<array-key, mixed> $ledger
 * @return array{checksum: string, status: string, updated: string, message: string|null}|null
 */
function ledgerEntry(array $ledger, string $name): ?array
{
    if (!is_array($ledger[$name] ?? null)) {
        return null;
    }

    return [
        'checksum' => text($ledger[$name], key: 'checksum'),
        'status' => text($ledger[$name], key: 'status'),
        'updated' => text($ledger[$name], key: 'updated_at'),
        'message' => is_string($ledger[$name]['message'] ?? null) ? $ledger[$name]['message'] : null,
    ];
}

/**
 * The completion mark appended to each file needs @@in_transaction, which only MariaDB has. Checked before
 * the ledger table is created and again before the dump, so another server is left exactly as it was.
 */
function requireMariaDb(PDO $pdo): void
{
    try {
        $pdo->query('SELECT @@in_transaction');
    } catch (PDOException) {
        fail('Migrations need MariaDB: this database server has no @@in_transaction (MySQL?); no migration was run.');
    }
}

/**
 * On Unix-like systems PDO takes PHP's pdo_mysql.default_socket for localhost and ignores the port, while
 * the client, given a port, would use TCP: on a host running two servers they would reach different ones.
 * Follow PDO. On Windows there is no Unix socket and mysqlnd reaches localhost over TCP on the port.
 *
 * @return list<string>
 */
function clientServerOptions(string $host, int $port): array
{
    if ($host !== 'localhost' || PHP_OS_FAMILY === 'Windows') {
        return ["--port={$port}"];
    }
    $socket = (string) ini_get('pdo_mysql.default_socket');

    return $socket === '' ? ['--protocol=SOCKET'] : ['--protocol=SOCKET', "--socket={$socket}"];
}

/**
 * The clients get PATH and the password, nothing else: the deploy environment belongs to whoever pushed,
 * and the client reads variables of its own from it. Windows programs also need SystemRoot to start and
 * to use Winsock, and often the system, temporary and shell paths; none of these configures a client.
 *
 * @return array<string, string>
 */
function clientEnvironment(#[SensitiveParameter] string $password): array
{
    $path = getenv('PATH');
    $environment = ['PATH' => is_string($path) && $path !== '' ? $path : '/usr/local/bin:/usr/bin:/bin', 'MYSQL_PWD' => $password];
    if (PHP_OS_FAMILY !== 'Windows') {
        return $environment;
    }
    foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'COMSPEC'] as $name) {
        $value = getenv($name);
        if (is_string($value) && $value !== '') {
            $environment[$name] = $value;
        }
    }

    return $environment;
}

function removeFile(string $path): void
{
    if (is_file($path)) {
        unlink($path);
    }
}

function unresolved(string $name, string $status, string $updated): string
{
    $what = $status === 'running' ? 'was interrupted' : 'failed';

    return "Migration {$name} {$what} at {$updated} UTC and may be partly applied";
}

$arguments = array_slice($argv, offset: 1);
if (count($arguments) !== 3) {
    fail('Usage: php tools/migrate.php <release> <shared> <tag>', code: 2);
}
[$release, $shared, $tag] = $arguments;

// Finding nothing must mean there is nothing, never that the hook looked in the wrong place: a mistyped or
// unset release path, an unreadable directory or a misnamed file would otherwise publish without migrating.
if (!is_dir($release)) {
    fail("The release {$release} is not a directory; no migration was run.");
}
$migrations = $release . '/migrations';
if ((file_exists($migrations) || is_link($migrations)) && !is_dir($migrations)) {
    fail("The migrations path {$migrations} is not a directory; no migration was run.");
}
$names = [];
if (is_dir($migrations)) {
    // scandir, not glob: [ ] * ? in the release path are ordinary characters.
    $listed = scandir($migrations);
    if ($listed === false) {
        fail("Cannot list {$migrations}; no migration was run.");
    }
    $names = array_values(array_filter($listed, static fn (string $name): bool => str_ends_with(strtolower($name), '.sql')));
}
sort($names, SORT_STRING);

$current = [];
$folded = [];
foreach ($names as $base) {
    if (!str_ends_with($base, '.sql')) {
        fail("Migration file {$base} must end in .sql, in lower case.");
    }
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.sql$/D', $base) !== 1) {
        fail("Migration file name {$base} may use only letters, digits, dots, dashes and underscores.");
    }
    // deploy_migration.name is VARCHAR(190): a server without a strict sql_mode would cut a longer name
    // short, and the file would never match its own ledger row.
    if (strlen($base) > 190) {
        fail("Migration file name {$base} is longer than 190 characters.");
    }
    $lower = strtolower($base);
    if (array_key_exists($lower, $folded)) {
        fail("Migrations {$folded[$lower]} and {$base} differ only in letter case. Keep one.");
    }
    $folded[$lower] = $base;
    $file = $migrations . '/' . $base;
    if (!is_file($file)) {
        fail("Migration {$base} is not a regular file; no migration was run.");
    }
    $current[$base] = $file;
}

// A release without migrations still has to pass the unresolved check below when the site has a MySQL
// database: removing every migration file must not hide a failed or interrupted one. A site without one
// never ran a migration, so it publishes as long as it has no migration files.
$section = databaseConfig($release);
$driver = configString($section, key: 'driver', field: 'database.driver');
if ($driver !== 'mysql') {
    if ($current !== []) {
        fail($section === []
            ? 'migrations/ has SQL files, but this site has no database configured in config/framework.local.php '
                . 'or framework.local.php; SIMPRA_DB_* is not read.'
            : "Migrations support the mysql driver only, this site uses '{$driver}'.");
    }
    echo "Nothing to migrate: no migration files and no MySQL database.\n";
    exit(0);
}
$db = databaseSettings($section);
$tls = $db['ca'] !== '' || $db['cert'] !== '';
// Both sessions set autocommit themselves: with a server default of 0, everything would roll back on disconnect.
// The runner's own session sits idle while the clients dump and migrate, so it must not be dropped as idle:
// the lock and the ledger writes after each file depend on it.
$session = "SET time_zone = '+00:00', autocommit = 1, wait_timeout = 86400";
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, Mysql::ATTR_INIT_COMMAND => $session];
if ($tls) {
    $options[Mysql::ATTR_SSL_VERIFY_SERVER_CERT] = $db['verify'];
    if ($db['ca'] !== '') {
        $options[Mysql::ATTR_SSL_CA] = $db['ca'];
    }
    if ($db['cert'] !== '') {
        $options[Mysql::ATTR_SSL_CERT] = $db['cert'];
        $options[Mysql::ATTR_SSL_KEY] = $db['key'];
    }
}

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']),
        $db['user'],
        $db['password'],
        $options,
    );
    $autocommit = $pdo->query('SELECT @@session.autocommit');
    if ($autocommit === false || (string) $autocommit->fetchColumn() !== '1') {
        fail('Cannot switch on autocommit for the database session; no migration was run.');
    }

    // Held until this process exits, so a second deployment of the same database waits rather than applying a file twice.
    $lockName = substr('deploy_migration:' . $db['name'], offset: 0, length: 64);
    $lock = $pdo->prepare('SELECT GET_LOCK(?, 60)');
    if ($lock === false || !$lock->execute([$lockName]) || (string) $lock->fetchColumn() !== '1') {
        fail('Another deployment is migrating this database. Try again in a minute; no migration was run.');
    }
    $connection = $pdo->query('SELECT CONNECTION_ID()');
    $connectionId = $connection === false ? '' : (string) $connection->fetchColumn();
    if (!ctype_digit($connectionId)) {
        fail('Cannot read the database connection id; no migration was run.');
    }

    $exists = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deploy_migration'");
    if ($exists === false) {
        fail('Cannot check for the deploy_migration table.');
    }
    if ((string) $exists->fetchColumn() === '0') {
        if ($current === []) {
            echo "Nothing to migrate: no migration files.\n";
            exit(0);
        }
        requireMariaDb($pdo);
        // ascii_bin: a name differing only in letter case is a different migration, never the same row.
        $pdo->exec('CREATE TABLE deploy_migration (
            name VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
            checksum CHAR(64) CHARACTER SET ascii NOT NULL,
            status VARCHAR(8) CHARACTER SET ascii NOT NULL,
            updated_at DATETIME(6) NOT NULL,
            message TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }
    $collation = $pdo->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'deploy_migration' AND COLUMN_NAME = 'name'");
    if ($collation === false || (string) $collation->fetchColumn() !== 'ascii_bin') {
        fail('deploy_migration.name is not ascii_bin. Ask the administrator to recreate the table; no migration was run.');
    }
    $statement = $pdo->query('SELECT name, checksum, status, updated_at, message FROM deploy_migration');
    if ($statement === false) {
        fail('Cannot read the deploy_migration table.');
    }
    $ledger = $statement->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    fail("Cannot use the database {$db['name']}: {$exception->getMessage()}; no migration was run.");
}

// Unresolved entries block the deployment whether or not their file is still in this release.
foreach (array_keys($ledger) as $recorded) {
    $name = (string) $recorded;
    $entry = ledgerEntry($ledger, $name);
    if ($entry === null) {
        continue;
    }
    $lower = strtolower($name);
    if (array_key_exists($lower, $folded) && $folded[$lower] !== $name) {
        fail("Migration {$folded[$lower]} differs only in letter case from the recorded {$name}. Rename it back.");
    }
    if ($entry['status'] !== 'applied' && !array_key_exists($name, $current)) {
        fail(unresolved($name, $entry['status'], $entry['updated'])
            . ', and it is not in this release. Ask the administrator to restore the dump taken before it and clear it.');
    }
}

$pending = [];
foreach ($current as $base => $file) {
    $sql = file_get_contents($file);
    if ($sql === false) {
        fail("Cannot read migration {$base}.");
    }
    $sum = hash('sha256', $sql);
    $entry = ledgerEntry($ledger, $base);
    if ($entry === null) {
        $pending[$base] = [$sql, $sum];
        continue;
    }
    if ($entry['status'] === 'applied') {
        if (!hash_equals($entry['checksum'], $sum)) {
            fail("Migration {$base} was changed after it was applied. Never edit an applied migration: add a new one.");
        }
        continue;
    }
    if (hash_equals($entry['checksum'], $sum)) {
        fail(unresolved($base, $entry['status'], $entry['updated'])
            . '. Running it again unchanged could repeat its first statements, so it will not run. Change it so it works on the '
            . 'database as it is now, or ask the administrator to restore the dump taken before it and clear it.');
    }
    $pending[$base] = [$sql, $sum];
}
if ($pending === []) {
    $count = count($current);
    echo match ($count) {
        0 => "Nothing to migrate: no migration files.\n",
        1 => "Nothing to migrate: 1 migration file in this release, all applied.\n",
        default => "Nothing to migrate: {$count} migration files in this release, all applied.\n",
    };
    exit(0);
}

requireMariaDb($pdo);

// The dump covers what a migration can change. Events need the EVENT privilege to be read and to be
// created, altered or dropped, so an account that cannot list them cannot change them either.
$events = true;
try {
    $pdo->query('SHOW EVENTS');
} catch (PDOException) {
    $events = false;
}

// --no-defaults first: no option file (~/.my.cnf, /etc/my.cnf) may redirect the clients to another
// server. The password travels in this child's environment only, so no file and no encoding is needed.
$client = ['--no-defaults', "--user={$db['user']}", "--host={$db['host']}", ...clientServerOptions($db['host'], $db['port'])];
if ($tls) {
    if ($db['ca'] !== '') {
        $client[] = "--ssl-ca={$db['ca']}";
    }
    if ($db['cert'] !== '') {
        $client[] = "--ssl-cert={$db['cert']}";
        $client[] = "--ssl-key={$db['key']}";
    }
    $client[] = $db['verify'] ? '--ssl-verify-server-cert' : '--skip-ssl-verify-server-cert';
} else {
    $client[] = '--skip-ssl';
}
$environment = clientEnvironment($db['password']);
$null = ['file', '/dev/null', 'r'];

// The clients connect on their own, so they must prove they reached the server where this run holds the
// lock before anything is dumped or migrated; a redirected client would dump and change another database.
// SELECT, not DO: DO turns the failing subquery into a warning and exits 0.
$guard = sprintf('SELECT IF(IS_USED_LOCK(%s) <=> %s, 0, (SELECT 1 UNION ALL SELECT 2))', $pdo->quote($lockName), $connectionId);
[$code, $error] = run(['mariadb', ...$client, '--batch', "--execute={$guard}", $db['name']], input: $null, environment: $environment);
if ($code !== 0) {
    fail("The mariadb client does not reach the database this run locked; no migration was run.\n" . $error);
}

// A dump is the whole database: password hashes and personal data included. Everything this run creates,
// the dump directory and the files mariadb-dump and gzip write, is its owner's only, whatever the caller's umask.
umask(0o077);

$failure = null;
$migrating = null;
$dump = '';
try {
    $directory = $shared . '/db-dumps';
    if (!is_dir($directory) && !mkdir($directory, permissions: 0o750, recursive: true)) {
        throw new RuntimeException("Cannot create {$directory}; no migration was run.");
    }
    $dump = sprintf(
        '%s/%s-%s-%s.sql',
        $directory,
        gmdate('Ymd\THis\Z'),
        preg_replace('/[^A-Za-z0-9._-]/', replacement: '_', subject: $tag),
        bin2hex(random_bytes(4)),
    );
    // utf8mb4 whatever the application's charset: it holds every character a column can, so the dump is
    // never lossy, and the dump file names the charset it was written in.
    $dumpOptions = ['--default-character-set=utf8mb4', '--single-transaction', '--no-tablespaces', '--routines', '--triggers', "--result-file={$dump}"];
    if ($events) {
        $dumpOptions[] = '--events';
    }
    [$code, $error] = run(['mariadb-dump', ...$client, ...$dumpOptions, $db['name']], input: $null, environment: $environment);
    if ($code !== 0) {
        removeFile($dump);
        throw new RuntimeException("Database dump before migrating failed; no migration was run:\n" . $error);
    }
    [$code, $error] = run(['gzip', $dump], input: $null, environment: $environment);
    if ($code !== 0) {
        removeFile($dump);
        removeFile($dump . '.gz');
        throw new RuntimeException("Compressing the database dump failed; no migration was run:\n" . $error);
    }
    $dump .= '.gz';
    // Only this runner's own dumps rotate: anything else an operator keeps in the directory stays.
    $older = [];
    $entries = scandir($directory);
    foreach ($entries === false ? [] : $entries as $name) {
        $candidate = "{$directory}/{$name}";
        $time = preg_match('/^\d{8}T\d{6}Z-[A-Za-z0-9._-]*-[0-9a-f]{8}\.sql\.gz$/D', $name) === 1 ? filemtime($candidate) : false;
        if ($candidate !== $dump && $time !== false) {
            $older[$candidate] = $time;
        }
    }
    arsort($older);
    foreach (array_slice(array_keys($older), offset: 4) as $old) {
        unlink($old);
    }

    // The running mark is committed before any SQL of the file runs, so an interrupted run is never mistaken for one that never started.
    $start = $pdo->prepare("INSERT INTO deploy_migration (name, checksum, status, updated_at, message)
        VALUES (?, ?, 'running', UTC_TIMESTAMP(6), NULL)
        ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), status = 'running', updated_at = UTC_TIMESTAMP(6), message = NULL");
    $state = $pdo->prepare('SELECT status FROM deploy_migration WHERE name = ?');
    $abort = $pdo->prepare("UPDATE deploy_migration SET status = 'failed', updated_at = UTC_TIMESTAMP(6), message = ?
        WHERE name = ? AND status = 'running'");
    // A file that never started leaves the ledger as it found it: no row for a new file, and for a changed
    // file the earlier failed or interrupted entry, which must keep blocking until it is resolved. The entry
    // is read just before the running mark replaces it, not before the dump: an administrator may clear it
    // in between, and a restore must not bring back what was cleared.
    $prior = $pdo->prepare('SELECT checksum, status, updated_at, message FROM deploy_migration WHERE name = ?');
    $unstarted = $pdo->prepare("DELETE FROM deploy_migration WHERE name = ? AND status = 'running' AND message IS NULL");
    $restore = $pdo->prepare("UPDATE deploy_migration SET checksum = ?, status = ?, updated_at = ?, message = ?
        WHERE name = ? AND status = 'running' AND message IS NULL");
    if ($start === false || $state === false || $abort === false || $prior === false || $unstarted === false || $restore === false) {
        throw new RuntimeException('Cannot prepare the deploy_migration records; no migration was run.');
    }
    foreach ($pending as $base => [$sql, $sum]) {
        $migrating = $base;
        // The file's own session marks it started in its init command, before reading the file, then marks it
        // applied after its last statement. The init command is one compound statement, because the client fails
        // the connection only when the init command's first statement fails, and it signals unless the mark
        // changed exactly one row, so neither a refused nor a silently ignored mark lets the file run. A file
        // still unmarked when the client fails therefore never ran a statement and is safe to run again; the
        // client stops at the first error, a failing SOURCE included. If the file leaves a transaction open, or
        // its end is never reached, the applied mark is never committed and the file counts as failed. \g sends
        // an unterminated last statement whatever DELIMITER the file left set. Autocommit is switched back on
        // only when no transaction is open, so an idle session the file left at autocommit = 0 still records it.
        $input = tmpfile();
        $script = $sql . "\n\\g\nDELIMITER ;\nSET autocommit = IF(@@in_transaction, @@autocommit, 1);\n"
            . "UPDATE deploy_migration SET status = 'applied', updated_at = UTC_TIMESTAMP(6)"
            . " WHERE name = '{$base}' AND status = 'running';\n";
        if ($input === false || fwrite($input, $script) !== strlen($script) || !rewind($input)) {
            throw new RuntimeException("Cannot prepare migration {$base}; it was not run.");
        }
        $prior->execute([$base]);
        $previous = ledgerEntry([$base => $prior->fetch(PDO::FETCH_ASSOC)], $base);
        $start->execute([$base, $sum]);
        $initialize = "BEGIN NOT ATOMIC {$session}; UPDATE deploy_migration SET message = 'started' WHERE name = '{$base}' AND status = 'running';"
            . " IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Migration start mark was not recorded'; END IF; END";
        [$code, $error] = run(['mariadb', ...$client, '--abort-source-on-error', "--default-character-set={$db['charset']}", "--init-command={$initialize}", $db['name']], $input, environment: $environment);
        fclose($input);
        if ($code !== 0) {
            if ($previous === null) {
                $unstarted->execute([$base]);
                $untouched = $unstarted->rowCount() === 1;
            } else {
                $restore->execute([$previous['checksum'], $previous['status'], $previous['updated'], $previous['message'], $base]);
                $untouched = $restore->rowCount() === 1;
            }
            if ($untouched) {
                throw new RuntimeException("Migration {$base} was not started: the mariadb client failed before running any of it:\n{$error}"
                    . "Nothing of {$base} was applied, and the next deployment runs it again.");
            }
            // Only the ledger tells whether a file started; the client's output is never read for it, since a
            // file's own text reaches that output. With the row gone, nothing shows how far the file got.
            $state->execute([$base]);
            if ($state->fetchColumn() === false) {
                throw new RuntimeException("Migration {$base} failed and its ledger row was removed during the run, so the ledger cannot show whether any of it ran:\n{$error}"
                    . "Check the database before deploying again. The database as it was before this push: {$dump}");
            }
            $abort->execute([substr($error, offset: 0, length: 2000), $base]);
            throw new RuntimeException("Migration {$base} failed:\n{$error}Statements of {$base} before the failing one may already be applied. The database as it was before this push: {$dump}");
        }
        $state->execute([$base]);
        if ((string) $state->fetchColumn() !== 'applied') {
            $abort->execute(['The end of the file was not reached, or the file left a transaction open.', $base]);
            throw new RuntimeException("Migration {$base} did not finish: it leaves a transaction open, or an unclosed comment or quote hides its end. Its uncommitted changes were rolled back; statements before them may already be applied. End every transaction with COMMIT. The database as it was before this push: {$dump}");
        }
        echo "Migration applied: {$base}\n";
    }
} catch (PDOException $exception) {
    // The file's own session records what it finished, so the ledger knows what this session cannot report.
    $failure = $migrating === null
        ? "The database connection failed before any migration ran; no migration was run: {$exception->getMessage()}"
        : "The runner lost its database connection while migrating {$migrating}: {$exception->getMessage()}\n"
            . "{$migrating} may or may not have finished and nothing after it ran: read its row in deploy_migration before deploying again. "
            . "The database as it was before this push: {$dump}";
} catch (RuntimeException $exception) {
    $failure = $exception->getMessage();
}
if ($failure !== null) {
    fail($failure);
}
