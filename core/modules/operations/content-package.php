<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content package import/export
|--------------------------------------------------------------------------
| The portable JSON document that carries content and settings between
| installs: the theme's demo files, the Utilities export/download, and the
| preview-then-apply import. The document shape is versioned by
| CONTENT_PACKAGE_FORMAT; the admin page orchestrates it through the two
| utilities_* helpers at the bottom.
*/

/** Format version of a content package document. */
const CONTENT_PACKAGE_FORMAT = 1;

/**
 * The sections the posted form asked for.
 *
 * @return array{content: bool, settings: bool}
 */
function utilities_package_sections(): array
{
    $wanted = (array) ($_POST['sections'] ?? []);

    return [
        'content'  => in_array('content', $wanted, true),
        'settings' => in_array('settings', $wanted, true),
    ];
}

/**
 * Read the chosen source and plan it, without writing anything.
 *
 * @param array{content: bool, settings: bool} $sections
 * @return array{errors: list<string>, plan: array<string, mixed>, package: array<string, mixed>, source: string, files: int, token: string, sections: array{content: bool, settings: bool}}
 */
function utilities_import_read(array $sections, string $stashedToken = ''): array
{
    $documents = [];
    $errors    = [];
    $source    = 'demo';
    $files     = 0;
    $token     = '';

    if ($stashedToken !== '') {
        $read   = content_package_stash_read($stashedToken);
        $source = 'files';
        $token  = $stashedToken;
        $files  = count($read['documents']);
        $documents = $read['documents'];
        $errors = $read['errors'];
    } else {
        // A fresh upload on the preview request.
        $uploads = content_package_uploads($_FILES['files'] ?? []);

        if (content_package_uploads_present($uploads)) {
            $stash     = content_package_stash_store($uploads);
            $read      = content_package_stash_read($stash['token']);
            $source    = 'files';
            $token     = $stash['token'];
            $files     = $stash['count'];
            $documents = $read['documents'];
            $errors    = array_merge($stash['errors'], $read['errors']);
        } else {
            $demo      = content_package_theme_demo();
            $documents = $demo['documents'];
            $errors    = $demo['errors'];
        }
    }

    $available = ['content' => false, 'settings' => false];

    foreach ($documents as $document) {
        foreach (['content', 'settings'] as $section) {
            if (isset($document[$section])) {
                $available[$section] = true;
            }
        }
    }

    if (!$sections['content'] && !$sections['settings']) {
        $errors[] = 'Choose content, settings, or both.';
    }

    foreach (['content', 'settings'] as $section) {
        if ($sections[$section] && !$available[$section]) {
            $errors[] = "The package carries no {$section}.";
        }
    }

    // Keep only what was asked for, then plan the result.
    $chosen = [];

    foreach ($documents as $document) {
        if (!$sections['content']) {
            unset($document['content'], $document['taxonomies'], $document['menus']);
        }

        if (!$sections['settings']) {
            unset($document['settings']);
        }

        if (isset($document['content']) || isset($document['settings'])) {
            $chosen[] = $document;
        }
    }

    $merged = content_package_merge($chosen);
    $plan   = content_package_plan($merged['package']);

    return [
        'errors'   => array_merge($errors, $merged['problems']),
        'plan'     => $plan,
        'package'  => $merged['package'],
        'source'   => $source,
        'files'    => $files,
        'token'    => $token,
        // The apply form repeats these, so it imports what was previewed.
        'sections' => $sections,
    ];
}

/**
 * The settings a package may carry.
 *
 * Only what describes the site's content. Environment and operations settings
 * (site_url, timezone, admin language, media sizes, contact email) and raw
 * admin-only ones (custom CSS, header and footer scripts, robots rules,
 * maintenance) are excluded, because a package that carried site_url or
 * timezone would wreck the install it landed on.
 *
 * @return list<string>
 */
function content_package_setting_keys(): array
{
    return [
        'site_title',
        'site_description',
        'title_suffix',
        'default_layout',
        'default_header',
        'default_footer',
        'content_prefixes',
        'menu_locations',
    ];
}


