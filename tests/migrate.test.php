<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Migration runner
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

/**
 * Every column the registry should guarantee.
 */
function content_columns(): array
{
    return db()->query("PRAGMA table_info(content)")->fetchAll(PDO::FETCH_COLUMN, 1) ?: [];
}

function media_columns(): array
{
    return db()->query("PRAGMA table_info(media)")->fetchAll(PDO::FETCH_COLUMN, 1) ?: [];
}

/**
 * Simulate a database created by an older setup.php: current tables, none of
 * the columns/tables that migrations added, and no marker.
 */
function simulate_legacy_database(): void
{
    $pdo = db();

    // Indexes referencing a dropped column must go first.
    $pdo->exec("DROP INDEX IF EXISTS idx_content_search");

    foreach (['created_by', 'updated_by', 'search_text'] as $column) {
        if (in_array($column, content_columns(), true)) {
            $pdo->exec("ALTER TABLE content DROP COLUMN {$column}");
        }
    }

    // media.title is the one column a migration removes, so a legacy database
    // is simulated by putting it back — with a row that uses it, to prove the
    // drop keeps the data around it.
    if (!in_array('title', media_columns(), true)) {
        $pdo->exec("ALTER TABLE media ADD COLUMN title TEXT");
    }

    $pdo->exec("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, title, alt_text, description,
                           created_at, updated_at)
        VALUES ('legacy.jpg', '2026/03/legacy01', 'image/jpeg', 10, 1, 1, '{}', '{}',
                NULL, 'Old caption', 'Legacy alt', 'Legacy description', 1, 1)
    ");

    $pdo->exec("DROP TABLE IF EXISTS migrations");
    $pdo->exec("DROP TABLE IF EXISTS login_attempts");
    $pdo->exec("DROP TABLE IF EXISTS form_rate_limits");

    migrate_reset_marker();
}

t('a fresh install already has every migrated schema feature', function () {
    $columns = content_columns();

    foreach (['created_by', 'updated_by', 'search_text'] as $column) {
        assert_true(in_array($column, $columns, true), "content.{$column} should exist on a fresh install");
    }

    // A column a migration removes must be absent from the installer's schema
    // too, or the two paths end up different.
    assert_false(in_array('title', media_columns(), true), 'media.title is gone from a fresh install');

    // The installer creates these directly; a legacy database gets them from
    // the migration registry instead.
    $tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('login_attempts', $tables, true), 'throttle table exists');
});

t('the runner records every registry entry and skips work via its marker', function () {
    // A fresh install (or the first real request) runs the registry once; the
    // marker then short-circuits subsequent checks without touching the db.
    migrate_reset_marker();
    migrate_run();

    $tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('migrations', $tables, true), 'the runner records what it applied');
    assert_count(count(migrate_registry()), migrate_applied(), 'every registry entry is recorded');
    assert_true(is_file(migrate_marker_path()), 'marker written');

    assert_count(0, migrate_run(), 'a current marker means no work at all');
});

t('migrate_run() upgrades a legacy database', function () {
    simulate_legacy_database();

    assert_false(in_array('created_by', content_columns(), true), 'precondition: the column is gone');
    assert_true(in_array('title', media_columns(), true), 'precondition: the dormant column is back');

    $ran = migrate_run();

    assert_true(count($ran) >= 3, 'pending migrations should run, ran ' . count($ran));

    $columns = content_columns();
    foreach (['created_by', 'updated_by', 'search_text'] as $column) {
        assert_true(in_array($column, $columns, true), "content.{$column} should be restored");
    }

    assert_false(in_array('title', media_columns(), true), 'media.title is dropped');

    // Dropping a column must not disturb the rows around it.
    $legacy = db()->query("SELECT original_name, alt_text, description FROM media WHERE base_path = '2026/03/legacy01'")->fetch(PDO::FETCH_ASSOC);
    assert_true(is_array($legacy), 'the legacy media row survived');
    assert_eq('Legacy alt', (string) ($legacy['alt_text'] ?? ''), 'and kept its alt text');
    assert_eq('Legacy description', (string) ($legacy['description'] ?? ''), 'and its description');

    $tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    assert_true(in_array('form_rate_limits', $tables, true), 'rate limit table created');

    assert_true(is_file(migrate_marker_path()), 'marker written');
});

t('a second run is skipped via the marker', function () {
    assert_count(0, migrate_run(), 'nothing left to do');
});

t('a stale marker re-checks but re-applies nothing', function () {
    migrate_reset_marker();

    // No schema changes are pending, so the registry is a no-op even though
    // the runner has to consult the database again.
    assert_count(0, migrate_run(), 'idempotent');
});

t('migrations are idempotent even if the recorded state is lost', function () {
    db()->exec("DELETE FROM migrations");
    migrate_reset_marker();

    // Every migration must tolerate being replayed.
    migrate_run();

    $columns = content_columns();
    foreach (['created_by', 'updated_by', 'search_text'] as $column) {
        assert_true(in_array($column, $columns, true), "content.{$column} still present");
    }

    assert_count(0, migrate_run(), 'idempotent on the next run too');
});

t('migrate_applied() reports the recorded keys', function () {
    $applied = migrate_applied();

    assert_true(in_array('2026_09_17_000003_rate_limit_tables', $applied, true), 'rate limit migration recorded');
    assert_count(count(migrate_registry()), $applied, 'every registry entry is recorded');
});

t('the legacy published_at backfill fills missing timestamps', function () {
    // Re-create the condition the migration repairs.
    db()->exec("UPDATE content SET published_at = NULL WHERE slug = 'about'");

    db()->exec("DELETE FROM migrations WHERE id = '2026_09_17_000004_backfill_published_at'");
    migrate_reset_marker();
    migrate_run();

    $stmt = db()->prepare("SELECT published_at FROM content WHERE slug = 'about' LIMIT 1");
    $stmt->execute();

    assert_true((int) $stmt->fetchColumn() > 0, 'published_at was backfilled');
});

exit(test_summary());
