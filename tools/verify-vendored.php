<?php

declare(strict_types=1);

/**
 * Usage: php tools/verify-vendored.php <path-to-project>
 * Compares working-tree content through each repository's Git filters, not raw disk bytes.
 * Exit codes: 0 in sync, 1 drift found, 2 usage or environment error.
 */

/**
 * Framework subtree => project subtree. Product-owned test directories are excluded.
 */
const VENDORED = [
    'framework/core' => 'core',
    'framework/extensions' => 'extensions',
    'tests/core' => 'tests/core',
    'tests/extensions' => 'tests/extensions',
    'tests/integration' => 'tests/integration',
];

/**
 * Shared infrastructure only; product entry points and deployment probes are not vendored.
 */
const VENDORED_FILES = [
    'tests/root.php',
    'tests/helpers.php',
    'tests/run_all.php',
];

/**
 * The agent handbook and the one-line import that points at it, both copied byte-for-byte
 * into every project. Kept out of VENDORED_FILES because those double as the marker for
 * having adopted the generic suite: a checkout with the handbook and no tests would
 * otherwise be reported as missing every test file.
 *
 * Optional in the same sense an extension is - a project without one is not forced to adopt
 * it - and exact once present, which is the drift this catches. For CLAUDE.md that means a
 * copy of the handbook, or a write that landed on the pointer, is reported rather than read.
 */
const VENDORED_HANDBOOK = [
    'AGENTS.md',
    'CLAUDE.md',
];

$frameworkRoot = dirname(__DIR__);
$projectRoot = $argv[1] ?? null;

if ($projectRoot === null) {
    $usage = "usage: php tools/verify-vendored.php <path-to-project>\n";
    fwrite(STDERR, $usage);
    exit(2);
}

$projectRoot = realpath($projectRoot);
if ($projectRoot === false || !is_dir($projectRoot . '/.git')) {
    fwrite(STDERR, "not a git checkout: " . ($argv[1] ?? '') . "\n");
    exit(2);
}

/**
 * Git failures are environment errors, never evidence of an absent vendored file. Stderr is
 * kept apart from the output: a warning or GIT_TRACE line merged into a path list would turn
 * the first path into one that matches nothing, and that file would drop out of the check.
 */
function git(string $repo, string $args): string
{
    $errors = tmpfile();
    $command = 'git -C ' . escapeshellarg($repo) . ' ' . $args;
    $process = $errors === false ? false : proc_open($command, [1 => ['pipe', 'w'], 2 => $errors], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "git failed in {$repo}: could not start {$args}\n");
        exit(2);
    }
    $result = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        rewind($errors);
        fwrite(STDERR, "git failed in {$repo}: {$args}\n" . trim((string)stream_get_contents($errors)) . "\n");
        exit(2);
    }

    return $result;
}

/**
 * Content hashes of working-tree files, with the owning repository's filters applied; null for
 * a path that is not a file. Paths go to git a hundred at a time, because starting a process is
 * expensive on Windows and a project has hundreds of vendored files.
 *
 * @param list<string> $paths
 * @return array<string, ?string>
 */
function blobHashes(string $repo, array $paths): array
{
    $hashes = array_fill_keys($paths, null);
    $files = array_values(array_filter($paths, static fn(string $path): bool => is_file($repo . '/' . $path)));

    foreach (array_chunk($files, 100) as $chunk) {
        // Stderr goes to a file: reading two pipes in turn can deadlock once git fills the second.
        $errors = tmpfile();
        $process = $errors === false
            ? false
            : proc_open(['git', '-C', $repo, 'hash-object', '--', ...$chunk], [1 => ['pipe', 'w'], 2 => $errors], $pipes);
        if (!is_resource($process)) {
            fwrite(STDERR, "git failed in {$repo}: could not start hash-object\n");
            exit(2);
        }
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $lines = $output === '' ? [] : explode("\n", trim($output));
        if (proc_close($process) !== 0 || count($lines) !== count($chunk)) {
            rewind($errors);
            fwrite(STDERR, "git failed in {$repo}: hash-object\n" . trim((string)stream_get_contents($errors)) . "\n");
            exit(2);
        }
        foreach ($chunk as $index => $path) {
            $hashes[$path] = trim($lines[$index]);
        }
    }

    return $hashes;
}

git($frameworkRoot, args: 'rev-parse --git-dir');