/**
 * Content rows with ids as integers and a full slug path each.
 *
 * @return array{rows: list<array<string, mixed>>, paths: array<int, string>}
 */
function content_package_rows(): array
{
    $rows = db()->query("SELECT * FROM content WHERE deleted_at IS NULL ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $index => $row) {
        $rows[$index]['id']        = (int) $row['id'];
        $rows[$index]['parent_id'] = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
    }

    $paths = [];

    foreach ($rows as $row) {
        $paths[$row['id']] = build_full_slug($row, $rows);
    }

    return ['rows' => $rows, 'paths' => $paths];
}


/**
 * The theme's own demo package.
 *
 * A theme keeps its demo in theme/demo/content.json and theme/demo/settings.json,
 * so the content a theme developer works with lives beside their components and
 * the installer has no opinion about what a site contains.
 *
 * @return array{documents: list<array<string, mixed>>, errors: list<string>}
 */
function content_package_theme_demo(): array
{
    $documents = [];
    $errors    = [];

    foreach (['content', 'settings'] as $part) {
        $file = theme('demo/' . $part . '.json');

        if (!is_file($file)) {
            continue;
        }

        $parsed = content_package_parse((string) file_get_contents($file));

        if ($parsed['document'] === null) {
            $errors[] = basename($file) . ': ' . $parsed['error'];
            continue;
        }

        $documents[] = $parsed['document'];
    }

    return ['documents' => $documents, 'errors' => $errors];
}


/**
 * The content document: everything the editor owns except media.
 *
 * @return array<string, mixed>
 */
