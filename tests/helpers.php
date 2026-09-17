<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Minimal in-repo test helpers (no composer / no PHPUnit)
|--------------------------------------------------------------------------
*/

final class TestCounters
{
    public static int $passed = 0;
    public static int $failed = 0;
    /** @var string[] */
    public static array $failures = [];
}

/**
 * Run one named test with a body. Exceptions become failures, never fatals.
 */
function t(string $description, callable $body): void
{
    try {
        $body();
        TestCounters::$passed++;
        echo "PASS {$description}\n";
    } catch (Throwable $exception) {
        TestCounters::$failed++;
        TestCounters::$failures[] = $description;
        echo 'FAIL ' . $description . ' — ' . $exception->getMessage() . "\n";
    }
}

function assert_true(bool $condition, string $message = 'expected true'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_false(bool $condition, string $message = 'expected false'): void
{
    if ($condition) {
        throw new RuntimeException($message);
    }
}

function assert_eq(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $label = $message !== '' ? $message . ': ' : '';

        throw new RuntimeException(
            $label . 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)
        );
    }
}

function assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        $label = $message !== '' ? $message . ': ' : '';

        throw new RuntimeException($label . "expected to find '{$needle}'");
    }
}

function assert_not_contains(string $needle, string $haystack, string $message = ''): void
{
    if (str_contains($haystack, $needle)) {
        $label = $message !== '' ? $message . ': ' : '';

        throw new RuntimeException($label . "did not expect to find '{$needle}'");
    }
}

function assert_count(int $expected, array $actual, string $message = ''): void
{
    if (count($actual) !== $expected) {
        $label = $message !== '' ? $message . ': ' : '';

        throw new RuntimeException($label . 'expected ' . $expected . ' items, got ' . count($actual));
    }
}

/**
 * Print the summary and return a process exit code.
 */

function test_summary(): int
{
    echo "\n";

    foreach (TestCounters::$failures as $failure) {
        echo "  x {$failure}\n";
    }

    return TestCounters::$failed === 0 ? 0 : 1;
}