// Generic tests are optional, but must match in full once adopted.
$adoptedTests = false;
foreach (VENDORED_FILES as $marker) {
    if (is_file($projectRoot . '/' . $marker)) {
        $adoptedTests = true;
        break;
    }
}

$same = 0;
$drift = [];
$missing = [];
$extra = [];
$skipped = [];
/** @var array<string, string> $pairs framework path => project path, compared after hashing */
$pairs = [];

/**
 * One ls-files call per repository for every subtree, then each subtree's own entries.
 *
 * @param list<string> $subtrees
 * @return array<string, list<string>>
 */
$tracked = static function (string $repo, array $subtrees): array {
    $listed = git($repo, 'ls-files -z -- ' . implode(' ', array_map('escapeshellarg', $subtrees)));
    $paths = $listed === '' ? [] : explode("\0", rtrim($listed, "\0"));
    $bySubtree = [];
    foreach ($subtrees as $subtree) {
        $bySubtree[$subtree] = array_values(array_filter($paths, static fn(string $path): bool => str_starts_with($path, $subtree . '/')));
    }
    return $bySubtree;
};
$frameworkTracked = $tracked($frameworkRoot, array_keys(VENDORED));
$projectTracked = $tracked($projectRoot, array_values(VENDORED));

foreach (VENDORED as $fromSubtree => $toSubtree) {
    if (!$adoptedTests && str_starts_with($fromSubtree, 'tests/')) {
        continue;
    }

    if ($frameworkTracked[$fromSubtree] === []) {
        fwrite(STDERR, "nothing tracked under {$fromSubtree} — is this a framework checkout?\n");
        exit(2);
    }

    foreach ($frameworkTracked[$fromSubtree] as $frameworkPath) {
        $relative = substr($frameworkPath, strlen($fromSubtree) + 1);
        $projectPath = $toSubtree . '/' . $relative;

    // Absent extensions are optional; partially vendored extensions are drift.
        if ($fromSubtree === 'framework/extensions') {
            $extension = explode('/', $relative)[0];
            if (!is_dir($projectRoot . '/extensions/' . $extension)) {
                $skipped[$extension] = true;
                continue;
            }
        }

        $pairs[$frameworkPath] = $projectPath;
    }

    foreach ($projectTracked[$toSubtree] as $projectPath) {
        $relative = substr($projectPath, strlen($toSubtree) + 1);
        if (!is_file($frameworkRoot . '/' . $fromSubtree . '/' . $relative)) {
            $extra[] = $projectPath;
        }
    }
}

if ($adoptedTests) {
    foreach (VENDORED_FILES as $path) {
        $pairs[$path] = $path;
    }
} else {
    $skipped['generic test suite (not adopted)'] = true;
}

$handbook = array_combine(VENDORED_HANDBOOK, VENDORED_HANDBOOK);
$ours = blobHashes($frameworkRoot, array_keys($pairs + $handbook));
$theirs = blobHashes($projectRoot, array_values($pairs + $handbook));

foreach ($pairs as $frameworkPath => $projectPath) {
    if ($theirs[$projectPath] === null) {
        $missing[] = $projectPath;
        continue;
    }
    if ($ours[$frameworkPath] === $theirs[$projectPath]) {
        $same++;
        continue;
    }

    $drift[] = $projectPath;
}

foreach (VENDORED_HANDBOOK as $path) {
    if ($theirs[$path] === null) {
        $skipped[$path . ' (not adopted)'] = true;
        continue;
    }
    if ($ours[$path] === $theirs[$path]) {
        $same++;
        continue;
    }

    $drift[] = $path;
}

printf("framework: %s\nproject:   %s\n\n", $frameworkRoot, $projectRoot);
printf("  identical            %d\n", $same);
printf("  drift                %d\n", count($drift));
printf("  missing in project   %d\n", count($missing));
printf("  extra in project     %d\n", count($extra));
if ($skipped !== []) {
    printf("  not vendored here: %s\n", implode(', ', array_keys($skipped)));
}
echo "\n";

foreach ($drift as $path) {
    echo "  DRIFT      {$path}\n";
}
foreach ($missing as $path) {
    echo "  MISSING    {$path}\n";
}
foreach ($extra as $path) {
    echo "  EXTRA      {$path}\n";
}
$failed = $drift !== [] || $missing !== [] || $extra !== [];
echo $failed ? "\nVENDORED COPY OUT OF SYNC\n" : "\nVENDORED COPY IN SYNC\n";

exit($failed ? 1 : 0);