function content_package_export_content(): array
{
    $exported = content_package_rows();
    $rows     = $exported['rows'];
    $paths    = $exported['paths'];

    $byId = [];

    foreach ($rows as $row) {
        $byId[$row['id']] = $row;
    }

    $content = [];

    foreach ($rows as $row) {
        $item = [
            'type'  => (string) $row['type'],
            'path'  => $paths[$row['id']],
            'title' => (string) $row['title'],
            'status' => (string) $row['status'],
        ];

        // Optional when it matches what the theme would default to anyway.
        foreach (['layout', 'header', 'footer'] as $key) {
            if (!empty($row[$key])) {
                $item[$key] = (string) $row[$key];
            }
        }

        if ($row['parent_id'] !== null && isset($paths[$row['parent_id']])) {
            $parentType     = (string) ($byId[$row['parent_id']]['type'] ?? $row['type']);
            $item['parent'] = $parentType . ':' . $paths[$row['parent_id']];
        }

        $item['meta'] = json_decode((string) $row['meta'], true) ?: [];
        $item['body'] = json_decode((string) $row['body'], true) ?: [];

        foreach (['published_at', 'scheduled_at', 'created_at', 'updated_at'] as $key) {
            if ($row[$key] !== null) {
                $item[$key] = (int) $row[$key];
            }
        }

        $content[] = $item;
    }

    $taxonomies = [];

    foreach (db()->query("SELECT * FROM taxonomy ORDER BY id ASC") as $term) {
        $taxonomies[] = [
            'type'         => (string) $term['taxonomy_type'],
            'content_type' => (string) $term['content_type'],
            'name'         => (string) $term['name'],
            'slug'         => (string) $term['slug'],
            'description'  => (string) ($term['description'] ?? ''),
            'content'      => [],
        ];
    }

    // Attach the content each term is used by, so the links travel with it.
    $termIndex = [];

    foreach ($taxonomies as $index => $term) {
        $termIndex[$term['type'] . ':' . $term['content_type'] . ':' . $term['slug']] = $index;
    }

    $links = db()->query("
        SELECT r.taxonomy_id, r.content_id, t.taxonomy_type, t.content_type, t.slug
        FROM taxonomy_term_relationships r
        JOIN taxonomy t ON t.id = r.taxonomy_id
    ");

    foreach ($links as $link) {
        $key = $link['taxonomy_type'] . ':' . $link['content_type'] . ':' . $link['slug'];
        $row = $byId[(int) $link['content_id']] ?? null;

        if ($row === null || $row['type'] !== $link['content_type'] || !isset($termIndex[$key])) {
            continue;
        }

        $taxonomies[$termIndex[$key]]['content'][] = $row['type'] . ':' . $paths[$row['id']];
    }

    $menus = [];

    foreach (list_menus() as $menu) {
        $menus[] = [
            'label' => (string) $menu['label'],
            'slug'  => (string) $menu['slug'],
            // Ids belong to the site that wrote them. The slug travels, and the
            // importer resolves it against the content it just landed.
            'items' => menu_items_strip_ids($menu['items']),
        ];
    }

    return [
        'format'     => CONTENT_PACKAGE_FORMAT,
        'content'    => $content,
        'taxonomies' => $taxonomies,
        'menus'      => $menus,
    ];
}


/**
 * The settings document, including the homepage as a content reference.
 *
 * @return array<string, mixed>
 */
function content_package_export_settings(): array
{
    $settings = load_settings();
    $values   = [];

    foreach (content_package_setting_keys() as $key) {
        if (array_key_exists($key, $settings)) {
            $values[$key] = $settings[$key];
        }
    }

    // The homepage is stored as an id; a package names it by slug instead.
    $homepageId = (int) ($settings['homepage_id'] ?? 0);

    if ($homepageId > 0) {
        $exported = content_package_rows();

        foreach ($exported['rows'] as $row) {
            if ($row['id'] === $homepageId) {
                $values['homepage'] = $row['type'] . ':' . $exported['paths'][$homepageId];
                break;
            }
        }
    }

    return [
        'format'   => CONTENT_PACKAGE_FORMAT,
        'settings' => $values,
    ];
}


/**
 * A document as the JSON a package file holds.
 */
function content_package_json(array $document): string
{
    return json_encode(
        $document,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . "\n";
}


/**
 * Decode one uploaded package file.
 *
 * @return array{document: array<string, mixed>|null, error: string}
 */
function content_package_parse(string $json): array
{
    $document = json_decode($json, true);

    if (!is_array($document)) {
        return ['document' => null, 'error' => 'That file is not JSON.'];
    }

    $format = (int) ($document['format'] ?? 0);

    if ($format !== CONTENT_PACKAGE_FORMAT) {
        return ['document' => null, 'error' => 'Unsupported package format ' . ($format ?: 'none') . '.'];
    }

    if (!isset($document['content']) && !isset($document['settings'])) {
        return ['document' => null, 'error' => 'That file carries neither content nor settings.'];
    }

    return ['document' => $document, 'error' => ''];
}


/**
 * Merge parsed documents into one package.
 *
 * @param list<array<string, mixed>> $documents
 * @return array{package: array<string, mixed>, problems: list<string>}
 */
function content_package_merge(array $documents): array
{
    $package = [
        'content'    => [],
        'taxonomies' => [],
        'menus'      => [],
        'settings'   => [],
        'has_content'  => false,
        'has_settings' => false,
    ];

    $problems = [];

    foreach ($documents as $document) {
        if (isset($document['content'])) {
            if ($package['has_content']) {
                $problems[] = 'Two files both define content; import one at a time.';
            }

            $package['has_content']  = true;
            $package['content']      = is_array($document['content']) ? $document['content'] : [];
            $package['taxonomies']   = is_array($document['taxonomies'] ?? null) ? $document['taxonomies'] : [];
            $package['menus']        = is_array($document['menus'] ?? null) ? $document['menus'] : [];
        }

        if (isset($document['settings'])) {
            if ($package['has_settings']) {
                $problems[] = 'Two files both define settings; import one at a time.';
            }

            $package['has_settings'] = true;
            $package['settings']     = is_array($document['settings']) ? $document['settings'] : [];
        }
    }

    if (!$package['has_content'] && !$package['has_settings']) {
        $problems[] = 'Nothing to import.';
    }

    return ['package' => $package, 'problems' => $problems];
}


/**
 * What an import would do, and everything wrong with the package.
 *
 * Problems are fatal: they are why an import is refused rather than applied in
 * part. Warnings are reported and skipped.
 *
 * @param array<string, mixed> $package
 * @return array<string, mixed>
 */
function content_package_plan(array $package): array
{
    $theme        = theme_config();
    $contentTypes = $theme['content_types'] ?? [];

    $problems = [];
    $warnings = [];

    $refs = [];

    // ------------------------------------------------------------- content
    if ($package['has_content']) {
        foreach ($package['content'] as $index => $item) {
            if (!is_array($item)) {
                $problems[] = "Content item {$index} is not an object.";
                continue;
            }

            $type = (string) ($item['type'] ?? '');
            $path = trim((string) ($item['path'] ?? ''), '/');

            if ($type === '' || $path === '') {
                $problems[] = "Content item {$index} has no type or path.";
                continue;
            }

            if (!isset($contentTypes[$type])) {
                $problems[] = "Unknown content type '{$type}' ({$type}:{$path}).";
                continue;
            }

            $ref = $type . ':' . $path;

            if (isset($refs[$ref])) {
                $problems[] = "{$ref} appears twice.";
                continue;
            }

            $refs[$ref] = true;

            foreach (['layout' => 'layouts', 'header' => 'headers', 'footer' => 'footers'] as $key => $declared) {
                $value = trim((string) ($item[$key] ?? ''));

                if ($value !== '' && !isset($theme[$declared][$value])) {
                    $problems[] = "{$ref} uses an undeclared {$key} '{$value}'.";
                }
            }

            foreach (content_package_component_types($item['body'] ?? []) as $component) {
                if (!content_package_component_exists($component)) {
                    $problems[] = "{$ref} uses a missing component '{$component}'.";
                } elseif (!in_array($component, $contentTypes[$type]['available_components'] ?? [], true)) {
                    // Renderable, but the editor would not offer it: worth
                    // saying, not worth refusing.
                    $warnings[] = "{$ref} uses '{$component}', which {$type} does not offer in the editor.";
                }
            }
        }

        // Parents and taxonomy links have to point at something in the package.
        foreach ($package['content'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $parent = trim((string) ($item['parent'] ?? ''));

            if ($parent !== '' && !isset($refs[$parent])) {
                $problems[] = ($item['type'] ?? '') . ':' . ($item['path'] ?? '') . " has a parent '{$parent}' that is not in the package.";
            }
        }

        foreach ($package['taxonomies'] as $term) {
            if (!is_array($term)) {
                continue;
            }

            $termType = (string) ($term['type'] ?? '');

            if (!in_array($termType, ['category', 'tag'], true)) {
                $problems[] = "Taxonomy '{$term['slug']}' has unknown type '{$termType}'.";
            }

            if (!isset($contentTypes[(string) ($term['content_type'] ?? '')])) {
                $problems[] = "Taxonomy '{$term['slug']}' belongs to an unknown content type.";
            }

            foreach ((array) ($term['content'] ?? []) as $ref) {
                if (!isset($refs[(string) $ref])) {
                    $problems[] = "Taxonomy '{$term['slug']}' is attached to '{$ref}', which is not in the package.";
                }
            }
        }
    }

    // ------------------------------------------------------------ settings
    if ($package['has_settings']) {
        $allowed = content_package_setting_keys();

        foreach (array_keys($package['settings']) as $key) {
            if ($key !== 'homepage' && !in_array((string) $key, $allowed, true)) {
                $problems[] = "Setting '{$key}' cannot travel in a package.";
            }
        }
    }

    // ------------------------------------------------- homepage resolution
    $homepage = ['ref' => '', 'resolves' => false, 'note' => ''];

    if ($package['has_settings'] && isset($package['settings']['homepage'])) {
        $ref  = (string) $package['settings']['homepage'];
        $homepage['ref'] = $ref;

        if (isset($refs[$ref])) {
            $homepage['resolves'] = true;
            $homepage['note']     = 'Taken from this package.';
        } else {
            $existing = content_package_find_ref($ref);

            if ($existing !== null) {
                $homepage['resolves'] = true;
                $homepage['note']     = 'Matched against the content already on this site.';
            } else {
                $homepage['note'] = 'Not in the package and not on this site: the homepage is left as it is.';
                $warnings[]       = "The homepage '{$ref}' could not be resolved, so it was left unchanged.";
            }
        }
    }

    // Content without a homepage setting still moves the homepage: it is
    // re-matched by path afterwards, so say so (and say what happens if not).
    if ($package['has_content'] && !isset($package['settings']['homepage'])) {
        $before = content_package_ref_of((int) (load_settings()['homepage_id'] ?? 0));

        if ($before !== '') {
            $warnings[] = isset($refs[$before])
                ? "The homepage '{$before}' is in the package and will follow it."
                : "The homepage '{$before}' is not in the package, so the homepage will be unset.";
        }
    }

    $settingsChanges = [];

    if ($package['has_settings']) {
        $current = load_settings();

        foreach ($package['settings'] as $key => $value) {
            if ($key === 'homepage') {
                continue;
            }

            $from = $current[$key] ?? null;

            if ($from !== $value) {
                $settingsChanges[] = [
                    'key'  => (string) $key,
                    'from' => is_scalar($from) ? (string) $from : json_encode($from),
                    'to'   => is_scalar($value) ? (string) $value : json_encode($value),
                ];
            }
        }
    }

    return [
        'problems'     => $problems,
        'warnings'     => $warnings,
        'has_content'  => (bool) $package['has_content'],
        'has_settings' => (bool) $package['has_settings'],
        'create'       => [
            'content'    => $package['has_content'] ? count($package['content']) : 0,
            'taxonomies' => $package['has_content'] ? count($package['taxonomies']) : 0,
            'menus'      => $package['has_content'] ? count($package['menus']) : 0,
            'settings'   => count($settingsChanges),
        ],
        'delete'       => [
            'content'    => $package['has_content'] ? content_package_count('content', 'deleted_at IS NULL') : 0,
            'taxonomies' => $package['has_content'] ? content_package_count('taxonomy') : 0,
            'menus'      => $package['has_content'] ? content_package_count('menus') : 0,
        ],
        'settings'     => $settingsChanges,
        'homepage'     => $homepage,
    ];
}


/**
 * Every component type named in a component tree.
 *
 * @param mixed $components
 * @return list<string>
 */
function content_package_component_types(mixed $components): array
{
    $types = [];

    foreach ((array) $components as $component) {
        if (!is_array($component)) {
            continue;
        }

        $type = (string) ($component['type'] ?? '');

        if ($type !== '') {
            $types[] = $type;
        }

        $types = array_merge($types, content_package_component_types($component['children'] ?? []));
    }

    return array_values(array_unique($types));
}


/**
 * Does this theme (or core) have that component file?
 */
function content_package_component_exists(string $name): bool
{
    return is_file(theme("components/{$name}.php")) || is_file(CORE_PATH . "/components/{$name}.php");
}


/**
 * What a content id points at, as a "<type>:<path>" reference, or ''.
 */
function content_package_ref_of(int $id): string
{
    if ($id <= 0) {
        return '';
    }

    $exported = content_package_rows();

    foreach ($exported['rows'] as $row) {
        if ($row['id'] === $id) {
            return $row['type'] . ':' . $exported['paths'][$id];
        }
    }

    return '';
}


/**
 * Find existing content by "<type>:<path>", or null.
 *
 * @return array<string, mixed>|null
 */
function content_package_find_ref(string $ref): ?array
{
    $parts = explode(':', $ref, 2);

    if (count($parts) !== 2) {
        return null;
    }

    $exported = content_package_rows();

    foreach ($exported['rows'] as $row) {
        if ($row['type'] === $parts[0] && $exported['paths'][$row['id']] === trim($parts[1], '/')) {
            return $row;
        }
    }

    return null;
}


/**
 * Row count for one table, optionally filtered.
 */
function content_package_count(string $table, string $where = ''): int
{
    $sql = "SELECT COUNT(*) FROM {$table}" . ($where !== '' ? " WHERE {$where}" : '');

    return (int) db()->query($sql)->fetchColumn();
}


/**
 * Apply a validated package.
 *
 * Content, taxonomies and menus move as one unit: a menu whose pages are gone
 * is worse than no menu. Settings move separately, so a content-only import
 * leaves the site's configuration alone.
 *
 * @param array<string, mixed> $package
 * @param array<string, mixed> $options 'sitemap' => false to skip writing it (a fresh install has no origin yet)
 * @return array{content: int, taxonomies: int, links: int, menus: int, settings: int, homepage: string}
 */
function content_package_import(array $package, array $options = []): array
{
    $pdo     = db();
    $now     = time();
    $summary = ['content' => 0, 'taxonomies' => 0, 'links' => 0, 'menus' => 0, 'settings' => 0, 'homepage' => '', 'warnings' => []];

    // Replacing content replaces every id, so where the homepage points is
    // remembered as a reference before the rows go.
    $homepageBefore = '';

    if ($package['has_content'] && !isset($package['settings']['homepage'])) {
        $homepageBefore = content_package_ref_of((int) (load_settings()['homepage_id'] ?? 0));
    }

    $pdo->beginTransaction();

    try {
        if ($package['has_content']) {
            // Replace, not merge: a package is a site's content, and half of
            // two sites is nobody's site. Versions and relationships go with
            // it, or they would point at rows that no longer exist.
            $pdo->exec("DELETE FROM content_versions");
            $pdo->exec("DELETE FROM taxonomy_term_relationships");
            $pdo->exec("DELETE FROM content");
            $pdo->exec("DELETE FROM taxonomy");
            $pdo->exec("DELETE FROM menus");

            $ids = [];

            $insert = $pdo->prepare("
                INSERT INTO content (type, slug, parent_id, title, status, layout, header, footer, meta, body,
                                     published_at, scheduled_at, created_at, updated_at)
                VALUES (:type, :slug, NULL, :title, :status, :layout, :header, :footer, :meta, :body,
                        :published_at, :scheduled_at, :created_at, :updated_at)
            ");

            foreach ($package['content'] as $item) {
                $path  = trim((string) $item['path'], '/');
                $parts = explode('/', $path);
                $slug  = (string) end($parts);

                $insert->execute([
                    'type'         => (string) $item['type'],
                    'slug'         => $slug,
                    'title'        => (string) ($item['title'] ?? $slug),
                    'status'       => (string) ($item['status'] ?? 'draft'),
                    'layout'       => (string) ($item['layout'] ?? ''),
                    'header'       => (string) ($item['header'] ?? ''),
                    'footer'       => (string) ($item['footer'] ?? ''),
                    'meta'         => json_encode($item['meta'] ?? [], JSON_UNESCAPED_SLASHES),
                    'body'         => json_encode($item['body'] ?? [], JSON_UNESCAPED_SLASHES),
                    'published_at' => isset($item['published_at']) ? (int) $item['published_at'] : null,
                    'scheduled_at' => isset($item['scheduled_at']) ? (int) $item['scheduled_at'] : null,
                    'created_at'   => isset($item['created_at']) ? (int) $item['created_at'] : $now,
                    'updated_at'   => isset($item['updated_at']) ? (int) $item['updated_at'] : $now,
                ]);

                $ids[(string) $item['type'] . ':' . $path] = (int) $pdo->lastInsertId();
                $summary['content']++;
            }

            // Parents second: every row exists by now.
            $setParent = $pdo->prepare("UPDATE content SET parent_id = :parent WHERE id = :id");

            foreach ($package['content'] as $item) {
                $parent = trim((string) ($item['parent'] ?? ''));

                if ($parent === '') {
                    continue;
                }

                $ref = (string) $item['type'] . ':' . trim((string) $item['path'], '/');

                if (isset($ids[$parent], $ids[$ref])) {
                    $setParent->execute(['parent' => $ids[$parent], 'id' => $ids[$ref]]);
                }
            }

            // Taxonomy terms, then the links between them and the content.
            $termIds = [];

            $insertTerm = $pdo->prepare("
                INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, description, created_at, updated_at)
                VALUES (:taxonomy_type, :content_type, :name, :slug, :description, :now, :now)
            ");

            foreach ($package['taxonomies'] as $term) {
                $insertTerm->execute([
                    'taxonomy_type' => (string) $term['type'],
                    'content_type'  => (string) $term['content_type'],
                    'name'          => (string) ($term['name'] ?? $term['slug']),
                    'slug'          => (string) $term['slug'],
                    'description'   => (string) ($term['description'] ?? ''),
                    'now'           => $now,
                ]);

                $termIds[(string) $term['type'] . ':' . (string) $term['content_type'] . ':' . (string) $term['slug']]
                    = (int) $pdo->lastInsertId();
                $summary['taxonomies']++;
            }

            $insertLink = $pdo->prepare("
                INSERT OR IGNORE INTO taxonomy_term_relationships (content_type, content_id, taxonomy_id)
                VALUES (:content_type, :content_id, :taxonomy_id)
            ");

            foreach ($package['taxonomies'] as $term) {
                $termKey = (string) $term['type'] . ':' . (string) $term['content_type'] . ':' . (string) $term['slug'];

                foreach ((array) ($term['content'] ?? []) as $ref) {
                    if (!isset($termIds[$termKey], $ids[(string) $ref])) {
                        continue;
                    }

                    $insertLink->execute([
                        'content_type' => (string) $term['content_type'],
                        'content_id'   => $ids[(string) $ref],
                        'taxonomy_id'  => $termIds[$termKey],
                    ]);
                    $summary['links']++;
                }
            }

            $insertMenu = $pdo->prepare("
                INSERT INTO menus (label, slug, items, updated_at) VALUES (:label, :slug, :items, :now)
            ");

            foreach ($package['menus'] as $menu) {
                $items = is_array($menu['items'] ?? null) ? $menu['items'] : [];

                $insertMenu->execute([
                    'label' => (string) $menu['label'],
                    'slug'  => (string) $menu['slug'],
                    // Content is in place by now, so menu links can point at this
                    // install's rows instead of the package's slugs alone.
                    'items' => json_encode(menu_items_attach_ids($items), JSON_UNESCAPED_SLASHES),
                    'now'   => $now,
                ]);
                $summary['menus']++;
            }
        }

        // The homepage the site already had, re-matched by path: importing the
        // same content back must not leave '/' serving the 404 page.
        if ($homepageBefore !== '') {
            if (isset($ids[$homepageBefore])) {
                save_settings(['homepage_id' => $ids[$homepageBefore]]);
                $summary['homepage'] = $homepageBefore;
            } else {
                // Nothing here matches it any more, so the setting is cleared
                // rather than left pointing at a row that is gone.
                $pdo->prepare("DELETE FROM settings WHERE `key` = 'homepage_id'")->execute();
                settings_cache_clear();
                $summary['warnings'][] = "The homepage '{$homepageBefore}' is not in this package, so the homepage is unset. Choose one in Settings.";
            }
        }

        if ($package['has_settings']) {
            $values = [];

            foreach ($package['settings'] as $key => $value) {
                if ($key === 'homepage') {
                    continue;
                }

                $values[(string) $key] = $value;
            }

            // The homepage is an id again by the time it is stored; resolve the
            // package's reference against what now exists.
            if (isset($package['settings']['homepage'])) {
                $ref   = (string) $package['settings']['homepage'];
                $found = $package['has_content'] && isset($ids[$ref]) ? $ids[$ref] : null;
                $row   = $found === null ? content_package_find_ref($ref) : null;

                if ($found === null && $row !== null) {
                    $found = (int) $row['id'];
                }

                if ($found !== null) {
                    $values['homepage_id'] = $found;
                    $summary['homepage']   = $ref;
                }
            }

            if ($values) {
                save_settings($values);
                $summary['settings'] = count($values);
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $exception;
    }

    search_reindex_all();
    invalidate_cache();

    if (($options['sitemap'] ?? true) !== false) {
        save_sitemap();
    }

    return $summary;
}


/*
|--------------------------------------------------------------------------
| Uploaded package files
|--------------------------------------------------------------------------
|
| An import is two steps — preview, then apply — and the file has to survive
| between them, so an upload is stashed under storage/imports/<token>/ for an
| hour. The theme's own demo files need none of this; they are read twice.
|
| The token is the only thing the browser sends back, so it is matched against
| a strict pattern before it is used as a path.
|--------------------------------------------------------------------------
*/

/**
 * One entry per uploaded file, from $_FILES['files'].
 *
 * @param array<string, mixed> $files
 * @return list<array{name: string, tmp_name: string, error: int, size: int}>
 */
function content_package_uploads(array $files): array
{
    if (!isset($files['name'])) {
        return [];
    }

    $names = is_array($files['name']) ? array_keys($files['name']) : [0];
    $out   = [];

    foreach ($names as $index) {
        $out[] = [
            'name'     => (string) (is_array($files['name']) ? $files['name'][$index] : $files['name']),
            'tmp_name' => (string) (is_array($files['tmp_name'] ?? null) ? ($files['tmp_name'][$index] ?? '') : ($files['tmp_name'] ?? '')),
            'error'    => (int) (is_array($files['error'] ?? null) ? ($files['error'][$index] ?? UPLOAD_ERR_NO_FILE) : ($files['error'] ?? UPLOAD_ERR_NO_FILE)),
            'size'     => (int) (is_array($files['size'] ?? null) ? ($files['size'][$index] ?? 0) : ($files['size'] ?? 0)),
        ];
    }

    return $out;
}


/**
 * Were any files actually chosen?
 *
 * @param list<array{error: int}> $uploads
 */
function content_package_uploads_present(array $uploads): bool
{
    foreach ($uploads as $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            return true;
        }
    }

    return false;
}


/**
 * Store uploaded package files for the apply step.
 *
 * @param list<array{name: string, tmp_name: string, error: int, size: int}> $uploads
 * @return array{token: string, count: int, errors: list<string>}
 */
function content_package_stash_store(array $uploads): array
{
    $token   = bin2hex(random_bytes(12));
    $root    = STORAGE_PATH . '/imports';
    $dir     = $root . '/' . $token;
    $errors  = [];
    $stored  = 0;

    foreach ($uploads as $index => $file) {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = $file['name'] . ': the upload failed.';
            continue;
        }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            $errors[] = 'The upload could not be stored.';
            break;
        }

        if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $index . '.json')) {
            $errors[] = $file['name'] . ': the upload could not be stored.';
            continue;
        }

        $stored++;
    }

    if ($stored === 0) {
        @rmdir($dir);
        $token = '';
    }

    return ['token' => $token, 'count' => $stored, 'errors' => $errors];
}


/**
 * Read stashed documents back.
 *
 * @return array{documents: list<array<string, mixed>>, errors: list<string>}
 */
function content_package_stash_read(string $token): array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
        return ['documents' => [], 'errors' => ['That import could not be found.']];
    }

    $dir = STORAGE_PATH . '/imports/' . $token;

    if (!is_dir($dir)) {
        return ['documents' => [], 'errors' => ['That import has expired. Upload the files again.']];
    }

    $documents = [];
    $errors    = [];

    foreach (glob($dir . '/*.json') ?: [] as $file) {
        $parsed = content_package_parse((string) file_get_contents($file));

        if ($parsed['document'] === null) {
            $errors[] = basename($file) . ': ' . $parsed['error'];
            continue;
        }

        $documents[] = $parsed['document'];
    }

    return ['documents' => $documents, 'errors' => $errors];
}


/**
 * Throw a stash away.
 */
function content_package_stash_forget(string $token): void
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
        return;
    }

    foreach (glob(STORAGE_PATH . '/imports/' . $token . '/*') ?: [] as $file) {
        @unlink($file);
    }

    @rmdir(STORAGE_PATH . '/imports/' . $token);
}


/**
 * Drop stashes nobody came back to.
 */
function content_package_stash_prune(int $olderThan = 3600): void
{
    foreach (glob(STORAGE_PATH . '/imports/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (filemtime($dir) >= time() - $olderThan) {
            continue;
        }

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }
}
