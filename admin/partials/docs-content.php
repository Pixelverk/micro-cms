<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Documentation content
|--------------------------------------------------------------------------
| Plain PHP arrays: no build step, no markdown parser, no extra files to keep
| in sync. Each section is a title plus a list of blocks.
|
| Block shapes:
|   ['p'  => 'paragraph']
|   ['h'  => 'sub-heading']
|   ['ul' => ['item', 'item']]
|   ['code' => 'snippet']
|   ['table' => ['Heading' => 'cell', ...]]
|
| Rendered by admin/docs.php. Keep every entry short and task-shaped.
*/

function docs_content(): array
{
    return [
        'editor' => [
            'label' => 'Editor guide',
            'intro' => 'Everything you need to publish content, without touching code.',
            'sections' => [
                'Getting around' => [
                    ['p' => 'The sidebar groups the admin area: Content, Collections (categories and tags), Forms, then Menus, Media and Reports. Anything your role cannot use is hidden.'],
                    ['p' => 'The top bar has a link to the live site, a search field, dark-mode and full-screen toggles, and your profile. The buttons at the top left collapse the sidebar or open it on a phone.'],
                ],
                'Creating and editing content' => [
                    ['p' => 'Open Content, choose the type (Page, Blog Post, Portfolio Item), then Add. The editor has three parts: components in the middle, details on the right, and the component palette below.'],
                    ['ul' => [
                        'Title and Slug: the slug is the URL. It is generated from the title but you can adjust it.',
                        'Components: add from the palette, drag to reorder, duplicate or remove with the toolbar on each component.',
                        'Nested components: some components accept children. Drop them into the inner area of the parent.',
                        'Categories and Tags: attach as many tags as you like; one category per item.',
                        'Parent: nest a page under another to build a URL like /services/consulting/.',
                    ]],
                    ['p' => 'Save at any time. Nothing is live until the status is Published.'],
                ],
                'Drafts, scheduling and preview' => [
                    ['p' => 'The Status menu decides who can see the content:'],
                    ['table' => [
                        'Draft' => 'Only you and other signed-in editors.',
                        'Scheduled' => 'Hidden until the date and time you choose, then published automatically.',
                        'Published' => 'Live on the site.',
                        'Archived' => 'Kept in the admin but hidden from visitors.',
                    ]],
                    ['p' => 'Use Preview to see the real page as it will look, including unpublished changes. Preview pages are never cached and never indexed, so you can share the link with a colleague.'],
                    ['p' => 'If your role is Author you can write and preview drafts, but an editor or administrator has to publish them.'],
                ],
                'Versions and undo' => [
                    ['p' => 'Every meaningful save stores the previous version. Open History from the editor to see what changed, view any earlier version, and restore it.'],
                    ['p' => 'Restoring is safe: the state you replace is itself saved to history first, so a restore can be undone.'],
                ],
                'Deleting and restoring' => [
                    ['p' => 'Deleting moves an item to the trash instead of removing it. It leaves the site and the Content list, keeps its version history, and can be brought back from the Trash tab.'],
                    ['p' => 'Trashed items are purged automatically after a while — 30 days by default, see trash.retention_days in config.php. "Delete permanently" in the Trash tab removes one item immediately, and Utilities → Clear Trash empties the whole trash at once. Trashing a page also trashes the pages nested under it.'],
                ],
                'Media' => [
                    ['p' => 'Upload images, PDFs or video in Media. Give each image alt text so it is accessible and searchable.'],
                    ['p' => 'Uploads are automatically resized into several widths and converted to WebP where possible, with a JPEG or PNG fallback. Never delete a file another page still uses.'],
                ],
                'Search and listings' => [
                    ['p' => 'The site search covers titles and body text. Use the admin search field on the content list to find an item by title or text.'],
                ],
                'Bulk actions' => [
                    ['p' => 'Tick the boxes on the left of the content list to reveal a toolbar. You can publish, draft, archive, delete, clear cache, or add and remove a tag across the whole selection at once.'],
                    ['p' => 'Authors can only bulk-edit their own items; anything else is skipped and reported.'],
                ],
                'Activity log' => [
                    ['p' => 'Reports shows who changed what, when. Filter by action, object, person or date. Entries are written automatically and cannot be edited.'],
                ],
                'SEO and sharing' => [
                    ['p' => 'The SEO & Social panel on each item controls the browser title, the description search engines show, the canonical URL, and the image and text used when the page is shared. Leave a field blank to inherit a sensible default.'],
                ],
            ],
        ],

        'developer' => [
            'label' => 'Theme developer guide',
            'intro' => 'How to build a theme and its components for this CMS.',
            'sections' => [
                'The rules' => [
                    ['ul' => [
                        'Procedural PHP only. No classes, no framework, no composer.',
                        'No build step. No bundler, no npm, no CDN. Vendor anything third-party locally.',
                        'All output is HTML rendered from the database on request.',
                    ]],
                ],
                'Folder layout' => [
                    ['code' => "theme/\n  theme.php          manifest: layouts, content types, assets\n  layouts/           page wrappers (default, blog, search, …)\n  components/        one file per component\n  partials/          shared PHP/CSS includes\n  assets/            css, js, images, vendored libraries"],
                    ['p' => 'Content, users and settings live in SQLite under storage/. Core code is in core/ — you should rarely need to change it.'],
                ],
                'The theme manifest' => [
                    ['p' => 'theme/theme.php returns an array declaring what the theme supports:'],
                    ['code' => "return [\n    'name' => 'My Theme',\n    'layouts' => ['default' => 'Default', 'blog' => 'Blog Post'],\n    'headers' => ['site-header' => 'Default Header'],\n    'footers' => ['site-footer' => 'Default Footer'],\n    'content_types' => [\n        'page' => [\n            'label' => 'Page',\n            'default_layout' => 'default',\n            'available_components' => ['hero-section', 'cta-section'],\n            'url_prefix' => '',\n        ],\n    ],\n    'form_types' => [ /* contact, newsletter … */ ],\n    'styles' => ['utilities.css', 'style.css'],\n    'scripts' => [['src' => 'main.js', 'defer' => true]],\n    'icons' => ['favicon' => 'favicon.ico'],\n];"],
                    ['p' => 'Content types drive the admin: the sidebar, the component palette and URL prefixes all come from here.'],
                ],
                'Writing a component' => [
                    ['p' => 'A component is a single file in theme/components/ that returns an array. The render function receives props, the page, and the collected CSS/JS arrays.'],
                    ['code' => "<?php\nreturn [\n    'label' => 'Call To Action',\n    'schema' => [\n        'title' => ['type' => 'text', 'label' => 'Title', 'default' => 'Hello'],\n        'body'  => ['type' => 'quill', 'label' => 'Body'],\n    ],\n    'children' => 'none',\n    'allowed_children' => [],\n    'css' => <<<CSS\n.cta { padding: 3rem 2rem; text-align: center; }\nCSS,\n    'js' => <<<JS\n// runs after DOMContentLoaded\nJS,\n    'render' => function (array \$props, array \$page, array &\$collectedJs = [], array &\$collectedCss = []) {\n        \$title = \$props['title'] ?? '';\n        ?>\\n        <section class=\"cta\"><h2><?= e(\$title) ?></h2></section>\n        <?php\n    },\n];"],
                    ['ul' => [
                        "schema field types: text, textarea, number, color, checkbox, url, email, select, quill, image",
                        "children: 'none', 'any', or 'some' with allowed_children listing permitted types",
                        "css and js ship only on pages that use the component",
                        'always escape output with e()',
                        'read optional props defensively: $props["x"] ?? ""',
                    ]],
                ],
                'Helpers available to themes' => [
                    ['table' => [
                        'e($text)' => 'Escape HTML output',
                        'url($path)' => 'Site URL honouring subfolder installs',
                        'asset($path) / img($path)' => 'Theme asset and image URLs',
                        'load_content_by_slug($slug)' => 'Load a page by its URL path',
                        'load_content_by_id($id)' => 'Load a page by id',
                        'list_content($type, $filters)' => 'List items of a type',
                        'list_recent_content($type, $n)' => 'Recent published items for loops',
                        'picture($mediaId, $attrs)' => 'Responsive <picture> element',
                        'media_url($id, $width)' => 'URL for any uploaded file',
                        'settings' => 'load_settings() / get_setting(key)',
                        'component($name, $props, $page)' => 'Render one component',
                    ]],
                ],
                'Layouts' => [
                    ['p' => 'A layout receives $page, $headerComponent, $footerComponent and the CSS/JS arrays by reference. It renders the header, the main content, then the footer:'],
                    ['code' => "<?php\ncomponent(\$headerComponent, [], \$page, \$collectedJs, \$collectedCss);\n\necho '<main>';\nrender_components(\$page['components'], \$page, \$collectedJs, \$collectedCss);\necho '</main>';\n\ncomponent(\$footerComponent, [], \$page, \$collectedJs, \$collectedCss);\n\n// Add layout-specific CSS (shipped only on pages using this layout):\nrequire theme('partials/taxonomy-archive.css.php');"],
                ],
                'Styling' => [
                    ['ul' => [
                        'utilities.css is the shared class layer (grid, spacing, cards, buttons). Its class names are a public API.',
                        'style.css holds design tokens and theme-wide rules.',
                        'Everything specific to one component belongs in that component\'s css block.',
                        'Component CSS is injected last, so it can always override the shared layer.',
                    ]],
                ],
                'Caching' => [
                    ['p' => 'Rendered pages are cached as HTML under storage/cache. Saving content clears the cache for that item and regenerates the sitemap.'],
                    ['p' => 'Drafts, previews, search results, taxonomy archives and anything rendered for a signed-in user are never cached.'],
                ],
                'Adding an admin language' => [
                    ['p' => 'Admin strings live in admin/lang/. Copy en.php, translate the values, and add the language to admin_languages() in core/helpers/admin.php. Every string is fetched with admin_trans(key).'],
                    ['p' => 'Keys are short and descriptive: area_element, such as nav_dashboard, trash_move or settings_site_title_help. The area names the screen (nav, common, content, editor, versions, settings …) and the element names the string. Both files must carry the same keys; a missing one falls back to the key itself.'],
                ],
            ],
        ],

        'reference' => [
            'label' => 'Reference',
            'intro' => 'Statuses, roles and where things live.',
            'sections' => [
                'Content statuses' => [
                    ['table' => [
                        'draft' => 'Hidden from visitors. Visible in the admin, or through a preview link. Never indexed.',
                        'scheduled' => 'Hidden until published_at passes; then published automatically.',
                        'published' => 'Public, indexed, listed in the sitemap.',
                        'archived' => 'Hidden from visitors, kept in the admin.',
                    ]],
                ],
                'Roles' => [
                    ['table' => [
                        'Administrator' => 'Everything: content, media, users, settings, activity.',
                        'Editor' => 'All content including publishing and deletion, media, categories, tags, menus, forms.',
                        'Author' => 'Their own content only. Can draft and preview, cannot publish or delete.',
                    ]],
                    ['p' => 'The last remaining administrator cannot be demoted or deleted.'],
                ],
                'Form submissions' => [
                    ['p' => 'Contact and newsletter forms are configured under form_types in theme/theme.php and their submissions appear in the Forms section of the admin. Notifications go to the address set in Settings.'],
                ],
                'Configuration' => [
                    ['table' => [
                        'env' => 'local or production; production minifies HTML and suppresses verbose errors.',
                        'url' => 'Base path when installed in a subfolder.',
                        'cache_lifetime' => 'Seconds a rendered page stays cached.',
                        'security.form_secret' => 'Signs the tokens used by public forms.',
                        'security.login_max_attempts' => 'Failed logins before a lockout.',
                        'activity.retention_days' => 'How long audit entries are kept.',
                        'versions.keep' => 'How many versions of each item to keep.',
                        'trash.retention_days' => 'How long a trashed item is kept before it is purged.',
                        'robots_extra' => 'Extra lines appended to the generated robots.txt.',
                    ]],
                ],
                'Analytics' => [
                    ['p' => 'Reports → Analytics shows page views from the public site: views, unique visitors and the share served from the HTML cache, each compared with the period before it.'],
                    ['p' => 'Switch the range between 30 days, 6 months and 1 year. The chart, top pages and top referrers all follow it.'],
                    ['p' => 'Bot traffic is not counted. No IP addresses are stored; visitors are only counted through a hash that changes every day.'],
                ],
                'Redirects' => [
                    ['p' => 'Redirects keep old URLs working. Add one when a page moves, or use the recent-404s list to catch links that are already broken. Saving a redirect clears its cached page, so it applies immediately.'],
                ],
                'robots.txt' => [
                    ['p' => '/robots.txt is generated rather than stored, so it cannot go stale: it allows every crawler and points at the sitemap on the address set in Site URL. Add your own rules under Settings and they are appended exactly as written, which is where Disallow lines belong.'],
                ],
                'Site health' => [
                    ['p' => 'The health page checks PHP and its extensions, that storage is writable, that the schema is current and that production settings are safe. It only reads; fix what it flags before it turns into a blank page or a silently uncached site.'],
                ],
                'Maintenance' => [
                    ['ul' => [
                        'Utilities → Clear Cache: removes all cached pages.',
                        'Utilities → Reset Analytics: deletes all recorded page views.',
                        'Utilities → Clear Trash: permanently deletes everything in the trash at once.',
                        'Utilities → Warm Cache: renders every published page into the cache.',
                        'Utilities → Export Static Site: downloads the cached pages, theme assets and media as a zip.',
                        'Utilities → Download Backup: downloads the database, media and sitemap as a zip.',
                        'Utilities → Regenerate Sitemap: rebuilds sitemap.xml.',
                        'Utilities → Publish Due Content: publishes anything past its scheduled time.',
                        'Utilities → Run Migrations: applies schema updates after upgrading the code.',
                    ]],
                    ['h' => 'Restoring a backup'],
                    ['p' => 'Unzip the archive and put data.sqlite, media/ and sitemap.xml back into storage/, replacing what is there. The cache is not in the backup; it rebuilds on the next visit. There is deliberately no upload-and-restore button: a wrong database would brick the site.'],
                ],
                'Upgrading' => [
                    ['p' => 'Replace the code, then open Utilities and run the migrations. The database is upgraded in place; content, users and settings are preserved. The same happens automatically on the next admin request.'],
                ],
            ],
        ],
    ];
}
