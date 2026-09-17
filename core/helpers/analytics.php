<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Analytics
|--------------------------------------------------------------------------
|
| Page views are buffered during the request and written in a single
| shutdown flush, so rendering never waits on the insert. Nothing that
| identifies a visitor is stored: no IPs, only a per-day salted hash, a
| hashed user agent and the referrer's host.
|
*/

/**
 * Is this user agent a bot, crawler or scripted client?
 *
 * Also treats a missing user agent as a bot, which is the usual signature of
 * scrapers that do not bother to identify themselves.
 */
function analytics_is_bot(string $userAgent): bool
{
    if (trim($userAgent) === '') {
        return true;
    }

    return (bool) preg_match(
        '/bot|crawl|spider|slurp|mediapartners|facebookexternalhit|preview|monitor|headless|curl|wget|python-requests|httpclient|scan/i',
        $userAgent
    );
}

/**
 * The referrer's host, or null for direct, self-referring or unusable values.
 *
 * Only the domain is kept, so paths and query strings from other sites (which
 * can carry personal data) never reach the database.
 */
function analytics_referrer_host(?string $referrer): ?string
{
    $referrer = trim((string) $referrer);

    if ($referrer === '') {
        return null;
    }

    $host = parse_url($referrer, PHP_URL_HOST);

    if (!is_string($host) || $host === '') {
        return null;
    }

    $host = strtolower($host);

    return $host === strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')) ? null : $host;
}

/**
 * A visitor hash that changes every day.
 *
 * The IP is mixed in but never stored, so views can be de-duplicated within a
 * day without keeping anything that identifies a person across days.
 */
function analytics_visitor_hash(string $userAgent): string
{
    return sha1(implode('|', [
        $_SERVER['REMOTE_ADDR'] ?? '',
        $userAgent,
        date('Y-m-d'),
        (string) (config('security.form_secret') ?? ''),
        CMS_PATH,
    ]));
}

/**
 * Path of the file that buffers page views between requests.
 *
 * Appending here is one small write, so even a cache hit never pays for a
 * database connection; analytics_ingest() moves the rows in batches.
 */
function analytics_buffer_path(): string
{
    return STORAGE_PATH . '/page-views.log';
}

/**
 * Hard ceiling for the buffer file.
 *
 * Analytics is best-effort: if the database stays unreachable, dropping views
 * is better than an ever-growing file and an ever-slower retry loop.
 */
function analytics_buffer_limit(): int
{
    return 2 * 1024 * 1024;
}

/**
 * Record one page view by appending it to the buffer.
 *
 * $cacheHit is true when the view was served from the HTML cache.
 */
