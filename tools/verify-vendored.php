<?php

declare(strict_types=1);

/**
 * Verify that a project's vendored framework copy still matches this framework checkout.
 *
 *   php tools/verify-vendored.php <path-to-project>
 *
 * A project does not install the framework, it vendors it flattened: framework/core/ becomes core/
 * and framework/extensions/ becomes extensions/ in the application root. That transform is manual, so
 * nothing otherwise reports when it stops holding. This does.
 *
 * It compares **git blob hashes of the working tree**, not raw file bytes. Each repository's own
 * filters (`.gitattributes` eol=lf in a project, `core.autocrlf` in the framework) are applied before
 * hashing, so identical content compares equal even though the bytes on disk differ. Comparing bytes
 * would report every single file as drift. Working tree rather than HEAD is deliberate: uncommitted
 * edits are exactly the drift worth catching early.
 *
 * Exit codes: 0 in sync, 1 drift found, 2 usage or environment error.
 */

/**
 * framework subtree => project subtree
 *
 * The test tree is vendored too, and at the same path: tests/core/, tests/extensions/, and
 * tests/integration/ hold the generic suites; tests/root.php, tests/helpers.php, and tests/run_all.php
 * are their shared infrastructure.
 * A project's OWN tests live in tests/app/ and tests/wsl/, which are not listed here and so are never
 * compared — that separation is the whole reason the generic suite can be shared at all.
 */
const VENDORED = [
    'framework/core' => 'core',
    'framework/extensions' => 'extensions',
    'tests/core' => 'tests/core',
    'tests/extensions' => 'tests/extensions',
    'tests/integration' => 'tests/integration',
];

/**
 * Individually vendored files: the shared test infrastructure, which is all the top level holds.
 *
 * Suites are never listed here — they live in the discovered group directories above. A project's own
 * tests/bootstrap.php and tests/run.php are its entry points and are deliberately absent, as are the
 * framework's own tests/http_smoke.php and tests/security_probe.php, which remain explicitly invoked
 * rather than vendored.
 */
const VENDORED_FILES = [
    'tests/root.php',
    'tests/helpers.php',
    'tests/run_all.php',
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

/** Run a git command in $repo and return trimmed stdout, or null when git failed. */
function git(string $repo, string $args): ?string
{
    $command = 'git -C ' . escapeshellarg($repo) . ' ' . $args . ' 2>&1';
    $output = [];
    $status = 0;
    exec($command, $output, $status);

    return $status === 0 ? trim(implode("\n", $output)) : null;
}

/** Content hash of a working-tree file, with the owning repository's filters applied. */
function blobHash(string $repo, string $path): ?string
{
    if (!is_file($repo . '/' . $path)) {
        return null;
    }

    return git($repo, 'hash-object -- ' . escapeshellarg($path));
}

if (git($frameworkRoot, args: 'rev-parse --git-dir') === null) {
    fwrite(STDERR, "framework checkout is not a git repository: {$frameworkRoot}\n");
    exit(2);
}

// The generic test suite is adopted as a whole or not at all, so decide once: a project that has not
// taken it should not be told it is missing every one of them, and one that has taken it must match all.
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

foreach (VENDORED as $fromSubtree => $toSubtree) {
    if (!$adoptedTests && str_starts_with($fromSubtree, 'tests/')) {
        continue;
    }

    $listed = git($frameworkRoot, 'ls-files -- ' . escapeshellarg($fromSubtree));
    if ($listed === null || $listed === '') {
        fwrite(STDERR, "nothing tracked under {$fromSubtree} — is this a framework checkout?\n");
        exit(2);
    }

    foreach (explode("\n", $listed) as $frameworkPath) {
        $relative = substr($frameworkPath, strlen($fromSubtree) + 1);
        $projectPath = $toSubtree . '/' . $relative;

        // A project may vendor a subset of extensions. An extension it does not carry at all is a
        // deliberate choice, not drift; one it carries partially is drift.
        if ($fromSubtree === 'framework/extensions') {
            $extension = explode('/', $relative)[0];
            if (!is_dir($projectRoot . '/extensions/' . $extension)) {
                $skipped[$extension] = true;
                continue;
            }
        }

        $ours = blobHash($frameworkRoot, $frameworkPath);
        $theirs = blobHash($projectRoot, $projectPath);

        if ($theirs === null) {
            $missing[] = $projectPath;
            continue;
        }
        if ($ours === $theirs) {
            $same++;
            continue;
        }

        $drift[] = $projectPath;
    }

    // Files the project has under a vendored subtree that the framework does not ship.
    $theirListed = git($projectRoot, 'ls-files -- ' . escapeshellarg($toSubtree));
    foreach ($theirListed === null || $theirListed === '' ? [] : explode("\n", $theirListed) as $projectPath) {
        $relative = substr($projectPath, strlen($toSubtree) + 1);
        if (!is_file($frameworkRoot . '/' . $fromSubtree . '/' . $relative)) {
            $extra[] = $projectPath;
        }
    }
}

// Individually vendored files. A partial set is the interesting case, so once the suite is adopted
// every one of them must be present and identical.
if ($adoptedTests) {
    foreach (VENDORED_FILES as $path) {
        $ours = blobHash($frameworkRoot, $path);
        $theirs = blobHash($projectRoot, $path);
        if ($theirs === null) {
            $missing[] = $path;
            continue;
        }
        if ($ours === $theirs) {
            $same++;
            continue;
        }
        $drift[] = $path;
    }
} else {
    $skipped['generic test suite (not adopted)'] = true;
}

printf("framework: %s\nproject:   %s\n\n", $frameworkRoot, $projectRoot);
printf("  identical            %d\n", $same);
printf("  drift                %d\n", count($drift));
printf("  missing in project   %d\n", count($missing));
printf("  extra in project     %d\n", count($extra));
if ($skipped !== []) {
    printf("  extensions not vendored: %s\n", implode(', ', array_keys($skipped)));
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
