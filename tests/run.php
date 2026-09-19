<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tiny test runner
|--------------------------------------------------------------------------
|
| php tests/run.php            run every tests/*.test.php
| php tests/run.php auth       run only test files whose name contains "auth"
|
| Every test file runs in its own PHP process, so module-level statics
| (database handle, config, settings, current_user) cannot leak between files.
|
*/

$filter = $argv[1] ?? '';

$files = glob(__DIR__ . '/*.test.php') ?: [];
sort($files);

if ($filter !== '') {
    $files = array_values(array_filter($files, fn($f) => str_contains(basename($f), $filter)));
}

if (!$files) {
    fwrite(STDERR, "No test files found" . ($filter !== '' ? " matching '{$filter}'" : '') . ".\n");
    exit(1);
}

/*
|--------------------------------------------------------------------------
| Serialise concurrent runs
|--------------------------------------------------------------------------
| Every suite shares tests/.tmp/storage, and test_fresh_database() replaces
| data.sqlite before each one. Two runs at once therefore delete the database
| out from under the other's open connections, which shows up as spurious
| "database is locked" / "attempt to write a readonly database" failures. Hold
| an exclusive lock for the life of the run so a second run waits its turn.
*/
$lockDir = __DIR__ . '/.tmp';

if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}

$lock = @fopen($lockDir . '/run.lock', 'c');

if ($lock !== false && !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Another test run is using tests/.tmp — waiting for it to finish...\n";
    flock($lock, LOCK_EX);
}

$passed = 0;
$failures = [];

foreach ($files as $file) {
    $name = basename($file);

    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

    $reported = false;

    foreach ($output as $line) {
        if (str_starts_with($line, 'PASS ')) {
            $passed++;
        } elseif (str_starts_with($line, 'FAIL ')) {
            $failures[] = $name . ' :: ' . substr($line, 5);
            $reported = true;
        } elseif (str_starts_with($line, 'FATAL ')) {
            $failures[] = $name . ' :: ' . substr($line, 6);
            $reported = true;
        }
    }

    // A suite that dies mid-run (fatal, timeout) never prints a FAIL line, so
    // the non-zero exit code is the only evidence. Record one failure per
    // broken suite; the list below, not a separate counter, decides the verdict.
    if ($exitCode !== 0 && !$reported) {
        $failures[] = $name . ' :: process exited with code ' . $exitCode;
    }
}

$failed = count($failures);

echo "\n";
foreach ($failures as $failure) {
    echo "  ✗ {$failure}\n";
}

echo "\n" . ($failed === 0 ? "OK" : "FAILED") . " — {$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
