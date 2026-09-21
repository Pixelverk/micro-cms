<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Content status model, visibility and scheduled publishing
|--------------------------------------------------------------------------
*/

require __DIR__ . '/bootstrap.php';
test_fresh_database();

// ---------------------------------------------------------------------------
// Status resolution
// ---------------------------------------------------------------------------

t('resolve_content_status() covers every transition', function () {
    $now = 1_800_000_000;
    $future = $now + 3600;

    $draft = resolve_content_status(['status' => 'draft'], $now);
    assert_eq('draft', $draft['status']);
    assert_eq(null, $draft['published_at']);
    assert_eq(null, $draft['scheduled_at']);

    $live = resolve_content_status(['status' => 'published'], $now);
    assert_eq('published', $live['status']);
    assert_eq($now, $live['published_at'], 'publishing stamps "now"');

    $scheduled = resolve_content_status(['status' => 'scheduled', 'scheduled_at' => $future], $now);
    assert_eq('scheduled', $scheduled['status']);
    assert_eq($future, $scheduled['scheduled_at']);
    assert_eq(null, $scheduled['published_at'], 'scheduled is not yet published');

    // Publishing with a future date is really scheduling.
    $implied = resolve_content_status(['status' => 'published', 'scheduled_at' => $future], $now);
    assert_eq('scheduled', $implied['status']);

    $archived = resolve_content_status(['status' => 'archived'], $now);
    assert_eq('archived', $archived['status']);
    assert_eq(null, $archived['published_at']);

    // Unknown statuses fall back to draft rather than being stored.
    $bogus = resolve_content_status(['status' => 'exploded'], $now);
    assert_eq('draft', $bogus['status']);

    // A second save keeps the original go-live date.
    $resave = resolve_content_status(['status' => 'published', 'published_at' => $now - 500], $now);
    assert_eq($now - 500, $resave['published_at'], 'published_at is preserved');
});

t('content_statuses() lists the workflow in order', function () {
    assert_eq(['draft', 'scheduled', 'published', 'archived'], content_statuses());
});

// ---------------------------------------------------------------------------
// Visibility
// ---------------------------------------------------------------------------

t('anonymous visitors cannot see drafts, archived or future content', function () {
    $_SESSION = [];

    $visible = seed_content(['slug' => 'visible-page', 'status' => 'published', 'published_at' => time() - 10]);
    $draft   = seed_content(['slug' => 'draft-page', 'status' => 'draft', 'published_at' => null]);
    $archived = seed_content(['slug' => 'archived-page', 'status' => 'archived', 'published_at' => null]);
    $future  = seed_content(['slug' => 'future-page', 'status' => 'published', 'published_at' => time() + 3600]);

    assert_true(load_content_by_id($visible) !== null, 'published content is visible');
    assert_eq(null, load_content_by_id($draft), 'drafts are hidden');
    assert_eq(null, load_content_by_id($archived), 'archived content is hidden');
    assert_eq(null, load_content_by_id($future), 'future-dated content is hidden');

    assert_true(load_content_by_slug('visible-page') !== null);
    assert_eq(null, load_content_by_slug('draft-page'), 'draft slug must 404 for visitors');
    assert_eq(null, load_content_by_slug('future-page'), 'future slug must 404 for visitors');
});

t('signed-in editors see unpublished content only when previewing', function () {
    test_login_session();

    $draft = seed_content(['slug' => 'editor-draft', 'status' => 'draft', 'published_at' => null]);

    // Signed in but no preview token in the URL: behaves like a visitor, so an
    // editor browsing normally cannot fill the public cache with drafts.
    assert_eq(null, load_content_by_id($draft), 'no preview token means no drafts');
    assert_eq(null, load_content_by_slug('editor-draft'), 'no preview token means 404');

    // With the token, drafts become visible.
    test_preview_request();
    assert_true(load_content_by_id($draft) !== null, 'editors see drafts by id while previewing');
    assert_true(load_content_by_slug('editor-draft') !== null, 'editors see drafts by slug while previewing');

    test_request('GET', '/');
});

