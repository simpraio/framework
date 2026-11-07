<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = array_slice($argv, offset: 1);
$temporary = sys_get_temp_dir();
foreach ($options as $index => $option) {
    if (str_starts_with($option, '--temp-dir=')) {
        $temporary = substr($option, strlen('--temp-dir='));
        unset($options[$index]);
    }
}
if (array_diff($options, ['--apply', '--workers-stopped']) !== []) {
    fwrite(STDERR, data: "Usage: php tools/prune-auth-counters.php [--temp-dir=PATH] [--apply --workers-stopped]\n");
    exit(2);
}
$apply = in_array('--apply', $options, strict: true);
if ($apply && !in_array('--workers-stopped', $options, strict: true)) {
    fwrite(STDERR, data: "Stop all application workers using this PHP temporary directory, then pass --apply --workers-stopped.\n");
    exit(2);
}

// The workers' temp directory, which is not this command's under systemd PrivateTmp or a pool's
// own sys_temp_dir: pass --temp-dir then, and check the directory printed below is theirs.
$resolved = realpath($temporary);
if ($resolved === false) {
    fwrite(STDERR, "Cannot resolve the temporary directory: {$temporary}\n");
    exit(2);
}
$directory = $resolved . DIRECTORY_SEPARATOR . 'simpra-auth-rl';
echo "Counter directory: {$directory}\n";
if (!file_exists($directory) && !is_link($directory)) {
    echo "No auth counter directory.\n";
    exit(0);
}
if (is_link($directory) || realpath($directory) !== $directory || !is_dir($directory)) {
    fwrite(STDERR, data: "Refusing a redirected auth counter directory.\n");
    exit(2);
}
// The same rule the application applies: a directory another account can write to is not ours.
clearstatcache(true, $directory);
// A mode that cannot be read is refused like a writable one, never read as 0.
$mode = fileperms($directory);
// ext-posix is optional: without it, the owner of a file this process just created is its user.
$euid = static function (): ?int {
    if (function_exists('posix_geteuid')) {
        return posix_geteuid();
    }
    $probe = tmpfile();
    if ($probe === false) {
        return null;
    }
    $stat = fstat($probe);
    fclose($probe);

    return $stat === false ? null : $stat['uid'];
};
if (DIRECTORY_SEPARATOR === '/' && (
    $mode === false
    || ($mode & 0o022) !== 0
    || fileowner($directory) !== $euid()
)) {
    fwrite(STDERR, data: "Refusing an auth counter directory other accounts can write to, or that another account owns.\n");
    exit(2);
}

$expired = 0;
$removed = 0;
$skipped = 0;
$failed = 0;
$now = time();
foreach (new DirectoryIterator($directory) as $entry) {
    if ($entry->isLink() || !$entry->isFile() || preg_match('/^[a-f0-9]{16}\.json$/D', $entry->getFilename()) !== 1) {
        continue;
    }
    $file = fopen($entry->getPathname(), mode: 'r');
    if ($file === false) {
        $failed++;
        continue;
    }
    $eligible = false;
    try {
        if (!flock($file, LOCK_EX | LOCK_NB)) {
            $skipped++;
            continue;
        }
        $raw = stream_get_contents($file, length: 4097);
        /** @var mixed $counter */
        $counter = is_string($raw) && strlen($raw) <= 4096 ? json_decode($raw, associative: true) : null;
        $eligible = is_array($counter) && is_int($counter['attempts'] ?? null)
            && is_int($counter['expires'] ?? null) && $counter['expires'] <= $now;
        $expired += (int)$eligible;
    } finally {
        flock($file, LOCK_UN);
        fclose($file);
    }
    // Offline only: unlinking a live counter can strand another worker on the old inode.
    if ($apply && $eligible) {
        if (unlink($entry->getPathname())) {
            $removed++;
        } else {
            $failed++;
        }
    }
}
printf("%s: expired=%d removed=%d locked=%d failed=%d\n", $apply ? 'Applied' : 'Dry run', $expired, $removed, $skipped, $failed);
exit($failed > 0 ? 1 : 0);
