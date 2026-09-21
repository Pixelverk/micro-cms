<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Front-end search
|--------------------------------------------------------------------------
|
| Search runs over a plain-text copy of each item's components
| (`content.search_text`) rather than the JSON body, because LIKE over JSON
| would match markup and property names as well as prose.
|
| Every query is AND-ed with content_visibility_sql(), so drafts, scheduled
| and archived content can never appear in results.
|
*/

/**
 * Minimum length of a query we will actually run.
 */
function search_min_length(): int
{
    return 2;
}

/**
 * Results per page (hard-capped below).
 */
function search_per_page(): int
{
    return 12;
}

/**
 * Flatten a component tree into searchable plain text.
 *
 * Walks props recursively, stripping HTML and collapsing whitespace, so a
 * rich-text component and a plain text field index the same way.
 */
function search_extract_text(mixed $value, int $depth = 0): string
{
    if ($depth > 12) {
        return '';
    }

    if (is_string($value)) {
        // Block-level tags become spaces first: strip_tags('<p>a</p><p>b</p>')
        // yields "ab", which would glue words together in excerpts and make
        // them unsearchable as separate terms.
        $text = (string) preg_replace(
            '#<\s*(br|/p|/div|/li|/h[1-6]|/tr|/td|/th|/section|/article)\s*/?\s*>#i',
            ' ',
            $value
        );

        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    if (is_array($value)) {
        $parts = [];

        foreach ($value as $key => $item) {
            // Component type names and image references are not prose.
            if (is_string($key) && in_array($key, ['type', 'component', 'image', 'icon', 'url', 'href', 'id'], true)) {
                continue;
            }

            $extracted = search_extract_text($item, $depth + 1);

            if ($extracted !== '') {
                $parts[] = $extracted;
            }
        }

        return implode(' ', $parts);
    }

    if (is_scalar($value)) {
        return trim((string) $value);
    }

    return '';
}

/**
 * The searchable text for a content item.
 *
 * @param array<string, mixed> $body   Decoded components
 * @param array<string, mixed> $meta   Decoded meta (description, excerpt, …)
 */
function search_build_text(array $body, array $meta = [], string $title = ''): string
{
    $parts = [$title];

    // Author is prose an editor typed, so it is searchable like the title.
    foreach (['description', 'excerpt', 'author'] as $key) {
        if (!empty($meta[$key]) && is_string($meta[$key])) {
            $parts[] = $meta[$key];
        }
    }

    $parts[] = search_extract_text($body);

    $text = implode(' ', array_filter($parts, static fn($part) => trim((string) $part) !== ''));

    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/**
 * (Re)index one content item. Called whenever content is saved.
 */
function search_index_content(int $contentId): void
{
    try {
        $pdo = db();

        $stmt = $pdo->prepare("SELECT title, meta, body FROM content WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $contentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return;
        }

        $meta = json_decode((string) ($row['meta'] ?? ''), true);
        $body = json_decode((string) ($row['body'] ?? ''), true);

        $text = search_build_text(
            is_array($body) ? $body : [],
            is_array($meta) ? $meta : [],
            (string) $row['title']
        );

        $update = $pdo->prepare("UPDATE content SET search_text = :text WHERE id = :id");
        $update->execute(['text' => $text, 'id' => $contentId]);

        search_fts_index($contentId, $text);
    } catch (Throwable $exception) {
        // Indexing is best-effort: a failure must not break a save.
        debug_log('search_index_content failed: ' . $exception->getMessage());
    }
}

/**
 * Does the SQLite behind this install have the FTS5 module?
 *
 * FTS5 is a compile-time feature of the SQLite library PHP links against, so it
 * is present on some hosts and absent on others, and it cannot be enabled from
 * php.ini, Apache or this application. Probe it once per request rather than
 * trusting a version or PRAGMA compile_options, which a build may omit.
 */
function search_fts5_available(): bool
{
    if (array_key_exists('cms_search_fts5', $GLOBALS)) {
        return (bool) $GLOBALS['cms_search_fts5'];
    }

    try {
        db()->exec('CREATE VIRTUAL TABLE temp.cms_fts5_probe USING fts5(x)');
        db()->exec('DROP TABLE temp.cms_fts5_probe');
        $GLOBALS['cms_search_fts5'] = true;
    } catch (Throwable $exception) {
        $GLOBALS['cms_search_fts5'] = false;
    }

    return $GLOBALS['cms_search_fts5'];
}

/**
 * Test seam: force the probe on or off. Pass null to probe again for real.
 */
function search_override_fts5(?bool $available): void
{
    if ($available === null) {
        unset($GLOBALS['cms_search_fts5']);
        return;
    }

    $GLOBALS['cms_search_fts5'] = $available;
}

/**
 * Turn a user's query into an FTS5 MATCH expression.
 *
 * The whole query becomes one quoted string, so punctuation and FTS operators
 * cannot become syntax, and a trailing "*" makes the final token a prefix — as
 * close as FTS5 gets to LIKE's substring match.
 */
function search_fts_query(string $query): string
{
    return '"' . str_replace('"', '""', trim($query)) . '"*';
}

/**
 * Is the derived FTS index present in this database?
 */
function search_fts_table_exists(): bool
{
    try {
        return (bool) db()->query(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'content_fts'"
        )->fetchColumn();
    } catch (Throwable $exception) {
        return false;
    }
}

/**
 * Build the derived FTS table and fill it from content.search_text.
 *
 * All or nothing: an index that exists but is empty would answer every search
 * with nothing, so a failed fill must not leave the table behind.
 */
function search_fts_build(): void
{
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();

    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $pdo->exec('DROP TABLE IF EXISTS content_fts');
        $pdo->exec('CREATE VIRTUAL TABLE content_fts USING fts5(search_text)');
        $pdo->exec("INSERT INTO content_fts(rowid, search_text) SELECT id, COALESCE(search_text, '') FROM content");

        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $exception) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }
}

