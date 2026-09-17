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

$passed = 0;
$failed = 0;
$failures = [];

foreach ($files as $file) {
    $name = basename($file);

    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

    foreach ($output as $line) {
        if (str_starts_with($line, 'PASS ')) {
            $passed++;
        } elseif (str_starts_with($line, 'FAIL ')) {
            $failed++;
            $failures[] = $name . ' :: ' . substr($line, 5);
        } elseif (str_starts_with($line, 'FATAL ')) {
            $failed++;
            $failures[] = $name . ' :: ' . substr($line, 6);
        }
    }

    if ($exitCode !== 0) {
        $failures[] = $name . ' :: process exited with code ' . $exitCode;

        if (empty($output)) {
            $failed++;
        }
    }
}

echo "\n";
foreach ($failures as $failure) {
    echo "  ✗ {$failure}\n";
}

echo "\n" . ($failed === 0 ? "OK" : "FAILED") . " — {$passed} passed, {$failed} failed\n";

exit($failed === 0 ? 0 : 1);
