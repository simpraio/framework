<?php

declare(strict_types=1);

/**
 * Usage: php tools/verify-vendored.php <path-to-project>
 * Compares the blobs committed at HEAD on both sides, as `git ls-tree -r HEAD` lists them: blob ids
 * hash content after each repository's own line-ending normalisation, and paths are matched exactly,
 * letter case included. A vendored file that exists on disk but is not committed is reported as
 * missing, and uncommitted edits are not seen at all: commit a re-vendor before verifying it.
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

/**
 * What CLAUDE.md must hold, as AGENTS.md defines it. Compared against this rather than the
 * framework's own copy, which its repository does not commit: a fresh clone has none, and a damaged
 * one would become the reference.
 */
const HANDBOOK_POINTER = ['CLAUDE.md' => "@AGENTS.md\n"];

$frameworkRoot = dirname(__DIR__);
$projectRoot = $argv[1] ?? null;

if ($projectRoot === null) {
    fwrite(STDERR, data: "usage: php tools/verify-vendored.php <path-to-project>\n");
    exit(2);
}

$projectRoot = realpath($projectRoot);
if ($projectRoot === false || !file_exists($projectRoot . '/.git')) {
    fwrite(STDERR, 'not a git checkout: ' . ($argv[1] ?? '') . "\n");
    exit(2);
}

/**
 * Git failures are environment errors, never evidence of an absent vendored file. Stderr goes to
 * a file, kept apart from the output: a warning or GIT_TRACE line merged into the listing would
 * turn the first path into one that matches nothing, and reading two pipes in turn can deadlock.
 */
function git(string $repo, string $args): string
{
    $errors = tmpfile();
    if ($errors === false) {
        fwrite(STDERR, "git failed in {$repo}: no temporary file for {$args}\n");
        exit(2);
    }

    $pipes = [];
    $process = proc_open('git -C ' . escapeshellarg($repo) . ' ' . $args, [1 => ['pipe', 'w'], 2 => $errors], $pipes);
    if (!is_resource($process) || !is_resource($pipes[1] ?? null)) {
        fwrite(STDERR, "git failed in {$repo}: could not start {$args}\n");
        exit(2);
    }
    $result = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0) {
        rewind($errors);
        fwrite(STDERR, "git failed in {$repo}: {$args}\n" . trim((string) stream_get_contents($errors)) . "\n");
        exit(2);
    }

    return $result;
}

/**
 * Every file committed at HEAD, by exact path.
 *
 * @return array<int|string, string> path => blob id; numeric filenames become integer keys
 */
function committedBlobs(string $repo): array
{
    $blobs = [];
    foreach (explode("\0", git($repo, args: 'ls-tree -r -z --full-tree HEAD')) as $entry) {
        $match = [];
        if (preg_match('/^[0-7]+ blob ([0-9a-f]+)\t(.+)$/sD', $entry, $match) === 1) {
            $blobs[$match[2]] = $match[1];
        }
    }

    return $blobs;
}

/** The blob id git gives $content, in $repo's object format. */
function blobOf(string $repo, string $content): string
{
    $file = tempnam(sys_get_temp_dir(), prefix: 'vv-');
    if ($file === false || file_put_contents($file, $content) === false) {
        fwrite(STDERR, data: "cannot write a temporary file to hash\n");
        exit(2);
    }
    $id = trim(git($repo, args: 'hash-object --no-filters ' . escapeshellarg($file)));
    unlink($file);

    return $id;
}

/**
 * @param array<int|string, string> $blobs
 * @return list<string>
 */
function pathsUnder(array $blobs, string $subtree): array
{
    return array_values(array_filter(
        array_map(strval(...), array_keys($blobs)),
        static fn(string $path): bool => str_starts_with($path, $subtree . '/'),
    ));
}

$ours = committedBlobs($frameworkRoot);
$theirs = committedBlobs($projectRoot);

/** @var array<int|string, string> $theirsFolded lower-cased path => committed path, to name a case-only rename */
$theirsFolded = [];
foreach (array_map(strval(...), array_keys($theirs)) as $path) {
    $theirsFolded[strtolower($path)] = $path;
}