/**
 * Make sure the derived FTS index exists, filling it on first use.
 *
 * Deliberately not part of setup.php or a migration: the index is a cache, and
 * keeping it out of the schema leaves a database identical on every host. There
 * are no triggers either — one on `content` would reference a module a non-FTS5
 * host does not have and break every write. A leftover index there is inert,
 * because the search path never touches it.
 */
function search_fts_ensure(): bool
{
    if (!search_fts5_available()) {
        return false;
    }

    if (search_fts_table_exists()) {
        return true;
    }

    try {
        search_fts_build();

        return true;
    } catch (Throwable $exception) {
        debug_log('search_fts_ensure failed: ' . $exception->getMessage());

        return false;
    }
}

/**
 * Put one item's text into the FTS index, replacing whatever was there.
 *
 * Only an index that already exists is touched. Creating it is the read path's
 * job: a save holds write statements on `content`, and SQLite refuses a schema
 * change then with "database table is locked".
 */
function search_fts_index(int $contentId, string $text): void
{
    if (!search_fts_table_exists()) {
        return;
    }

    try {
        $pdo = db();
        $pdo->prepare('DELETE FROM content_fts WHERE rowid = :id')->execute(['id' => $contentId]);
        $pdo->prepare('INSERT INTO content_fts(rowid, search_text) VALUES (:id, :text)')
            ->execute(['id' => $contentId, 'text' => $text]);
    } catch (Throwable $exception) {
        debug_log('search_fts_index failed: ' . $exception->getMessage());
    }
}

/**
 * Drop one item out of the FTS index when it is purged.
 *
 * Never creates the index: a purge on a host without FTS5 must not build one.
 */
function search_index_remove(int $contentId): void
{
    if (!search_fts_table_exists()) {
        return;
    }

    try {
        db()->prepare('DELETE FROM content_fts WHERE rowid = :id')->execute(['id' => $contentId]);
    } catch (Throwable $exception) {
        debug_log('search_index_remove failed: ' . $exception->getMessage());
    }
}

/**
 * Rebuild the derived FTS index from content.search_text.
 *
 * Called after a full reindex. Skipped inside a transaction, where the schema
 * change would be refused; the read path builds the index on first search.
 */
function search_fts_refresh(): void
{
    if (db()->inTransaction() || !search_fts5_available()) {
        return;
    }

    try {
        search_fts_build();
    } catch (Throwable $exception) {
        debug_log('search_fts_refresh failed: ' . $exception->getMessage());
    }
}

/**
 * Rebuild the index for every content item.
 *
 * @return int number of items indexed
 */
