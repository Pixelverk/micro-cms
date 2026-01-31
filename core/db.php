<?php
declare(strict_types=1);

/**
 * Get a PDO connection to the SQLite database
 */
function db(): PDO
{
    $dbPath = STORAGE_PATH . '/data.sqlite';

    if (!file_exists($dbPath)) {
        echo'No database file found!<br>';
        echo'Set the value of "setup_completed" in config.php to false and reload the page.<br>';
        echo'That should run the intial setup and create a DB with some default content.<br>';
        echo'If things still fail, you might not have the PDO extension activated in PHP.<br>';
        exit;
    }

    // important, static = same the entire request, avoids repeated db connection.
    static $pdo;

    if (!$pdo) {
        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    return $pdo;
}

/**
 * Load a content item by slug and type (if provided)
 */
function load_content_by_slug(string $slug, ?string $type = null): ?array
{
    $theme    = theme_config();
    $settings = load_settings();
    $types    = array_keys($theme['content_types'] ?? []);
    $prefixes = $settings['content_prefixes'] ?? [];

    $pdo = db();

    // Are we an admin/preview user?
    $isAdminPreview = isset($_SESSION['user_id']) && $_SESSION['user_id'] !== '';

    foreach ($types as $ct) {
        if ($type !== null && $type !== $ct) continue;

        $prefix = $prefixes[$ct] ?? '';

        // Handle content prefixes (only at root level)
        if ($prefix) {
            if (!str_starts_with($slug, $prefix . '/')) continue;
            $relativeSlug = substr($slug, strlen($prefix) + 1);
        } else {
            $relativeSlug = $slug;
        }

        if ($relativeSlug === '') continue;

        // Split path into segments
        $segments = array_values(array_filter(explode('/', $relativeSlug), 'strlen'));

        $parentId = null;
        $row      = null;

        // Walk the hierarchy
        foreach ($segments as $segment) {

            $sql = "
                SELECT *
                FROM content
                WHERE slug = :slug
                  AND type = :type
                  AND parent_id " . ($parentId === null ? "IS NULL" : "= :parent_id") . "
                LIMIT 1
            ";

            $stmt = $pdo->prepare($sql);

            $params = [
                'slug' => $segment,
                'type' => $ct,
            ];

            if ($parentId !== null) {
                $params['parent_id'] = $parentId;
            }

            $stmt->execute($params);
            $row = $stmt->fetch();

            if (!$row) {
                break;
            }

            $parentId = (int) $row['id'];
        }

        if (!$row) continue;

        // Draft check — only block if not admin/preview
        if (!$isAdminPreview && $row['status'] !== 'published') {
            return null;
        }

        // Decode JSON columns safely
        $body = json_decode($row['body'] ?? '', true);
        $meta = json_decode($row['meta'] ?? '', true);

        if (!is_array($body)) {
            throw new RuntimeException("Invalid body JSON for {$ct}/{$relativeSlug}");
        }

        if ($meta !== null && !is_array($meta)) {
            throw new RuntimeException("Invalid meta JSON for {$ct}/{$relativeSlug}");
        }

        // taxonomies
        $tax = load_taxonomies_for_content($row['type'], (int)$row['id']);

        // header/footer from content type settings
        $ctConfig = $theme['content_types'][$ct] ?? [];

        return [
            'id'           => (int) $row['id'],
            'parent_id'    => $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'type'         => $ct,
            'slug'         => end($segments),
            'title'        => $row['title'],
            'status'       => $row['status'],
            'layout'       => $row['layout'] ?? $ctConfig['default_layout'] ?? $settings['default_layout'] ?? $theme['defaults']['layout'],
            'header'       => $row['header'] ?? $ctConfig['default_header'] ?? $settings['default_header'] ?? $theme['defaults']['header'],
            'footer'       => $row['footer'] ?? $ctConfig['default_footer'] ?? $settings['default_footer'] ?? $theme['defaults']['footer'],
            'meta'         => $meta ?? [],
            'components'   => $body ?? [],
            'created_at'   => (int) $row['created_at'],
            'updated_at'   => (int) $row['updated_at'],
            'published_at' => $row['published_at'] ? (int) $row['published_at'] : null,
            'categories'   => $tax['category'],
            'tags'         => $tax['tag'],
        ];
    }

    return null;
}

/**
 * Optional helper to fetch all content of a type, with filters
 */
function get_contents(string $type, array $filters = []): array
{
    $pdo = db();
    $sql = "SELECT * FROM content WHERE type = :type";
    $params = ['type' => $type];

    if (isset($filters['status'])) {
        $sql .= " AND status = :status";
        $params['status'] = $filters['status'];
    }

    if (isset($filters['order_by'])) {
        $order = strtoupper($filters['order_by']);
        $sql .= " ORDER BY " . ($order === 'ASC' ? 'created_at ASC' : 'created_at DESC');
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $results = [];
    while ($row = $stmt->fetch()) {
        $data = json_decode($row['data'], true);
        $data['slug'] = $row['slug'];
        $data['type'] = $row['type'];
        $data['status'] = $row['status'];
        $data['created_at'] = $row['created_at'];
        $data['updated_at'] = $row['updated_at'];
        $results[] = $data;
    }

    return $results;
}