t('the visibility fragment is relaxed for preview requests only', function () {
    $_SESSION = [];
    assert_contains("status = 'published'", content_visibility_sql()['sql'], 'visitors get a filter');

    test_login_session();
    assert_contains("status = 'published'", content_visibility_sql()['sql'], 'a signed-in visit is still filtered');

    test_preview_request();
    assert_not_contains("status = 'published'", content_visibility_sql()['sql'], 'previewers lose the status filter');
    assert_contains('deleted_at IS NULL', content_visibility_sql()['sql'], 'trash stays hidden even in preview');

    test_request('GET', '/');
});

t('list_content() filters by visibility and optional status', function () {
    $_SESSION = [];
    seed_content(['slug' => 'list-published-1', 'status' => 'published', 'published_at' => time() - 5]);
    seed_content(['slug' => 'list-draft-1', 'status' => 'draft', 'published_at' => null]);

    $publishedSlugs = array_column(list_content('page', ['status' => 'published']), 'slug');
    $draftSlugs     = array_column(list_content('page', ['status' => 'draft']), 'slug');

    assert_count(0, $draftSlugs, 'drafts are invisible to visitors');
    assert_true(in_array('list-published-1', $publishedSlugs, true), 'published row is listed');
    assert_false(in_array('list-draft-1', $publishedSlugs, true), 'draft row is not listed');

    // An editor in preview sees the drafts (other tests may have created some too).
    test_preview_request();
    $editorDraftSlugs = array_column(list_content('page', ['status' => 'draft']), 'slug');
    assert_true(in_array('list-draft-1', $editorDraftSlugs, true), 'previewers see drafts');
    test_request('GET', '/');
});

t('search filtering narrows list_content()', function () {
    $_SESSION = [];
    seed_content(['slug' => 'find-me-please', 'title' => 'Findable Page', 'status' => 'published', 'published_at' => time() - 5]);

    $hits = list_content('page', ['q' => 'Findable']);
    assert_count(1, $hits);
    assert_eq('Findable Page', $hits[0]['title']);

    assert_count(0, list_content('page', ['q' => 'NothingLikeThis']));
});

// ---------------------------------------------------------------------------
// Scheduled publishing
// ---------------------------------------------------------------------------