function search_reindex_all(): int
{
    $ids = db()->query("SELECT id FROM content")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($ids as $id) {
        search_index_content((int) $id);
    }

    search_fts_refresh();

    return count($ids);
}

/**
 * Is a query worth running?
 */
function search_query_is_valid(string $query): bool
{
    return mb_strlen(trim($query)) >= search_min_length();
}

/**
 * Run a search: FTS5 when the host has it, LIKE otherwise.
 *
 * Both backends answer with the same shape and the same order, so the result a
 * visitor sees does not depend on how their host built SQLite.
 *
 * @param array{type?: string, category?: string, tag?: string} $filters
 * @return array{items: list<array<string, mixed>>, total: int, query: string, page: int, pages: int}
 */
function search_content(string $query, array $filters = [], int $page = 1): array
{
    $query = trim($query);
    $page = max(1, $page);

    if (!search_query_is_valid($query)) {
        return ['items' => [], 'total' => 0, 'query' => $query, 'page' => 1, 'pages' => 1];
    }

    if (search_fts5_available() && search_fts_ensure()) {
        try {
            return search_content_fts($query, $filters, $page);
        } catch (Throwable $exception) {
            // A malformed expression or a damaged index must not take the page
            // down; LIKE still answers.
            debug_log('search_content FTS query failed, falling back to LIKE: ' . $exception->getMessage());
        }
    }

    return search_content_like($query, $filters, $page);
}

/**
 * The LIKE backend. Terms are escaped, so a user's "%" or "_" is a character.
 */
function search_content_like(string $query, array $filters, int $page): array
{
    return search_content_run(
        [
            'sql'    => "(c.title LIKE :q ESCAPE '\\' OR c.search_text LIKE :q ESCAPE '\\')",
            'params' => ['q' => '%' . like_escape($query) . '%'],
        ],
        $query,
        $filters,
        $page
    );
}

/**
 * The FTS5 backend. The index carries the same text as content.search_text.
 */
function search_content_fts(string $query, array $filters, int $page): array
{
    return search_content_run(
        [
            // MATCH goes in a subquery: FTS5 refuses it inside an OR expression
            // ("unable to use function MATCH in the requested context"), and the
            // taxonomy clause below is OR-ed with it.
            'sql'    => 'c.id IN (SELECT rowid FROM content_fts WHERE content_fts MATCH :fts)',
            'params' => ['fts' => search_fts_query($query)],
        ],
        $query,
        $filters,
        $page
    );
}

/**
 * The clauses both backends share: visibility, type and taxonomy filters, and a
 * taxonomy-name match OR-ed with the backend's own matcher.
 *
 * @param array{sql: string, params: array<string, mixed>} $matcher
 * @return array{items: list<array<string, mixed>>, total: int, query: string, page: int, pages: int}
 */