/**
 * Why a vendored path is absent from the project's commit, when there is more to say than that.
 */
$absence = static function (string $path) use ($theirsFolded, $projectRoot): string {
    $folded = $theirsFolded[strtolower($path)] ?? null;
    if ($folded !== null) {
        return " (committed as {$folded})";
    }

    return file_exists($projectRoot . '/' . $path) ? ' (not committed)' : '';
};

// Generic tests are optional, but must match in full once adopted, committed or not.
$adoptedTests = false;
foreach (VENDORED_FILES as $marker) {
    if (array_key_exists($marker, $theirs) || is_file($projectRoot . '/' . $marker)) {
        $adoptedTests = true;
        break;
    }
}

$same = 0;
$drift = [];
$missing = [];
$extra = [];
$skipped = [];
/** @var array<string, string> $pairs framework path => project path */
$pairs = [];

// A framework checkout that ships no tests/ - a production export - has no suite to compare a
// project's against, which is not the same as a project missing it.
$frameworkHasTests = pathsUnder($ours, subtree: 'tests/core') !== [];
$compareTests = $adoptedTests && $frameworkHasTests;

foreach (VENDORED as $fromSubtree => $toSubtree) {
    if (!$compareTests && str_starts_with($fromSubtree, 'tests/')) {
        continue;
    }

    $frameworkPaths = pathsUnder($ours, $fromSubtree);
    if ($frameworkPaths === []) {
        fwrite(STDERR, "nothing committed under {$fromSubtree} — is this a framework checkout?\n");
        exit(2);
    }

    foreach ($frameworkPaths as $frameworkPath) {
        $relative = substr($frameworkPath, strlen($fromSubtree) + 1);
        $projectPath = $toSubtree . '/' . $relative;

        // Absent extensions are optional; partially vendored or uncommitted extensions are drift.
        if ($fromSubtree === 'framework/extensions') {
            $extension = explode('/', $relative)[0];
            if (pathsUnder($theirs, 'extensions/' . $extension) === [] && !is_dir($projectRoot . '/extensions/' . $extension)) {
                $skipped[$extension] = true;
                continue;
            }
        }

        $pairs[$frameworkPath] = $projectPath;
    }

    foreach (pathsUnder($theirs, $toSubtree) as $projectPath) {
        if (!array_key_exists($fromSubtree . substr($projectPath, strlen($toSubtree)), $ours)) {
            $extra[] = $projectPath;
        }
    }
}

if ($compareTests) {
    foreach (VENDORED_FILES as $path) {
        $pairs[$path] = $path;
    }
} else {
    $skipped[$adoptedTests ? 'generic test suite (not in the framework)' : 'generic test suite (not adopted)'] = true;
}

foreach ($pairs as $frameworkPath => $projectPath) {
    if (!array_key_exists($projectPath, $theirs)) {
        $missing[] = $projectPath . $absence($projectPath);
        continue;
    }
    if (($ours[$frameworkPath] ?? null) === $theirs[$projectPath]) {
        $same++;
        continue;
    }

    $drift[] = $projectPath;
}

foreach (VENDORED_HANDBOOK as $path) {
    if (array_key_exists($path, HANDBOOK_POINTER)) {
        $ours[$path] = blobOf($frameworkRoot, HANDBOOK_POINTER[$path]);
    }
    if (!array_key_exists($path, $ours)) {
        $skipped[$path . ' (not in the framework)'] = true;
        continue;
    }
    if (!array_key_exists($path, $theirs)) {
        $reason = $absence($path);
        if ($reason === '') {
            $skipped[$path . ' (not adopted)'] = true;
            continue;
        }
        $missing[] = $path . $reason;
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
// Committed content is what is compared; say so when the working tree holds something else.
$vendoredPaths = ['core', 'extensions', 'tests/core', 'tests/extensions', 'tests/integration', ...VENDORED_FILES, ...VENDORED_HANDBOOK];
$uncommitted = trim(git($projectRoot, args: 'status --porcelain -- ' . implode(' ', array_map(escapeshellarg(...), $vendoredPaths))));
if ($uncommitted !== '') {
    echo "  note: uncommitted changes under vendored paths are not compared - commit them first\n";
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