t('publish_due_content() publishes only what is due', function () {
    $due = seed_content([
        'slug'         => 'scheduled-due',
        'status'       => 'scheduled',
        'published_at' => null,
        'scheduled_at' => time() - 60,
    ]);

    $later = seed_content([
        'slug'         => 'scheduled-later',
        'status'       => 'scheduled',
        'published_at' => null,
        'scheduled_at' => time() + 3600,
    ]);

    $published = publish_due_content();

    assert_true(in_array($due, $published, true), 'the due item was published');
    assert_false(in_array($later, $published, true), 'the future item was left alone');

    $stmt = db()->prepare("SELECT status, published_at, scheduled_at FROM content WHERE id = :id");

    $stmt->execute(['id' => $due]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    assert_eq('published', $row['status']);
    assert_eq(null, $row['scheduled_at'], 'scheduled_at is cleared');
    assert_true((int) $row['published_at'] > 0, 'published_at is stamped');

    $stmt->execute(['id' => $later]);
    assert_eq('scheduled', $stmt->fetch(PDO::FETCH_ASSOC)['status']);
});

t('publish_due_content() is a no-op when nothing is due', function () {
    db()->exec("UPDATE content SET status = 'draft', scheduled_at = NULL");

    assert_count(0, publish_due_content());
});

t('publishing_check() respects its once-a-minute marker', function () {
    $marker = STORAGE_PATH . '/.publish-check';
    @unlink($marker);

    $due = seed_content([
        'slug'         => 'marker-due',
        'status'       => 'scheduled',
        'published_at' => null,
        'scheduled_at' => time() - 5,
    ]);

    publishing_check();

    $status = db()->prepare("SELECT status FROM content WHERE id = :id");
    $status->execute(['id' => $due]);
    assert_eq('published', $status->fetchColumn(), 'first call publishes');

    // A second due item must not be picked up within the same minute.
    $second = seed_content([
        'slug'         => 'marker-second',
        'status'       => 'scheduled',
        'published_at' => null,
        'scheduled_at' => time() - 5,
    ]);

    publishing_check();

    $status->execute(['id' => $second]);
    assert_eq('scheduled', $status->fetchColumn(), 'the marker suppresses a second run');

    // Force the marker stale and confirm it runs again.
    touch($marker, time() - 120);
    publishing_check();

    $status->execute(['id' => $second]);
    assert_eq('published', $status->fetchColumn(), 'a stale marker allows another run');

    @unlink($marker);
});

// ---------------------------------------------------------------------------
// Rich-text-only content types
// ---------------------------------------------------------------------------

t('a rich-text content type resolves the component it writes', function () {
    $types = theme_config()['content_types'];

    assert_eq(
        ['component' => 'quill-editor', 'field' => 'content'],
        content_rich_text_editor($types['blog_post']),
        'a post is written with the rich-text component its palette names'
    );

    assert_eq(null, content_rich_text_editor($types['page']), 'a component type has no single rich-text field');
});

t('the body of a rich-text content type is exactly its one component', function () {
    $blog = theme_config()['content_types']['blog_post'];
    $component = [
        'type'     => 'quill-editor',
        'props'    => ['content' => '<p>Kept</p>', 'extra' => 'dropped'],
        'children' => [['type' => 'anything', 'props' => [], 'children' => []]],
    ];

    assert_eq(
        [['type' => 'quill-editor', 'props' => ['content' => '<p>Kept</p>'], 'children' => []]],
        content_rich_text_body($blog, [$component]),
        'only the declared field survives, and the extra component is dropped'
    );

    // A hand-made POST with a second component is reduced to the one field: the
    // editor could never hydrate the rest.
    $twoComponents = [
        $component,
        ['type' => 'hero-section', 'props' => ['title' => 'Extra'], 'children' => []],
    ];

    assert_eq(
        [['type' => 'quill-editor', 'props' => ['content' => '<p>Kept</p>'], 'children' => []]],
        content_rich_text_body($blog, $twoComponents),
        'a second component cannot reach the stored body'
    );

    // A component content type keeps the tree the editor posted.
    $body = [['type' => 'hero-section', 'props' => ['title' => 'Hi'], 'children' => []]];

    assert_eq($body, content_rich_text_body(theme_config()['content_types']['page'], $body));
});

// ---------------------------------------------------------------------------
// Content-type meta fields
// ---------------------------------------------------------------------------

t('a declared meta field gets a type and a label', function () {
    $fields = content_meta_fields([
        'fields' => [
            'project_url' => ['type' => 'url', 'label' => 'Project link'],
            'subtitle'    => [],
            'author_role' => ['label' => 'Author role'],
        ],
    ]);

    assert_eq('url', $fields['project_url']['type']);
    assert_eq('Project link', $fields['project_url']['label']);

    // An entry with no type is text, and an unlabelled key becomes its name.
    assert_eq('text', $fields['subtitle']['type']);
    assert_eq('Subtitle', $fields['subtitle']['label']);
    assert_eq('Author role', $fields['author_role']['label']);

    assert_eq([], content_meta_fields([]), 'a content type that declares none has none');
});

t('the meta field value falls back to the default', function () {
    $field = ['type' => 'text', 'default' => 'Fallback'];

    assert_eq('Typed', content_meta_field_value($field, 'Typed'));
    assert_eq('Fallback', content_meta_field_value($field, ''));
    assert_eq('Fallback', content_meta_field_value($field, null));
    assert_eq('', content_meta_field_value($field, ['not', 'scalar']));
});

t('collecting meta fields trims, caps and clears them', function () {
    $fields = content_meta_fields([
        'fields' => [
            'project_url' => ['type' => 'url'],
            'excerpt'     => ['type' => 'textarea', 'max' => 5],
            'featured'    => ['type' => 'checkbox'],
        ],
    ]);

    $meta = content_collect_meta_fields(
        ['meta_project_url' => '  https://example.com/x  ', 'meta_excerpt' => '123456789', 'meta_featured' => '1'],
        [],
        $fields
    );

    assert_eq('https://example.com/x', $meta['project_url'], 'trimmed');
    assert_eq('12345', $meta['excerpt'], 'capped at max');
    assert_eq(true, $meta['featured'], 'a checked box is true');

    // A blank value clears the key rather than storing an empty string.
    $cleared = content_collect_meta_fields(['meta_project_url' => '   '], ['project_url' => 'old'], $fields);
    assert_true(!array_key_exists('project_url', $cleared), 'a blank field unsets the key');

    // An unchecked checkbox submits an empty value.
    $unchecked = content_collect_meta_fields(['meta_featured' => ''], ['featured' => true], $fields);
    assert_eq(false, $unchecked['featured'], 'an unchecked box is false');

    // A field the form did not submit is left alone, so partial saves keep it.
    $untouched = content_collect_meta_fields([], ['project_url' => 'kept'], $fields);
    assert_eq('kept', $untouched['project_url']);
});

t('a declared meta field is checked by its type', function () {
    $url = ['type' => 'url', 'label' => 'Project link'];

    assert_eq('', content_meta_field_error($url, 'https://example.com/x'), 'an absolute address passes');
    assert_eq('', content_meta_field_error($url, ''), 'blank is not an error');
    assert_contains('Project link', content_meta_field_error($url, '#'), 'a bare fragment is rejected');
    assert_contains('Project link', content_meta_field_error($url, '/relative/'), 'a relative path is rejected');

    $email = ['type' => 'email', 'label' => 'Contact'];
    assert_eq('', content_meta_field_error($email, 'hello@example.com'));
    assert_contains('Contact', content_meta_field_error($email, 'not-an-email'));

    $number = ['type' => 'number', 'label' => 'Order'];
    assert_eq('', content_meta_field_error($number, '12'));
    assert_contains('Order', content_meta_field_error($number, 'twelve'));

    $select = ['type' => 'select', 'label' => 'Size', 'options' => ['s' => 'Small', 'l' => 'Large']];
    assert_eq('', content_meta_field_error($select, 's'));
    assert_contains('Size', content_meta_field_error($select, 'xl'), 'a value not offered is rejected');

    // A text field takes anything, and a checkbox is never a validation error.
    assert_eq('', content_meta_field_error(['type' => 'text', 'label' => 'Note'], 'anything'));
    assert_eq('', content_meta_field_error(['type' => 'checkbox', 'label' => 'On'], 'yes'));
});

// ---------------------------------------------------------------------------
// Pre-publish checklist
// ---------------------------------------------------------------------------

/**
 * One checklist rule's result, or a failure if the rule is missing.
 */
function checklist_item(array $items, string $rule): array
{
    foreach ($items as $item) {
        if (($item['rule'] ?? '') === $rule) {
            return $item;
        }
    }

    throw new RuntimeException("checklist rule '{$rule}' is missing");
}

/** A page body with a single component. */
function checklist_body(string $type, array $props, array $children = []): array
{
    return [['type' => $type, 'props' => $props, 'children' => $children]];
}

t('the pre-publish checklist passes a complete item', function () {
    $items = content_publish_checklist([
        'title' => 'Complete',
        'meta'  => ['description' => 'A description'],
        'body'  => checklist_body('team-member', [
            'name' => 'Ada', 'role' => 'Engineer', 'image' => 'ada.png', 'image_alt' => 'Ada at a desk',
        ]),
    ]);

    assert_count(0, content_checklist_blockers($items), 'nothing blocks');
    assert_true(checklist_item($items, 'image_alt')['ok'], 'the image is described');
    assert_true(checklist_item($items, 'links')['ok'], 'there are no dead links');
    assert_true(checklist_item($items, 'description')['ok'], 'the description is present');
});

t('an empty title blocks publishing', function () {
    $items = content_publish_checklist([
        'title' => '   ',
        'meta'  => ['description' => 'Desc'],
        'body'  => checklist_body('hero-section', ['title' => 'T', 'subtitle' => 'S']),
    ]);

    assert_false(checklist_item($items, 'title')['ok']);
    assert_count(1, content_checklist_blockers($items), 'the title is the only blocker');
});

t('an empty required component field blocks publishing', function () {
    $items = content_publish_checklist([
        'title' => 'Page',
        'meta'  => ['description' => 'Desc'],
        'body'  => checklist_body('team-member', ['name' => '', 'role' => 'Engineer', 'image' => 'ada.png', 'image_alt' => 'Alt']),
    ]);

    $required = checklist_item($items, 'required');
    assert_false($required['ok']);
    assert_contains('Name', $required['detail'], 'the missing field is named');
    assert_count(1, content_checklist_blockers($items));
});

t('the checklist walks nested components', function () {
    $items = content_publish_checklist([
        'title' => 'Nested',
        'meta'  => ['description' => 'Desc'],
        'body'  => checklist_body(
            'cta-section',
            ['title' => 'T', 'text' => 'X', 'url' => '#', 'linktext' => 'Go'],
            checklist_body('team-member', ['name' => '', 'role' => 'R', 'image' => 'i.png', 'image_alt' => 'Alt'])
        ),
    ]);

    assert_false(checklist_item($items, 'required')['ok'], 'a nested required field is found');
    assert_contains('Name', checklist_item($items, 'required')['detail']);
});

t('an image without a description warns but does not block', function () {
    $items = content_publish_checklist([
        'title' => 'Page',
        'meta'  => ['description' => 'Desc'],
        'body'  => checklist_body('team-member', ['name' => 'Ada', 'role' => 'Engineer', 'image' => 'ada.png', 'image_alt' => '']),
    ]);

    $alt = checklist_item($items, 'image_alt');
    assert_false($alt['ok']);
    assert_eq('warn', $alt['level']);
    assert_count(0, content_checklist_blockers($items), 'a warning never blocks');
});

t('a link label with no target warns', function () {
    $items = content_publish_checklist([
        'title' => 'Page',
        'meta'  => ['description' => 'Desc'],
        'body'  => checklist_body('hero-section', [
            'title' => 'T', 'subtitle' => 'S',
            'btn1_url' => '', 'btn1_text' => 'Go',
            'btn2_url' => '', 'btn2_text' => '',
        ]),
    ]);

    $links = checklist_item($items, 'links');
    assert_false($links['ok']);
    assert_contains('Btn 1 url', $links['detail']);
    assert_count(0, content_checklist_blockers($items));

    // An optional URL with no label is not a visible link, so it is ignored.
    $ignored = content_publish_checklist([
        'title' => 'Page',
        'meta'  => ['description' => 'Desc'],
        'body'  => checklist_body('contact-section', ['form_type' => 'contact', 'redirect_url' => '']),
    ]);

    assert_true(checklist_item($ignored, 'links')['ok'], 'a URL with no label is not a dead link');
});

t('a missing meta description warns', function () {
    $items = content_publish_checklist([
        'title' => 'Page',
        'meta'  => [],
        'body'  => checklist_body('hero-section', ['title' => 'T', 'subtitle' => 'S']),
    ]);

    $description = checklist_item($items, 'description');
    assert_false($description['ok']);
    assert_eq('warn', $description['level']);
    assert_count(0, content_checklist_blockers($items));
});

// ---------------------------------------------------------------------------
// Presentation images (theme-declared content meta)
// ---------------------------------------------------------------------------

/** Insert a media row and return its id. */
function checklist_seed_media(string $altText = 'A library image'): int
{
    $now = time();

    $stmt = db()->prepare("
        INSERT INTO media (original_name, base_path, mime_type, original_size, width, height,
                           sizes_json, formats_json, lqip_base64, alt_text, description,
                           created_at, updated_at)
        VALUES ('photo.jpg', '2026/03/checklist', 'image/jpeg', 1000, 800, 600,
                '{}', '{}', NULL, ?, NULL, ?, ?)
    ");
    $stmt->execute([$altText, $now, $now]);

    return (int) db()->lastInsertId();
}

t('a media library image carries its own description', function () {
    $described = checklist_seed_media('Sunset over water');

    $withAlt = content_publish_checklist([
        'title' => 'Page',
        'meta'  => ['description' => 'Desc'],
        'body'  => checklist_body('team-member', [
            'name' => 'Ada', 'role' => 'Engineer', 'image' => (string) $described, 'image_alt' => '',
        ]),
    ]);

    assert_true(checklist_item($withAlt, 'image_alt')['ok'], 'the media row supplies the alt text');

    // The same shape with no alt anywhere is still a warning.
    $bare = checklist_seed_media('');

    $withoutAlt = content_publish_checklist([
        'title' => 'Page',
        'meta'  => ['description' => 'Desc'],
        'body'  => checklist_body('team-member', [
            'name' => 'Ada', 'role' => 'Engineer', 'image' => (string) $bare, 'image_alt' => '',
        ]),
    ]);

    assert_false(checklist_item($withoutAlt, 'image_alt')['ok']);
});

t('content_collect_images() keeps, clears and reindexes image meta', function () {
    $fields = [
        'thumbnail' => ['label' => 'Featured image'],
        'gallery'   => ['label' => 'Gallery', 'multiple' => true],
    ];

    // A field the form did not submit is left untouched (partial saves).
    assert_eq(['thumbnail' => '7'], content_collect_images([], ['thumbnail' => '7'], $fields));

    $collected = content_collect_images(
        ['meta_thumbnail' => ' 12 ', 'meta_gallery' => ['', '5', ' 6 ', '']],
        [],
        $fields
    );

    assert_eq('12', $collected['thumbnail'], 'the single value is trimmed');
    assert_eq(['5', '6'], $collected['gallery'], 'blank sentinel rows are dropped and the list reindexed');

    // The editor always posts the gallery, even when every row was removed.
    $cleared = content_collect_images(
        ['meta_thumbnail' => '', 'meta_gallery' => ['']],
        ['thumbnail' => '12', 'gallery' => ['5']],
        $fields
    );

    assert_false(array_key_exists('thumbnail', $cleared), 'a cleared thumbnail is removed');
    assert_false(array_key_exists('gallery', $cleared), 'an emptied gallery is removed');
});

// ---------------------------------------------------------------------------
// Taxonomy delete
// ---------------------------------------------------------------------------

t('deleting a term takes its links with it', function () {
    $pdo = db();

    $pdo->prepare("INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, created_at, updated_at) VALUES ('category', 'blog_post', 'Bulk One', 'bulk-one', :now, :now)")
        ->execute(['now' => time()]);
    $first = (int) $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, created_at, updated_at) VALUES ('category', 'blog_post', 'Bulk Two', 'bulk-two', :now, :now)")
        ->execute(['now' => time()]);
    $second = (int) $pdo->lastInsertId();

    // One post carries both, so the links have something to be removed from.
    $post = (int) $pdo->query("SELECT id FROM content WHERE type = 'blog_post' LIMIT 1")->fetchColumn();

    $link = $pdo->prepare("INSERT INTO taxonomy_term_relationships (content_type, content_id, taxonomy_id) VALUES ('blog_post', ?, ?)");

    foreach ([$first, $second] as $term) {
        $link->execute([$post, $term]);
    }

    assert_eq('Bulk One', taxonomy_delete('category', $first), 'the term name comes back');
    assert_eq('', taxonomy_delete('category', $first), 'deleting it twice reports nothing to delete');

    assert_eq(
        0,
        (int) $pdo->query("SELECT COUNT(*) FROM taxonomy_term_relationships WHERE taxonomy_id = {$first}")->fetchColumn(),
        'its links are gone with it'
    );
    assert_eq(
        1,
        (int) $pdo->query("SELECT COUNT(*) FROM taxonomy_term_relationships WHERE taxonomy_id = {$second}")->fetchColumn(),
        'the other term keeps its own'
    );

    // A term of another kind is not touched by a mismatched kind.
    assert_eq('', taxonomy_delete('tag', $second), 'the kind has to match');
});

t('bulk_delete_taxonomies() removes what it can and counts the rest', function () {
    $pdo = db();

    $ids = [];

    foreach (['bulk-three', 'bulk-four'] as $slug) {
        $pdo->prepare("INSERT INTO taxonomy (taxonomy_type, content_type, name, slug, created_at, updated_at) VALUES ('tag', 'blog_post', :name, :slug, :now, :now)")
            ->execute(['name' => ucfirst($slug), 'slug' => $slug, 'now' => time()]);
        $ids[] = (int) $pdo->lastInsertId();
    }

    $result = bulk_delete_taxonomies('tag', array_merge($ids, [999999, 0]));

    assert_eq(2, $result['removed'], 'the terms that existed are removed');
    assert_eq(2, $result['skipped'], 'the ids that did not resolve are counted, not fatal');

    assert_eq(
        0,
        (int) $pdo->query("SELECT COUNT(*) FROM taxonomy WHERE id IN ({$ids[0]}, {$ids[1]})")->fetchColumn(),
        'nothing is left behind'
    );

    // A second run has nothing to do, and says so.
    $again = bulk_delete_taxonomies('tag', $ids);

    assert_eq(0, $again['removed']);
    assert_eq(2, $again['skipped']);
});

t('the content list search treats % and _ literally', function () {
    seed_content(['slug' => 'literal-percent', 'title' => 'Discount 100% Off']);

    assert_true(count(list_content('page', ['q' => '100%'])) >= 1, 'a literal percent is found');
    assert_count(0, list_content('page', ['q' => '%%']), '%% is not a match-everything wildcard');
    assert_count(0, list_content('page', ['q' => '__']), '__ is not a match-everything wildcard');
});

t('content_link_resolves() mirrors the front-end routes', function () {
    test_anonymous_request();

    // Earlier tests in this file draft everything, so give these their own
    // published state rather than depending on the seed.
    $homeId = (int) (load_settings()['homepage_id'] ?? 0);

    if ($homeId > 0) {
        db()->prepare("UPDATE content SET status = 'published', published_at = :now WHERE id = :id")
            ->execute(['now' => time(), 'id' => $homeId]);
    }

    seed_content(['slug' => 'link-target-page', 'title' => 'Link target', 'status' => 'published', 'published_at' => time()]);

    assert_true(content_link_resolves('/'), 'the homepage');
    assert_true(content_link_resolves('/link-target-page/'), 'a page');
    assert_true(content_link_resolves('/category/news/'), 'a category archive');
    assert_true(content_link_resolves('/search'), 'a virtual route');
    assert_true(content_link_resolves('/link-target-page?x=1#frag'), 'query and fragment are ignored');

    assert_false(content_link_resolves('/definitely-missing/'), 'a missing path');
    assert_false(content_link_resolves('https://example.com/'), 'another host');
    assert_false(content_link_resolves('mailto:a@example.com'), 'a mail link');
    assert_false(content_link_resolves('#'), 'a placeholder');
    assert_false(content_link_resolves('about'), 'a bare relative path is not ours');
    assert_false(content_link_resolves('/media/2026/04/nope/x.jpg'), 'a missing media file');
});

t('content_link_resolves() follows a redirect and checks media files', function () {
    test_anonymous_request();

    redirect_save('old-link-target', 'about');
    assert_true(content_link_resolves('/old-link-target'), 'a redirected path still works');

    $base = '2026/04/1ink0001';
    @mkdir(STORAGE_PATH . '/media/' . $base, 0777, true);
    file_put_contents(STORAGE_PATH . '/media/' . $base . '/doc.pdf', 'x');

    assert_true(content_link_resolves('/media/' . $base . '/doc.pdf'), 'a media file resolves');
    assert_false(content_link_resolves('/media/' . $base . '/missing.pdf'), 'a missing file does not');
});

t('content_broken_links() finds dead links in props and rich text', function () {
    test_anonymous_request();

    $id = seed_content([
        'slug'         => 'broken-links-page',
        'title'        => 'Broken links page',
        'status'       => 'published',
        'published_at' => time(),
        'meta'         => ['description' => 'd'],
        'body'         => [
            ['type' => 'cta-section', 'props' => ['title' => 'Go', 'text' => 't', 'url' => '/gone-for-good/', 'linktext' => 'Go'], 'children' => []],
            ['type' => 'quill-editor', 'props' => ['content' => '<p><a href="/also-gone/">x</a> <a href="/link-target-page/">ok</a> <a href="https://example.com/">out</a> <a href="#top">top</a></p>'], 'children' => []],
        ],
    ]);

    $findings = array_values(array_filter(content_broken_links(), fn(array $finding): bool => $finding['id'] === $id));

    assert_count(2, $findings, 'only the two dead internal links are reported');
    assert_eq(['url', 'content'], array_column($findings, 'field'));
    assert_eq(['/gone-for-good/', '/also-gone/'], array_column($findings, 'href'));
});

exit(test_summary());