function search_content_run(array $matcher, string $query, array $filters, int $page): array
{
    $pdo = db();
    $theme = theme_config();
    $contentTypes = array_keys($theme['content_types'] ?? []);

    // Visibility first: nothing unpublished can ever match. The fragment
    // begins with AND, which is only valid once another condition exists, so
    // it is pushed last while the remaining clauses join with AND.
    $visible = content_visibility_sql();
    $params = array_merge($visible['params'], $matcher['params']);
    $params['q_tax'] = '%' . like_escape($query) . '%';

    $where = [];
    $where[] = '1 = 1';
    $where[] = '(' . $matcher['sql']
        . ' OR EXISTS (SELECT 1 FROM taxonomy_term_relationships tr'
        . ' INNER JOIN taxonomy tx ON tx.id = tr.taxonomy_id'
        . ' WHERE tr.content_id = c.id AND tr.content_type = c.type'
        . " AND tx.name LIKE :q_tax ESCAPE '\\'))";

    if (!empty($filters['type']) && in_array($filters['type'], $contentTypes, true)) {
        $where[] = 'c.type = :type';
        $params['type'] = $filters['type'];
    }

    // Taxonomy filters join through the relationship table. Only declared
    // taxonomies are honoured, so a stray query parameter cannot filter by an
    // arbitrary taxonomy_type.
    $joins = '';
    $taxonomyFilters = [];

    foreach (theme_taxonomies() as $name => $config) {
        $slug = $filters['taxonomy'][$name] ?? null;

        if (is_string($slug) && $slug !== '') {
            $taxonomyFilters[$name] = $slug;
        }
    }

    if ($taxonomyFilters) {
        $joins .= " INNER JOIN taxonomy_term_relationships ttr ON ttr.content_id = c.id AND ttr.content_type = c.type";
        $joins .= " INNER JOIN taxonomy t ON t.id = ttr.taxonomy_id";

        $taxClauses = [];
        foreach ($taxonomyFilters as $kind => $slug) {
            $taxClauses[] = "(t.taxonomy_type = :tax_type_{$kind} AND t.slug = :tax_slug_{$kind})";
            $params["tax_type_{$kind}"] = $kind;
            $params["tax_slug_{$kind}"] = (string) $slug;
        }

        $where[] = '(' . implode(' OR ', $taxClauses) . ')';
    }

    // The visibility fragment is prefixed with " AND "; strip just that
    // prefix (ltrim would also eat the word "status") and add it as a clause.
    $visibilityClause = preg_replace('/^\s*AND\s+/i', '', trim($visible['sql']));

    if ($visibilityClause !== '') {
        $where[] = $visibilityClause;
    }

    $sql = " FROM content c {$joins} WHERE " . implode(' AND ', $where);

    $count = $pdo->prepare("SELECT COUNT(DISTINCT c.id) {$sql}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $perPage = search_per_page();
    $offset = ($page - 1) * $perPage;

    $select = $pdo->prepare("
        SELECT c.id, c.title, c.slug, c.type, c.meta, c.search_text, c.published_at, c.updated_at
        {$sql}
        GROUP BY c.id
        ORDER BY c.published_at DESC, c.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $select->execute($params);

    $items = [];
    foreach ($select->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $meta = json_decode((string) ($row['meta'] ?? ''), true);

        $items[] = [
            'id'           => (int) $row['id'],
            'title'        => (string) $row['title'],
            'slug'         => (string) $row['slug'],
            'type'         => (string) $row['type'],
            'meta'         => is_array($meta) ? $meta : [],
            'published_at' => (int) $row['published_at'],
            'excerpt'      => search_excerpt((string) ($row['search_text'] ?? ''), $query),
            'url'          => search_item_url($row),
        ];
    }

    return [
        'items' => $items,
        'total' => $total,
        'query' => $query,
        'page' => $page,
        'pages' => max(1, (int) ceil($total / $perPage)),
    ];
}

/**
 * Public URL for a search hit, honouring the content type's URL prefix and
 * treating the configured homepage as the site root.
 */
function search_item_url(array $row): string
{
    $settings = load_settings();

    if (!empty($settings['homepage_id']) && (int) $settings['homepage_id'] === (int) $row['id']) {
        return url('');
    }

    $theme = theme_config();
    $prefix = $settings['content_prefixes'][$row['type']]
        ?? ($theme['content_types'][$row['type']]['url_prefix'] ?? '');

    $prefix = trim((string) $prefix, '/');

    return url(($prefix !== '' ? $prefix . '/' : '') . $row['slug']);
}

/**
 * A short window of text around the first match, for the result list.
 */
function search_excerpt(string $text, string $query, int $length = 200): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

    if ($text === '') {
        return '';
    }

    $position = mb_stripos($text, $query);

    if ($position === false) {
        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length)) . '…' : $text;
    }

    // Start a little before the match so it reads in context.
    $start = max(0, $position - (int) ($length / 3));
    $slice = mb_substr($text, $start, $length);

    $prefix = $start > 0 ? '…' : '';
    $suffix = mb_strlen($text) > $start + $length ? '…' : '';

    return $prefix . trim($slice) . $suffix;
}

/**
 * Highlight the matched terms in an already-escaped string.
 *
 * Returns HTML, so the input must be e()'d first.
 */
function search_highlight(string $escapedText, string $query): string
{
    $query = trim($query);

    if ($query === '') {
        return $escapedText;
    }

    $pattern = '/' . preg_quote(e($query), '/') . '/iu';

    return (string) preg_replace($pattern, '<mark>$0</mark>', $escapedText);
}

/**
 * Search results are never indexable: they are a query view, not content.
 */
function search_robots(): string
{
    return 'noindex, follow';
}
