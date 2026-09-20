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
    return ['admin', 'media', 'search', 'form-submit', 'form-token', 'sitemap.xml', 'robots.txt', 'index.php'];
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
 * @return array{id: int, from_path: string, to_path: string, status: int, hits: int}|null
 */
function redirect_find(string $path): ?array
{
    $path = redirect_normalize_path($path);

    if ($path === '' || !redirect_table_exists()) {
        return null;
    }

    $stmt = db()->prepare("SELECT id, from_path, to_path, status, hits FROM redirects WHERE from_path = :path LIMIT 1");
    $stmt->execute(['path' => $path]);

    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * Every redirect, for the admin list.
 *
 * @param array{q?: string} $filters `q` matches the old or the new path.
 * @return list<array{id: int, from_path: string, to_path: string, status: int, hits: int, created_at: int}>
 */
function redirect_all(array $filters = []): array
{
    if (!redirect_table_exists()) {
        return [];
    }

    $search = trim((string) ($filters['q'] ?? ''));
    $sql    = "SELECT id, from_path, to_path, status, hits, created_at FROM redirects";
    $params = [];

    if ($search !== '') {
        $sql .= " WHERE from_path LIKE :q ESCAPE '\\' OR to_path LIKE :q ESCAPE '\\'";
        $params['q'] = '%' . like_escape($search) . '%';
    }

    $stmt = db()->prepare($sql . " ORDER BY from_path ASC");
    $stmt->execute($params);

    return $stmt->fetchAll() ?: [];
}

/**
 * Create or replace the redirect for a path. Returns its id.
 *
 * Refuses a redirect the front end could not serve: one that owns a route or a
 * live page, points at itself, or closes a loop. Replacing an entry that already
 * owns the path is this function's job — editing and the rename recorder rely on
 * it — so `existing` is left to the caller to judge.
 *
 * @throws RuntimeException when the redirect would break a working URL.
 */
function redirect_save(string $fromPath, string $toPath, int $status = 301, ?int $ignoreId = null): int
{
    $from = redirect_normalize_path($fromPath);
    $to   = trim($toPath);

    foreach (redirect_conflicts($from, $to, $ignoreId) as $problem) {
        if ($problem['rule'] === 'existing') {
            continue;
        }

        throw new RuntimeException('Refusing a redirect from /' . $from . '/: ' . $problem['rule']);
    }

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

    $rows    = function_exists('content_path_rows') ? content_path_rows($type) : [];
    $oldPath = redirect_content_path($type, $oldRow, $rows);
    $newPath = redirect_content_path($type, $newRow, $rows);

    if ($oldPath === '' || $oldPath === $newPath || redirect_is_reserved($oldPath)) {
        return null;
    }

    try {
        return redirect_save($oldPath, $newPath, 301);
    } catch (Throwable $exception) {
        // The move itself has already succeeded; a URL that could not be kept
        // alive is worth a log line, not a failed save.
        debug_log('redirect not recorded for /' . $oldPath . '/: ' . $exception->getMessage());

        return null;
    }
}

/**
 * Where a content row lives: its type's prefix plus its parents' slugs.
 *
 * @param list<array<string, mixed>> $rows Rows of the same type, when the caller has them.
 */
function redirect_content_path(string $type, array $row, array $rows = []): string
{
    if (!function_exists('build_full_slug')) {
        return '';
    }

    if ($rows === [] && function_exists('content_path_rows')) {
        $rows = content_path_rows($type);
    }

    $settings = load_settings();
    $prefix   = (string) ($settings['content_prefixes'][$type] ?? '');

    return trim(($prefix !== '' ? $prefix . '/' : '') . build_full_slug($row, $rows), '/');
}

/**
 * Drop whatever redirect owns this path.
 *
 * Called when the path becomes live content again — a page renamed back, or one
 * created on an old URL — because the front end serves a redirect before it
 * routes, so an entry left there would hide the page that just came back.
 */
function redirect_forget_path(string $path): bool
{
    $path = redirect_normalize_path($path);

    if ($path === '' || !redirect_table_exists()) {
        return false;
    }

    $stmt = db()->prepare("DELETE FROM redirects WHERE from_path = :path");
    $stmt->execute(['path' => $path]);

    if ($stmt->rowCount() > 0) {
        invalidate_cache($path);

        return true;
    }

    return false;
}

/*
|--------------------------------------------------------------------------
| Conflicts
|--------------------------------------------------------------------------
|
| A redirect exists to repair a URL that no longer resolves. Anything that would
| take a working URL away instead — a route, a live page, the path itself, or a
| loop — is refused, as rule names the admin translates.
|
*/

/**
 * Why this redirect cannot be saved.
 *
 * @return list<array{rule: string, detail: string}>
 */
function redirect_conflicts(string $fromPath, string $toPath, ?int $ignoreId = null): array
{
    $from     = redirect_normalize_path($fromPath);
    $to       = trim($toPath);
    $problems = [];

    if ($from === '') {
        return [['rule' => 'empty', 'detail' => '']];
    }

    if (redirect_is_reserved($from)) {
        $problems[] = ['rule' => 'reserved', 'detail' => $from];
    }

    $shadowed = redirect_shadowed_content($from);

    if ($shadowed !== '') {
        $problems[] = ['rule' => 'shadows', 'detail' => $shadowed];
    }

    // Only a path can point back at this site; an absolute URL is another site's
    // problem, and a legitimate target.
    if (!preg_match('#^https?://#i', $to)) {
        $toPath = redirect_normalize_path($to);

        if ($toPath === $from) {
            $problems[] = ['rule' => 'self', 'detail' => $from];
        } elseif (redirect_chain_reaches($toPath, $from, $targets)) {
            $problems[] = ['rule' => 'loop', 'detail' => $to];
        }
    }

    $targets  = redirect_target_map();
    $existing = redirect_find($from);

    if ($existing !== null && (int) $existing['id'] !== (int) $ignoreId) {
        $problems[] = ['rule' => 'existing', 'detail' => (string) $existing['to_path']];
    }

    return $problems;
}

/**
 * The title of the live thing at this path, or '' when the path is free.
 *
 * Drafts and trashed rows do not count: their URL was never (or is no longer)
 * serving, which is exactly when a redirect is the right answer.
 */
function redirect_shadowed_content(string $path): string
{
    $path = redirect_normalize_path($path);

    if ($path === '') {
        return '';
    }

    // An archive lives at category/<slug> or tag/<slug>.
    $parts = explode('/', $path);

    if (count($parts) === 2 && in_array($parts[0], ['category', 'tag'], true)) {
        $stmt = db()->prepare("SELECT name FROM taxonomy WHERE taxonomy_type = :type AND slug = :slug LIMIT 1");
        $stmt->execute(['type' => $parts[0], 'slug' => $parts[1]]);

        return (string) $stmt->fetchColumn();
    }

    if (!function_exists('load_content_by_slug')) {
        return '';
    }

    $row = load_content_by_slug($path);

    return $row !== null ? (string) ($row['title'] ?? $path) : '';
}

/**
 * Redirect targets keyed by path, for walking chains without a query per hop.
 *
 * @return array<string, string>
 */
function redirect_target_map(): array
{
    $map = [];

    foreach (redirect_all() as $row) {
        $map[(string) $row['from_path']] = (string) $row['to_path'];
    }

    return $map;
}

/**
 * Whether following redirects from $path ever arrives at $target.
 *
 * Stops on a repeat, so an install that already carries a loop cannot make this
 * spin.
 *
 * @param array<string, string>|null $targets Map from redirect_target_map(), when the caller has one.
 */
function redirect_chain_reaches(string $path, string $target, ?array $targets = null): bool
{
    $targets ??= redirect_target_map();
    $current   = redirect_normalize_path($path);
    $seen      = [];
    $limit     = 20;

    while ($current !== '' && $limit-- > 0) {
        if ($current === $target) {
            return true;
        }

        if (isset($seen[$current]) || !isset($targets[$current])) {
            return false;
        }

        $seen[$current] = true;
        $to = $targets[$current];

        if (preg_match('#^https?://#i', $to)) {
            return false;
        }

        $current = redirect_normalize_path($to);
    }

    return false;
}

/**
 * How many redirects a request for this path follows before it stops.
 *
 * 0 is a path that does not redirect at all; more than one is a chain, which
 * works but costs the visitor a second hop.
 */
function redirect_chain_depth(string $path, ?array $targets = null): int
{
    $targets ??= redirect_target_map();
    $current   = redirect_normalize_path($path);
    $seen      = [];
    $hops      = 0;
    $limit     = 20;

    while ($current !== '' && $limit-- > 0) {
        if (isset($seen[$current]) || !isset($targets[$current])) {
            return $hops;
        }

        $seen[$current] = true;
        $hops++;
        $to = $targets[$current];

        if (preg_match('#^https?://#i', $to)) {
            return $hops;
        }

        $current = redirect_normalize_path($to);
    }

    return $hops;
}

/**
 * Entries this install should not be carrying.
 *
 * `self`, `loop`, `shadows` and `reserved` cannot do their job; `chain` works,
 * it just costs an extra hop, so it is reported rather than removed.
 *
 * @return list<array{kind: string, id: int, from_path: string, to_path: string, detail: string}>
 */
function redirect_audit(): array
{
    $targets = redirect_target_map();
    $audit   = [];

    foreach (redirect_all() as $row) {
        $id   = (int) $row['id'];
        $from = (string) $row['from_path'];
        $to   = (string) $row['to_path'];

        $finding = ['kind' => '', 'id' => $id, 'from_path' => $from, 'to_path' => $to, 'detail' => ''];

        if (redirect_is_reserved($from)) {
            $finding['kind']   = 'reserved';
            $finding['detail'] = $from;
            $audit[]           = $finding;
            continue;
        }

        if (!preg_match('#^https?://#i', $to)) {
            $toPath = redirect_normalize_path($to);

            if ($toPath === $from) {
                $finding['kind'] = 'self';
                $audit[]         = $finding;
                continue;
            }

            if (redirect_chain_reaches($toPath, $from, $targets)) {
                $finding['kind'] = 'loop';
                $audit[]         = $finding;
                continue;
            }
        }

        $shadowed = redirect_shadowed_content($from);

        if ($shadowed !== '') {
            $finding['kind']   = 'shadows';
            $finding['detail'] = $shadowed;
            $audit[]           = $finding;
            continue;
        }

        $hops = redirect_chain_depth($from, $targets);

        if ($hops > 1) {
            $finding['kind']   = 'chain';
            $finding['detail'] = (string) $hops;
            $audit[]           = $finding;
        }
    }

    return $audit;
}

/**
 * Remove the entries that cannot do their job, leaving chains alone.
 *
 * @return int How many entries were removed.
 */
function redirect_repair(): int
{
    $removed = 0;

    foreach (redirect_audit() as $finding) {
        if ($finding['kind'] === 'chain') {
            continue;
        }

        if (redirect_delete((int) $finding['id'])) {
            $removed++;
        }
    }

    return $removed;
}
