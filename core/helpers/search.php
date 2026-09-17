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

    foreach (['description', 'excerpt'] as $key) {
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
    } catch (Throwable $exception) {
        // Indexing is best-effort: a failure must not break a save.
        debug_log('search_index_content failed: ' . $exception->getMessage());
    }
}

/**
 * Rebuild the index for every content item.
 *
 * @return int number of items indexed
 */
function search_reindex_all(): int
{
    $pdo = db();

    $ids = $pdo->query("SELECT id FROM content")->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($ids as $id) {
        search_index_content((int) $id);
    }

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
 * Run a search.
 *
 * @param array{type?: string, category?: string, tag?: string} $filters
 * @return array{items: list<array<string, mixed>>, total: int, query: string, page: int, pages: int}
 */
function search_content(string $query, array $filters = [], int $page = 1): array
{
    $query = trim($query);
    $page = max(1, $page);

    $empty = ['items' => [], 'total' => 0, 'query' => $query, 'page' => 1, 'pages' => 1];

    if (!search_query_is_valid($query)) {
        return $empty;
    }

    $pdo = db();
    $theme = theme_config();
    $contentTypes = array_keys($theme['content_types'] ?? []);

    // Visibility first: nothing unpublished can ever match. The fragment
    // begins with AND, which is only valid once another condition exists, so
    // it is pushed last while the remaining clauses join with AND.
    $visible = content_visibility_sql();
    $params = $visible['params'];

    $where = [];
    $where[] = '1 = 1';
    $where[] = '(c.title LIKE :q OR c.search_text LIKE :q)';
    $params['q'] = '%' . $query . '%';

    if (!empty($filters['type']) && in_array($filters['type'], $contentTypes, true)) {
        $where[] = 'c.type = :type';
        $params['type'] = $filters['type'];
    }

    // Taxonomy filters join through the relationship table.
    $joins = '';
    $taxonomyFilters = array_filter([
        'category' => $filters['category'] ?? null,
        'tag'      => $filters['tag'] ?? null,
    ]);

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

/**
 * Is this a search request that should bypass the HTML cache?
 */
function search_request_is_uncacheable(): bool
{
    return true;
}
