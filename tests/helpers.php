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
 * Insert a content row directly, bypassing validation, and return its id.
 *
 * The primitive every content fixture builds on: pass only the columns the
 * test cares about; `meta` and `body` may be arrays and are JSON-encoded.
 */
function seed_content(array $overrides = []): int
{
    $now = time();

    $row = array_merge([
        'type'         => 'page',
        'slug'         => 'sample-' . bin2hex(random_bytes(3)),
        'parent_id'    => null,
        'title'        => 'Sample',
        'status'       => 'published',
        'layout'       => null,
        'header'       => null,
        'footer'       => null,
        'meta'         => '{}',
        'body'         => '[]',
        'published_at' => $now,
        'scheduled_at' => null,
        'created_at'   => $now,
        'updated_at'   => $now,
    ], $overrides);

    foreach (['meta', 'body'] as $jsonColumn) {
        if (is_array($row[$jsonColumn])) {
            $row[$jsonColumn] = json_encode($row[$jsonColumn], JSON_THROW_ON_ERROR);
        }
    }

    db()->prepare("
        INSERT INTO content (type, slug, parent_id, title, status, layout, header, footer, meta, body, published_at, scheduled_at, created_at, updated_at)
        VALUES (:type, :slug, :parent_id, :title, :status, :layout, :header, :footer, :meta, :body, :published_at, :scheduled_at, :created_at, :updated_at)
    ")->execute($row);

    return (int) db()->lastInsertId();
}

/**
 * Entry names in a zip, using ZipArchive when present and PharData otherwise.
 *
 * @return list<string>
 */
function zip_entry_names(string $archive): array
{
    $names = [];

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        $zip->open($archive);

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }

        $zip->close();

        return $names;
    }

    $prefix = 'phar://' . $archive . '/';

    foreach (new RecursiveIteratorIterator(new PharData($archive)) as $entry) {
        $path = str_replace('\\', '/', (string) $entry->getPathname());

        $names[] = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    return $names;
}

/**
 * How many content items the theme's demo ships.
 *
 * Derived from the demo files rather than hard-coded, so extending the demo
 * does not quietly break every count in the suite.
 */
function demo_content_count(): int
{
    $demo   = content_package_theme_demo();
    $merged = content_package_merge($demo['documents']);

    return count($merged['package']['content'] ?? []);
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
