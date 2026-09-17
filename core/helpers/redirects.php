<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Redirects
|--------------------------------------------------------------------------
|
| Old URLs that must keep working after a slug changes. The front path looks a
| request up before routing, and the admin page manages the table.
|
| Creating or changing a redirect clears the cached file for that path. That is
| what lets a redirect win without a database read on every cache hit — the
| firebreak in index.php never has to know about redirects.
|
*/

function redirect_table_exists(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        db()->query("SELECT 1 FROM redirects LIMIT 1");
        $exists = true;
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

/**
 * Stored form of a path: no scheme, query string or surrounding slashes.
 */
function redirect_normalize_path(string $path): string
{
    $path = (string) parse_url(trim($path), PHP_URL_PATH);

    return trim($path, '/');
}

/**
 * Paths a redirect must never capture, because they are routes, not content.
 *
 * @return list<string>
 */
function redirect_reserved_paths(): array
{
    return ['admin', 'media', 'search', 'form-submit', 'form-token', 'sitemap.xml'];
}

function redirect_is_reserved(string $path): bool
{
    $path = redirect_normalize_path($path);

    if ($path === '') {
        return true;
    }

    foreach (redirect_reserved_paths() as $reserved) {
        if ($path === $reserved || str_starts_with($path, $reserved . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * The redirect for a request path, or null.
 *
 * @return array{from_path: string, to_path: string, status: int, hits: int}|null
 */
function redirect_find(string $path): ?array
{
    $path = redirect_normalize_path($path);

    if ($path === '' || !redirect_table_exists()) {
        return null;
    }

    $stmt = db()->prepare("SELECT from_path, to_path, status, hits FROM redirects WHERE from_path = :path LIMIT 1");
    $stmt->execute(['path' => $path]);

    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Every redirect, for the admin list.
 *
 * @return list<array{id: int, from_path: string, to_path: string, status: int, hits: int, created_at: int}>
 */
function redirect_all(): array
{
    if (!redirect_table_exists()) {
        return [];
    }

    return db()->query("
        SELECT id, from_path, to_path, status, hits, created_at
        FROM redirects
        ORDER BY from_path ASC
    ")->fetchAll() ?: [];
}

/**
 * Create or replace the redirect for a path. Returns its id.
 */
function redirect_save(string $fromPath, string $toPath, int $status = 301): int
{
    $from   = redirect_normalize_path($fromPath);
    $to     = trim($toPath);
    $status = $status === 302 ? 302 : 301;
    $pdo    = db();

    $stmt = $pdo->prepare("
        INSERT INTO redirects (from_path, to_path, status, hits, created_at)
        VALUES (:from_path, :to_path, :status, 0, :created_at)
        ON CONFLICT(from_path) DO UPDATE SET
            to_path = excluded.to_path,
            status  = excluded.status
    ");
    $stmt->execute([
        'from_path'  => $from,
        'to_path'    => $to,
        'status'     => $status,
        'created_at' => time(),
    ]);

    // The old URL may still be cached; a redirect must not lose to a stale file.
    invalidate_cache($from);

    $lookup = $pdo->prepare("SELECT id FROM redirects WHERE from_path = :from_path LIMIT 1");
    $lookup->execute(['from_path' => $from]);

    return (int) $lookup->fetchColumn();
}

function redirect_delete(int $id): bool
{
    if (!redirect_table_exists()) {
        return false;
    }

    $pdo  = db();
    $stmt = $pdo->prepare("SELECT from_path FROM redirects WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $from = (string) $stmt->fetchColumn();

    if ($from === '') {
        return false;
    }

    $pdo->prepare("DELETE FROM redirects WHERE id = :id")->execute(['id' => $id]);
    invalidate_cache($from);

    return true;
}

function redirect_record_hit(string $fromPath): void
{
    if (!redirect_table_exists()) {
        return;
    }

    db()->prepare("UPDATE redirects SET hits = hits + 1 WHERE from_path = :path")
        ->execute(['path' => redirect_normalize_path($fromPath)]);
}

/**
 * Send the redirect and stop the request.
 */
function redirect_send(array $redirect): void
{
    $target = (string) $redirect['to_path'];

    if (!preg_match('#^https?://#i', $target)) {
        $target = url($target);
    }

    header('Location: ' . $target, true, (int) $redirect['status']);
    exit;
}

/**
 * Keep a 301 when an existing item's slug or parent changes.
 *
 * @return int|null the redirect id, or null when the path did not change
 */
function redirect_record_slug_change(string $type, array $oldRow, array $newRow): ?int
{
    if (!function_exists('build_full_slug')) {
        return null;
    }

    $stmt = db()->prepare("SELECT id, slug, parent_id FROM content WHERE type = :type");
    $stmt->execute(['type' => $type]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $prefix = (string) (load_settings()['content_prefixes'][$type] ?? '');
    $prefix = $prefix !== '' ? $prefix . '/' : '';

    $oldPath = trim($prefix . build_full_slug($oldRow, $items), '/');
    $newPath = trim($prefix . build_full_slug($newRow, $items), '/');

    if ($oldPath === '' || $oldPath === $newPath || redirect_is_reserved($oldPath)) {
        return null;
    }

    return redirect_save($oldPath, $newPath, 301);
}
