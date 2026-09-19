<?php
// theme/components/blog-list-section.php

return [

/** --------------------------------------------
 * User-facing name/label
 * -------------------------------------------- */
'label' => 'Paginated List',

/** --------------------------------------------
 * CMS-Editable Schema
 * -------------------------------------------- */
'schema' => [
    'title' => [
        'type' => 'text',
        'label' => 'Section title',
        'required' => false,
        'default' => 'Latest posts',
    ],
    // Options are filled from theme.php's content_types, so a theme author
    // decides what can be listed here.
    'content_type' => [
        'type' => 'select',
        'label' => 'Content type to list',
        'required' => true,
        'default' => 'blog_post',
    ],
    'per_page' => [
        'type' => 'number',
        'label' => 'Items per page',
        'required' => true,
        'default' => 6,
        'min' => 1,
        'max' => 48,
    ],
    'show_date' => [
        'type' => 'checkbox',
        'label' => 'Show publish date',
        'required' => false,
        'default' => true,
    ],
    'show_excerpt' => [
        'type' => 'checkbox',
        'label' => 'Show excerpt',
        'required' => false,
        'default' => true,
    ],
],

/** --------------------------------------------
 * Child element options
 * -------------------------------------------- */
'children' => 'none',
'allowed_children' => [],

/** --------------------------------------------
 * Component CSS (optional)
 * -------------------------------------------- */
'css' => <<<CSS
.list-section-items { list-style: none; padding: 0; margin: 0; }
.list-section-item { padding: 1.25rem 0; border-bottom: 1px solid rgba(0, 0, 0, 0.1); }
.list-section-item h3 { margin: 0 0 0.25rem; }
.list-section-meta { color: #6c757d; font-size: 0.875rem; margin-bottom: 0.35rem; }
.list-section-excerpt { margin: 0; }
CSS,

/** --------------------------------------------
 * Render function
 * -------------------------------------------- */
'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
    $id = 'list-section-' . uniqid();

    $theme    = theme_config();
    $settings = load_settings();

    $type = (string) ($props['content_type'] ?? 'blog_post');

    // Only list types the theme declares. An unknown type renders nothing
    // rather than querying content the theme knows nothing about.
    if (!isset($theme['content_types'][$type])) {
        return;
    }

    $perPage = max(1, min(48, (int) ($props['per_page'] ?? 6)));

    $baseUrl = url(trim((string) ($page['path'] ?? ''), '/'));

    // One page number per page, since `?page` belongs to the URL rather than
    // to one component. A page should carry a single paginated listing.
    $result = list_content_page($type, pagination_current_page(), $perPage, [], $baseUrl);

    $title = (string) ($props['title'] ?? '');
    ?>
    <section id="<?= e($id) ?>" class="py-5">
        <div class="container px-5">
            <?php if ($title !== ''): ?>
                <h2 class="fw-bolder mb-4"><?= e($title) ?></h2>
            <?php endif; ?>

            <?php if (!$result['items']): ?>
                <p class="text-muted">Nothing published yet.</p>
            <?php else: ?>
                <ul class="list-section-items">
                    <?php foreach ($result['items'] as $item): ?>
                        <?php
                        $ctConfig = $theme['content_types'][$type] ?? [];
                        $prefix   = $settings['content_prefixes'][$type] ?? $ctConfig['url_prefix'] ?? '';
                        $itemUrl  = url(($prefix ? $prefix . '/' : '') . (string) $item['slug']);
                        ?>
                        <li class="list-section-item">
                            <h3><a class="link-dark" href="<?= e($itemUrl) ?>"><?= e($item['title']) ?></a></h3>

                            <?php if (!empty($props['show_date']) && !empty($item['published_at'])): ?>
                                <div class="list-section-meta"><?= e(format_date((int) $item['published_at'])) ?></div>
                            <?php endif; ?>

                            <?php if (!empty($props['show_excerpt']) && !empty($item['meta']['excerpt'])): ?>
                                <p class="list-section-excerpt text-muted"><?= e($item['meta']['excerpt']) ?></p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <?php
                // The pager markup is shared with the archive layouts.
                $pagination = $result;
                $pagerUrl   = $baseUrl;
                $pagerLabel = 'List pages';
                require theme('partials/pager.php');
                ?>
            <?php endif; ?>
        </div>
    </section>
    <?php
},

];