function analytics_record_view(?int $contentId = null, bool $cacheHit = false): void
{
    $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = '/' . ltrim($path, '/');

    $userAgent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');

    $view = [
        'path'          => $path,
        'content_id'    => $contentId,
        'referrer_host' => analytics_referrer_host($_SERVER['HTTP_REFERER'] ?? null),
        'ua_hash'       => $userAgent === '' ? null : sha1($userAgent),
        'visitor_hash'  => analytics_visitor_hash($userAgent),
        'is_bot'        => analytics_is_bot($userAgent) ? 1 : 0,
        'cache_hit'     => $cacheHit ? 1 : 0,
        'viewed_at'     => time(),
    ];

    // Best-effort: a page must never break because analytics could not write,
    // and a database that stays down must not grow the buffer forever.
    $buffer = analytics_buffer_path();

    if (is_file($buffer) && filesize($buffer) > analytics_buffer_limit()) {
        return;
    }

    @file_put_contents(
        $buffer,
        json_encode($view, JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

/**
 * Move buffered views into page_views, one transaction per batch.
 *
 * Recovers batches left behind by a process that died mid-ingest, so a crash
 * cannot strand rows in a staging file forever.
 *
 * @return int rows inserted
 */
function analytics_ingest(): int
{
    $buffer   = analytics_buffer_path();
    $staging  = $buffer . '.' . getmypid() . '.staging';
    $inserted = 0;

    // Claim each orphan with a rename, so two concurrent ingests cannot both
    // read the same file.
    foreach (glob($buffer . '.*.staging') ?: [] as $orphan) {
        if (@rename($orphan, $staging)) {
            $inserted += analytics_ingest_file($staging);
        }
    }

    if (is_file($buffer) && filesize($buffer) > 0 && @rename($buffer, $staging)) {
        $inserted += analytics_ingest_file($staging);
    }

    return $inserted;
}

/**
 * Insert one staging file and remove it.
 *
 * On failure the rows go back to the buffer (while it is still under the
 * limit) so a transient error loses nothing.
 */
function analytics_ingest_file(string $file): int
{
    if (!is_file($file)) {
        return 0;
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    try {
        $pdo  = db();
        $stmt = $pdo->prepare("
            INSERT INTO page_views (path, content_id, referrer_host, ua_hash, visitor_hash, is_bot, cache_hit, viewed_at)
            VALUES (:path, :content_id, :referrer_host, :ua_hash, :visitor_hash, :is_bot, :cache_hit, :viewed_at)
        ");

        $pdo->beginTransaction();
        $inserted = 0;

        foreach ($lines as $line) {
            $view = json_decode($line, true);

            if (!is_array($view) || !isset($view['path'], $view['visitor_hash'], $view['viewed_at'])) {
                continue;
            }

            $stmt->execute([
                'path'          => $view['path'],
                'content_id'    => $view['content_id'] ?? null,
                'referrer_host' => $view['referrer_host'] ?? null,
                'ua_hash'       => $view['ua_hash'] ?? null,
                'visitor_hash'  => $view['visitor_hash'],
                'is_bot'        => (int) ($view['is_bot'] ?? 0),
                'cache_hit'     => (int) ($view['cache_hit'] ?? 0),
                'viewed_at'     => (int) $view['viewed_at'],
            ]);

            $inserted++;
        }

        $pdo->commit();
        @unlink($file);

        return $inserted;
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        // Keep the rows for the next attempt, but only while the buffer is
        // still under the limit.
        $buffer = analytics_buffer_path();
        $size   = is_file($buffer) ? (int) filesize($buffer) : 0;

        if ($size < analytics_buffer_limit()) {
            @file_put_contents($buffer, (string) @file_get_contents($file), FILE_APPEND | LOCK_EX);
        }

        @unlink($file);
        debug_log('analytics ingest failed: ' . $exception->getMessage());

        return 0;
    }
}

/**
 * Ingest at most once a minute.
 *
 * The full front-end path calls this, and the scheduled-publishing fall-through
 * guarantees one such request a minute on a busy cached site.
 */
function analytics_maybe_ingest(): void
{
    $marker = STORAGE_PATH . '/.analytics-ingest';

    if (is_file($marker) && (time() - (int) filemtime($marker)) < 60) {
        return;
    }

    @touch($marker);

    try {
        analytics_ingest();
    } catch (Throwable $exception) {
        // Housekeeping: never let an ingest problem reach the request.
        debug_log('analytics maybe_ingest failed: ' . $exception->getMessage());
    }
}

function analytics_table_exists(): bool
{
    static $exists = null;

    if ($exists !== null) {
        return $exists;
    }

    try {
        db()->query("SELECT 1 FROM page_views LIMIT 1");
        $exists = true;
    } catch (Throwable $exception) {
        $exists = false;
    }

    return $exists;
}

/**
 * Unix timestamps [from, to) for a $days-long window ending $offsetDays ago.
 *
 * Offset 0 is the current window; offset = $days is the window immediately
 * before it, which is how the dashboard compares two periods.
 *
 * @return array{from: int, to: int}
 */
function analytics_window(int $days, int $offsetDays = 0): array
{
    $days = max(1, $days);

    // +1 makes "now" inclusive while the window stays half-open [from, to),
    // so a view recorded this second counts and adjacent windows never
    // double-count the boundary row.
    $to = time() + 1 - (max(0, $offsetDays) * 86400);

    return ['from' => $to - ($days * 86400), 'to' => $to];
}

/**
 * Relative change from $previous to $current, as a percentage.
 *
 * Returns null when there is no baseline (a zero previous value), because a
 * percentage against zero is meaningless.
 */
function analytics_change(int $current, int $previous): ?float
{
    if ($previous === 0) {
        return null;
    }

    return (($current - $previous) / $previous) * 100;
}

/**
 * Delete every recorded page view, including anything still buffered.
 *
 * @return int rows removed
 */
function analytics_clear(): int
{
    // Drop anything buffered or orphaned, or the next ingest would bring the
    // views back.
    foreach (glob(analytics_buffer_path() . '*') ?: [] as $file) {
        @unlink($file);
    }

    if (!analytics_table_exists()) {
        return 0;
    }

    return (int) db()->exec("DELETE FROM page_views");
}

/**
 * Page views in a window, excluding bots.
 *
 * @param int $offsetDays 0 for the current window, $days for the previous one.
 */
function analytics_views(int $days, int $offsetDays = 0): int
{
    if (!analytics_table_exists()) {
        return 0;
    }

    $window = analytics_window($days, $offsetDays);

    $stmt = db()->prepare("
        SELECT COUNT(*)
        FROM page_views
        WHERE is_bot = 0 AND viewed_at >= :from AND viewed_at < :to
    ");
    $stmt->execute($window);

    return (int) $stmt->fetchColumn();
}

/**
 * Distinct visitors in a window, excluding bots.
 */
function analytics_unique_visitors(int $days, int $offsetDays = 0): int
{
    if (!analytics_table_exists()) {
        return 0;
    }

    $window = analytics_window($days, $offsetDays);

    $stmt = db()->prepare("
        SELECT COUNT(DISTINCT visitor_hash)
        FROM page_views
        WHERE is_bot = 0 AND viewed_at >= :from AND viewed_at < :to
    ");
    $stmt->execute($window);

    return (int) $stmt->fetchColumn();
}

/**
 * Views per day for the last $days days, oldest first and gap-filled.
 *
 * @return array<string, int> date (Y-m-d) => views
 */
function analytics_daily_views(int $days): array
{
    $days   = max(1, $days);
    $counts = [];

    // Days without traffic still need a point on the sparkline.
    for ($i = $days - 1; $i >= 0; $i--) {
        $counts[gmdate('Y-m-d', time() - ($i * 86400))] = 0;
    }

    if (!analytics_table_exists()) {
        return $counts;
    }

    $stmt = db()->prepare("
        SELECT strftime('%Y-%m-%d', viewed_at, 'unixepoch') AS day, COUNT(*) AS views
        FROM page_views
        WHERE is_bot = 0 AND viewed_at >= :from AND viewed_at < :to
        GROUP BY day
    ");
    $stmt->execute(analytics_window($days));

    foreach ($stmt->fetchAll() as $row) {
        if (array_key_exists((string) $row['day'], $counts)) {
            $counts[(string) $row['day']] = (int) $row['views'];
        }
    }

    return $counts;
}

/**
 * Most viewed paths in the last $days days.
 *
 * @return list<array{path: string, views: int}>
 */
function analytics_top_pages(int $days, int $limit = 10): array
{
    if (!analytics_table_exists()) {
        return [];
    }

    $limit = max(1, min($limit, 50));

    $stmt = db()->prepare("
        SELECT path, COUNT(*) AS views
        FROM page_views
        WHERE is_bot = 0 AND viewed_at >= :from AND viewed_at < :to
        GROUP BY path
        ORDER BY views DESC, path ASC
        LIMIT {$limit}
    ");
    $stmt->execute(analytics_window($days));

    return $stmt->fetchAll() ?: [];
}

/**
 * Most common referrer hosts in the last $days days.
 *
 * @return list<array{referrer_host: string, views: int}>
 */
function analytics_top_referrers(int $days, int $limit = 10): array
{
    if (!analytics_table_exists()) {
        return [];
    }

    $limit = max(1, min($limit, 50));

    $stmt = db()->prepare("
        SELECT referrer_host, COUNT(*) AS views
        FROM page_views
        WHERE is_bot = 0
          AND viewed_at >= :from
          AND viewed_at < :to
          AND referrer_host IS NOT NULL
          AND referrer_host <> ''
        GROUP BY referrer_host
        ORDER BY views DESC, referrer_host ASC
        LIMIT {$limit}
    ");
    $stmt->execute(analytics_window($days));

    return $stmt->fetchAll() ?: [];
}

/**
 * Share of views served from the HTML cache in a window.
 *
 * Recorded per view, so it does not depend on perf_logging.
 *
 * @return array{hits: int, total: int, ratio: float}|null null when no views
 *         were recorded in the window.
 */
function analytics_cache_hit_ratio(int $days = 30, int $offsetDays = 0): ?array
{
    if (!analytics_table_exists()) {
        return null;
    }

    $stmt = db()->prepare("
        SELECT COUNT(*) AS total, COALESCE(SUM(cache_hit), 0) AS hits
        FROM page_views
        WHERE is_bot = 0 AND viewed_at >= :from AND viewed_at < :to
    ");
    $stmt->execute(analytics_window($days, $offsetDays));

    $row   = $stmt->fetch() ?: [];
    $total = (int) ($row['total'] ?? 0);

    if ($total === 0) {
        return null;
    }

    $hits = (int) ($row['hits'] ?? 0);

    return ['hits' => $hits, 'total' => $total, 'ratio' => $hits / $total];
}

/**
 * A self-contained inline SVG sparkline (no chart library, no CDN).
 *
 * @param list<int> $counts
 */
function analytics_sparkline(array $counts, int $width = 240, int $height = 48): string
{
    $counts = array_values(array_map('intval', $counts));
    $max    = $counts ? max($counts) : 0;
    $step   = count($counts) > 1 ? $width / (count($counts) - 1) : 0;

    $points = [];

    foreach ($counts as $index => $value) {
        $x = round($index * $step, 2);

        // Keep the line inside the viewBox, and draw a flat zero line just
        // above the bottom edge.
        $y = $max > 0
            ? round($height - 2 - ($value / $max) * ($height - 4), 2)
            : $height - 2;

        $points[] = $x . ',' . $y;
    }

    return sprintf(
        "<svg class='sparkline' viewBox='0 0 %d %d' width='%d' height='%d' role='img' aria-hidden='true'>"
        . "<polyline points='%s'/></svg>",
        $width,
        $height,
        $width,
        $height,
        implode(' ', $points)
    );
}
