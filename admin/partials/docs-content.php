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
                    ['p' => 'The sidebar is the map of the admin: Welcome, Content, Collections (categories and tags), Forms, Site (media, menus, redirects), Reports (analytics, activity, health), System (settings, users, utilities) and Help (documentation). Anything your role cannot use is hidden, and a group disappears when it has nothing left to show.'],
                    ['p' => 'The top bar names the page you are on. On the right are a link to the live site, the help button, the light/dark switch, and your account. Each page carries its own working buttons at the top right of the content area.'],
                    ['p' => 'On a narrow screen the sidebar becomes a drawer and the top bar keeps only the menu and account controls.'],
                ],
                'Creating and editing content' => [
                    ['p' => 'Open Content, choose the type (Page, Blog Post, Portfolio Item), then Add. The editor has three parts: components in the middle, details on the right, and the component palette below.'],
                    ['ul' => [
                        'Title and Slug: the slug is the URL. It is generated from the title but you can adjust it.',
                        'Components: add from the palette, drag to reorder, duplicate or remove with the toolbar on each component.',
                        'Nested components: some components accept children. Drop them into the inner area of the parent.',
                        'Categories and Tags: attach as many tags as you like; one category per item.',
                        'Parent: nest a page under another to build a URL like /services/consulting/.',
                        'Images: a Blog Post has a Featured image, and a Portfolio Item also has a Gallery. Pick from the media library, or type a theme file name or a full URL.',
                    ]],
                    ['p' => "Clear removes the selected image. A section image falls back to the theme's placeholder; a featured image or gallery row simply becomes empty. A gallery keeps the order you add images in, and removing a row takes that image out."],
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
                    ['p' => 'While you edit, the CMS autosaves a draft about once a minute. If the tab closes, the next visit offers the draft back into the editor; saving is still what updates the page.'],
                    ['p' => 'Publishing is blocked while a required component field is empty: the save is kept as a draft and the Pre-publish checklist shows what is missing. Missing image descriptions, dead links and a missing meta description only warn.'],
                    ['p' => 'If your role is Author you can write and preview drafts, but an editor or administrator has to publish them.'],
                ],
                'Versions and undo' => [
                    ['p' => 'Every meaningful save stores the previous version. Open History from the editor to see what changed, view any earlier version, and restore it.'],
                    ['p' => 'Restoring is safe: the state you replace is itself saved to history first, so a restore can be undone.'],
                ],
                'Deleting and restoring' => [
                    ['p' => 'Deleting moves an item to the trash instead of removing it. It leaves the site and the Content list, keeps its version history, and can be brought back from the Trash tab.'],
                    ['p' => 'Trashed items are purged automatically after a while — 30 days by default, see trash.retention_days in config.php. "Delete permanently" in the Trash tab removes one item immediately, and Utilities → Clear Trash empties the whole trash at once. Trashing a page also trashes the pages nested under it.'],
                    ['p' => 'Utilities → Clear Trash cannot be undone. Everything else on the Utilities page either can be repeated safely or makes a file you download.'],
                ],
                'Media' => [
                    ['p' => 'Upload images, PDFs or video in Media. Give each image alt text so it is accessible and searchable.'],
                    ['p' => 'Uploads are automatically resized into several widths and converted to WebP where possible, with a JPEG or PNG fallback. The original is kept as well.'],
                    ['p' => 'Selecting a file opens an inspector on the right: the preview, its name, size and upload date, the alt text and description, and an optional replacement file.'],
                    ['p' => 'Choose size/format lists every stored variant with its pixel dimensions, so you can pick the right one before pressing Copy URL. The URL goes on the clipboard ready to paste into content or a template.'],
                    ['p' => 'Deleting a file that content still uses would break that content, so the confirmation names what uses it — content, site settings and menus — before anything is removed. Media has a search box for finding a file by name, alt text or description.'],
                    ['p' => 'Authors may pick from the media library but cannot upload or delete.'],
                ],
                'Bulk actions' => [
                    ['p' => 'Tick the boxes on the left of the content list to reveal a toolbar. You can publish, draft, archive, delete, clear cache, or add and remove a tag across the whole selection at once.'],
                    ['p' => 'The media library works the same way: tick files and delete them together. Deleting media is permanent, so it asks once before removing anything.'],
                    ['p' => 'Categories and tags are the same, with delete as the only bulk action: tick the terms on their list and remove them together. The content that used them is kept and simply loses the term.'],
                    ['p' => 'Authors can only bulk-edit their own items; anything else is skipped and reported.'],
                ],
                'Finding things' => [
                    ['p' => 'The site search covers titles and body text. In the admin, the content list has its own search box, and the status tabs above it filter by draft, scheduled, published, archived or trashed.'],
                    ['p' => 'Media has a search box, and the activity log can be filtered by action, object, author, period and free text.'],
                    ['p' => 'Documentation has a filter of its own; typing in it hides every section that does not match.'],
                ],
                'Activity log' => [
                    ['p' => 'More → Activity log shows who changed what, when. Entries are written automatically and cannot be edited.'],
                ],
                'Menus' => [
                    ['p' => 'A menu is a list of links — your content, an archive, or a custom URL — that the theme can print in a location such as the main navigation or the footer.'],
                    ['p' => 'Open Menus and pick the menu you want from the dropdown at the top of the sidebar. Choosing "New menu" clears the selection so the next save creates one; name it in the Menu Label field.'],
                    ['p' => 'Tick the locations the menu should fill. A location holds one menu at a time, so ticking one that already has a menu moves it here, and unticking releases it.'],
                    ['p' => 'Add items from the sidebar: choose a type (pages, blog posts, portfolio items, categories or tags), then the item itself, and the row shows the URL it will use. Custom URLs are typed by hand instead. Every item has a label, a target (same tab or new tab) and an eye button that hides it — the eye is struck through while it is hidden. Drag a row anywhere except its fields and buttons to reorder, and use the child action to nest an item under the one above it — one level deep.'],
                    ['p' => 'A link to content is stored as a reference, so renaming a page moves its menu link with it. Hiding an item takes its children off the site too, while both stay in this editor. An item whose page was deleted, trashed or unpublished is marked as broken, and renders as plain text on the site rather than a dead link.'],
                    ['p' => 'Deleting a menu leaves its locations empty until another menu is assigned to them.'],
                ],
                'Settings' => [
                    ['p' => 'Settings is grouped by what you are changing: Site, Editor account, Layout, SEO and social, Media uploads, Custom code, Maintenance, and the URL prefix for each content type. Related fields sit side by side, and a Save button for the whole page is at the top right.'],
                    ['p' => 'Site URL matters most: set it to the site\'s real address so canonical URLs, the sitemap and social sharing are correct. Leave it blank and the CMS works it out from the request.'],
                    ['p' => 'Change a URL prefix only on a site that is not yet public, or with redirects ready: existing links to the old paths will break.'],
                    ['p' => 'Custom code and custom CSS are written into every public page exactly as typed, so treat them as trusted-admin-only input. Saving settings clears the page cache.'],
                ],
                'SEO and sharing' => [
                    ['p' => 'The SEO & Social panel on each item controls the browser title, the description search engines show, the canonical URL, and the image and text used when the page is shared. Leave a field blank to inherit a sensible default.'],
                    ['p' => 'The panel previews itself: a search result and a social card, showing the title, description and image a crawler would actually get, fallbacks included. It follows the fields as you type.'],
                    ['p' => "Author is the writer shown on a post and in the article metadata search engines read. Twitter/X creator is the writer's own handle, for when that is not the site account in Settings."],
                    ['p' => 'Site-wide defaults for the title suffix, the social image and the Twitter/X handle live under Settings → SEO and social. Anything set on an item overrides them.'],
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
                    ['code' => "return [
    'name' => 'My Theme',
    'schema' => true,
    'layouts' => ['default' => 'Default', 'blog' => 'Blog Post'],\n    'headers' => ['site-header' => 'Default Header'],\n    'footers' => ['site-footer' => 'Default Footer'],\n    'defaults' => ['layout' => 'default', 'header' => 'site-header', 'footer' => 'site-footer'],\n    'menu_locations' => ['main' => 'Main Menu', 'footer' => 'Footer Menu'],\n    'content_types' => [\n        'page' => [\n            'label' => 'Page',\n            'default_layout' => 'default',\n            'available_components' => ['hero-section', 'cta-section'],\n            'url_prefix' => '',\n        ],\n    ],\n    'form_types' => [ /* contact, newsletter … */ ],\n    'styles' => ['utilities.css', 'style.css'],\n    'scripts' => [['src' => 'main.js', 'defer' => true]],\n    'icons' => ['favicon' => 'favicon.ico', 'app' => ['img/icon-192.png', 'img/icon-512.png']],\n];"],
                    ['p' => 'Content types drive the admin: the sidebar, the component palette and URL prefixes all come from here.'],
                    ['p' => "schema => true emits JSON-LD structured data in the head; the homepage also gets an Organization entry. icons.favicon and icons.logo are the fallbacks for the matching Settings fields."],
                    ['p' => "meta.theme_color is the colour a phone browser tints its chrome with, and meta.background_color the splash colour an installed site starts from; Settings can override the first. meta.theme_color_dark adds a dark-scheme variant when set."],
                    ['p' => "icons.app lists the square PNGs an installed site uses: the manifest offers them and the largest is the apple touch icon. An uploaded logo or favicon takes their place when Settings has one, so a theme only needs these as its fallback."],
                    ['p' => "/site.webmanifest is served from the site title, description, colours and icons, so there is no manifest file to keep in step with Settings. A static export writes it and robots.txt out as files."],
                    ['p' => "A content type can also declare the images an editor may set. Each key becomes a field in the editor's Images panel and is stored in the item's meta array, so a layout reads it back as \$page['meta'][key]:"],
                    ['code' => "'images' => [\n    'thumbnail' => ['label' => 'Featured image'],\n    'gallery'   => ['label' => 'Gallery', 'multiple' => true],\n],"],
                    ['p' => "A value may be a media id, a theme filename or an absolute URL. Render it with render_image(), which turns a media id into a responsive picture() and anything else into a plain img."],
                    ['p' => "defaults is the last fallback for a page's layout, header and footer: the page's own value wins, then the content type, then the settings, then this. Everything the manifest names — layouts, headers, footers, components, child names, styles, scripts, icons and form fields — is checked on the Health page, which reports anything that does not resolve."],
                ],
                'Writing a component' => [
                    ['p' => 'A component is a single file in theme/components/ that returns an array. The render function receives props, the page, and the collected CSS/JS arrays.'],
                    ['code' => "<?php\nreturn [\n    'label' => 'Call To Action',\n    'schema' => [\n        'title' => ['type' => 'text', 'label' => 'Title', 'default' => 'Hello'],\n        'body'  => ['type' => 'quill', 'label' => 'Body'],\n    ],\n    'children' => 'none',\n    'allowed_children' => [],\n    'css' => <<<CSS\n.cta { padding: 3rem 2rem; text-align: center; }\nCSS,\n    'js' => <<<JS\n// runs after DOMContentLoaded\nJS,\n    'render' => function (array \$props, array \$page, array &\$collectedJs = [], array &\$collectedCss = []) {\n        \$title = \$props['title'] ?? '';\n        ?>\\n        <section class=\"cta\"><h2><?= e(\$title) ?></h2></section>\n        <?php\n    },\n];"],
                    ['ul' => [
                        "schema field types: text, textarea, number, color, checkbox, url, email, select, quill, image",
                        "an image field gets the media-library picker; render its value with render_image(\$props['image'] ?? '', ['class' => 'img-fluid']) so a media id, a theme filename and a URL all work",
                        "an image field's default is its placeholder: it fills a new component, and it comes back when the editor clears the field",
                        "a field named 'menu' is filled with the theme's menu_locations, so the editor picks a menu slot (see Menus below)",
                        "children: 'none', 'any', or 'some' with allowed_children listing permitted types",
                        "css and js ship only on pages that use the component",
                        'always escape output with e()',
                        'read optional props defensively: $props["x"] ?? ""',
                        'required => true is enforced when publishing: the missing value blocks the publish and the save is kept as a draft',
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
                        'render_image($value, $attrs)' => 'Theme image: a media id, theme filename or URL',
                        'resolve_image_value($value, $width)' => 'The same value as a URL, for og:image or a CSS background',
                        'media_url($id, $width)' => 'URL for any uploaded file',
                        'settings' => 'load_settings() / get_setting(key)',
                        'format_date($ts, $format = null)' => 'Date using the site date format and timezone settings',
                        'site_timezone()' => 'The timezone chosen in Settings',
                        'site_logo_url() / site_favicon_url()' => 'Logo and favicon: Settings value, then the theme manifest',
                        'component($name, $props, $page)' => 'Render one component',
                    ]],
                ],
                'Forms' => [
                    ['p' => 'Public forms are declared under form_types in the manifest. The CMS validates the declared field types server-side, stores the submission and emails the notification address; the theme renders the fields. In the default theme one partial does that for every type: theme/partials/form.php, included by contact-section and blog-preview-section alike, so a new form needs only a form_types entry and somewhere to put it. It ships its own CSS and JS with the first form on the page and comes in two variants — stacked (a card of fields, with floating labels) and inline (one row for a signup).'],
                    ['code' => "// theme/theme.php
'form_types' => [
    'contact' => [
        'label' => 'Contact',
        'fields' => [
            'name'    => ['type' => 'text', 'label' => 'Your name', 'required' => true],
            'email'   => ['type' => 'email', 'required' => true],
            'subject' => ['type' => 'select', 'required' => false, 'options' => ['general' => 'General', 'sales' => 'Sales']],
            'reply_by'=> ['type' => 'radio', 'required' => false, 'options' => ['email' => 'Email', 'phone' => 'Phone']],
            'message' => ['type' => 'textarea', 'required' => true],
        ],
        'notification_email_setting' => 'contact_email',
        'store_submission' => true,
    ],
];"],
                    ['ul' => [
                        'field types: text, textarea, email, tel, url, number, select, radio, checkbox',
                        'label is optional; without it the field name is turned into a label',
                        'select and radio require options as value => label',
                        'max bounds a text or textarea value (500 and 5000 by default)',
                        'notification_email_setting names a Settings value, which may hold several comma-separated addresses',
                        'store_submission => false validates and emails without keeping the submission',
                    ]],
                ],
                'Layouts' => [
                    ['p' => 'A layout receives $page, $headerComponent, $footerComponent and the CSS/JS arrays by reference. It renders the header, the main content, then the footer:'],
                    ['code' => "<?php\ncomponent(\$headerComponent, [], \$page, \$collectedJs, \$collectedCss);\n\necho '<main>';\nrender_components(\$page['components'], \$page, \$collectedJs, \$collectedCss);\necho '</main>';\n\ncomponent(\$footerComponent, [], \$page, \$collectedJs, \$collectedCss);\n\n// Add layout-specific CSS (shipped only on pages using this layout):\nrequire theme('partials/taxonomy-archive.css.php');"],
                ],
                'The default theme as an example' => [
                    ['p' => 'The theme that ships with the CMS is the worked example. It mirrors the Start Bootstrap "Modern Business" reference page for page, and its demo lives in theme/demo/content.json and theme/demo/settings.json rather than in code.'],
                    ['table' => [
                        'index.html' => '/ — home: hero, features, testimonial, blog preview',
                        'about.html' => '/about/ — hero, feature rows, team',
                        'pricing.html' => '/pricing/ — pricing plans',
                        'faq.html' => '/faq/ — FAQ accordion',
                        'contact.html' => '/contact/ — the contact form',
                        'blog-home.html' => '/blog/ — featured post, news list, stories',
                        'blog-post.html' => '/blog/welcome-to-our-blog/ — a written post',
                        'portfolio-overview.html' => '/portfolio/ — the project grid',
                        'portfolio-item.html' => '/portfolio/project-one/ — one project',
                    ]],
                    ['p' => 'The demo also carries pages the reference has no counterpart for: /services/, /privacy/, the 404 page, and /landing/, which is the only page using the landing layout — a page with no header or footer. Between them and the search route, every layout the manifest declares renders on a fresh install, and the demo categories and tags are what make the blog archive and the generic taxonomy archive reachable: drop them and those two layouts have no page to appear on.'],
                    ['p' => 'A fresh install imports the demo; Utilities → Import with the file field left empty puts it back after experimenting. Both files are written by the package exporter, so a demo can never contain something an import could not reproduce.'],
                    ['p' => 'Blog comments are deliberately out of scope: the reference\'s post comments have no CMS counterpart, so nothing in this theme renders them. Do not go looking for the missing component.'],
                ],
                'Menus' => [
                    ['p' => 'menu_locations in the manifest declares every place a menu can appear. It is the single source of truth: the admin menu page lists these slots, and a component references one of them.'],
                    ['p' => 'A component declares a menu slot with a field named menu. The editor fills its options from menu_locations, so the theme author keeps only the default:'],
                    ['code' => "// theme/components/site-header.php\n'schema' => [\n    'menu' => ['type' => 'select', 'label' => 'Menu slot', 'default' => 'main'],\n],\n\n// in the render function\n\$menu = get_menu_for_location((string) (\$props['menu'] ?? 'main'));\nforeach (\$menu['items'] as \$item) { /* … */ }"],
                    ['p' => 'The editor can then point the component at any declared slot, and a slot nobody assigned renders nothing. Assign menus to slots under Menus in the admin.'],
                    ['p' => 'get_menu_for_location() hands back items that are ready to render. Each is type, label, slug, target, hidden and children, plus two keys the resolver adds: url (empty when the link no longer resolves) and broken. type is a content type key, url for a hand-written link, or category / tag for an archive.'],
                    ['code' => "foreach (\$menu['items'] as \$item) {\n    echo '<a class=\"nav-link' . (!empty(\$item['active']) ? ' active' : '') . '\"'\n       . ' href=\"' . e(\$item['url']) . '\"'\n       . (!empty(\$item['current']) ? ' aria-current=\"page\"' : '') . '>'\n       . e(\$item['label']) . '</a>';\n}"],
                    ['ul' => [
                        'content_id is how a content link survives a rename: the id resolves to the row\'s current path, and the stored slug is the fallback for items written before ids existed',
                        'hidden prunes the whole branch before the items reach the component',
                        'active marks the page you are on and every item it sits under; current marks the exact page, which is what aria-current belongs on',
                        'an item with no url is broken — render it as text, not as a link',
                        'content_url(\$row) builds any content URL from its type prefix and parents; use it instead of assembling one by hand',
                        'a package never carries content_id: export strips it and import resolves it again from the slug, so a menu from another site points at this one\'s pages',
                    ]],
                ],
                'Styling' => [
                    ['ul' => [
                        'utilities.css is the shared class layer (grid, spacing, cards, buttons). Its class names are a public API.',
                        'style.css holds design tokens and theme-wide rules.',
                        'Everything specific to one component belongs in that component\'s css block.',
                        'Component CSS is injected after the shared layer so it can override it; custom CSS from Settings comes after that, so an operator can override the theme without editing it.',
                    ]],
                ],
                'Caching' => [
                    ['p' => 'Rendered pages are cached as HTML under storage/cache. Saving content clears the cache for that item and regenerates the sitemap.'],
                    ['p' => 'Drafts, previews, search results, taxonomy archives and anything rendered for a signed-in user are never cached.'],
                ],
                'Responses the theme does not render' => [
                    ['p' => 'A few responses bypass the theme on purpose, so a theme cannot style them:'],
                    ['ul' => [
                        'Maintenance mode answers with a standalone 503 page while the public site is closed.',
                        'A database the CMS cannot upgrade shows its own explanatory page.',
                        'When no 404 page exists, core/components/404.php renders. Override it with your own components/404.php, or by giving a page the slug 404.',
                    ]],
                ],
                'Adding an admin language' => [
                    ['p' => 'Admin strings live in admin/lang/. Copy en.php, translate the values, and add the language to admin_languages() in core/helpers/admin.php. Every string is fetched with admin_trans(key).'],
                    ['p' => 'Keys are short and descriptive: area_element, such as nav_dashboard, trash_move or settings_site_title_help. The area names the screen (nav, common, content, editor, versions, settings …) and the element names the string. Both files must carry the same keys; a missing one falls back to the key itself.'],
                ],
                'The admin UI' => [
                    ['p' => 'admin/assets/style.css is the only admin stylesheet, and it is the single source of styling. Two rules the test suite enforces: every class used in admin markup must be defined there, and nothing is styled inline — neither style blocks in a page nor style attributes on an element.'],
                    ['p' => 'Colour, spacing, radii and shadows are CSS custom properties in :root, with html.dark overriding the whole ramp. A component reads a token rather than a literal colour, so a palette change is a change in one block.'],
                    ['code' => ":root {\n    --surface: #ffffff;\n    --surface-muted: #f9fafb;\n    --text: #1f2937;\n    --text-muted: #626d7d;\n    --border: #e5e7eb;\n    --primary: #00796b;\n    --primary-contrast: #ffffff;\n    --radius-md: 8px;\n    --shadow-sm: 0 1px 2px rgba(15, 23, 42, 0.06);\n}\n\nhtml.dark {\n    --surface: #0f172a;\n    --text: #e5e7eb;\n    --primary: #14b8a6;\n    --primary-contrast: #04201d;\n}"],
                    ['p' => 'Buttons say what they do rather than what colour they are: btn-primary for the one main action, btn-secondary for an alternative, btn-muted for a quiet one, btn-info for export and system actions, btn-danger and btn-delete for anything that discards data, and btn-small as a size modifier that can go with any of them.'],
                    ['p' => 'Forms use .field-grid: two equal columns by default, .field-grid-3 for three, and .field-span for a field that needs the whole row. Set the column count per group rather than giving an individual input a width, so fields in a row stay the same size.'],
                    ['p' => 'States that only appear conditionally — the .notice-* callouts, .status-* labels, .field-error, .empty-state and the .off-screen helper — are kept even when a search for them finds nothing. They render in states a static check cannot see.'],
                    ['p' => 'Icons live in admin/assets/icons/ as single SVGs drawn with stroke="currentColor", so they take the colour of the text around them. icon($name, $size) inlines one.'],
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
                    ['p' => 'A fresh install ships one account per role, so the difference between them can be seen side by side; README.md lists the demo logins.'],
                ],
                'Form submissions' => [
                    ['p' => 'Contact and newsletter forms are configured under form_types in theme/theme.php and their submissions appear in the Forms section of the admin. Notifications go to the addresses set in Settings.'],
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
                    ['p' => 'The add form is one row: the old path, the new path, and whether the redirect is permanent (301) or temporary (302). Pressing "Redirect this" in the 404 list fills the form for you.'],
                    ['p' => 'A redirect is refused when it would take a working URL away: a path the CMS serves (the admin area, media, search, the form endpoints, sitemap.xml, robots.txt), a page or archive that is live, a path that already redirects (edit that entry instead), or one that would close a loop.'],
                    ['p' => 'Renaming the slug of a published page creates its 301 automatically. Drafts do not, because their URL was never public. Renaming a page back removes the entry that would now hide it, and publishing a page on a redirected path clears that redirect, so a live path is never shadowed.'],
                    ['p' => 'A target that is itself a redirect still works, but it costs the visitor a second hop; the list marks it, and Check redirects lists it as a chain. That check also finds entries that cannot work at all — loops, self-targets, routes and hidden pages — and can remove them in one go.'],
                    ['p' => 'Search above the list filters on both the old and the new path.'],
                ],
                'robots.txt' => [
                    ['p' => '/robots.txt is generated rather than stored, so it cannot go stale: it allows every crawler and points at the sitemap on the address set in Site URL. Add your own rules under Settings and they are appended exactly as written, which is where Disallow lines belong.'],
                ],
                'Site health' => [
                    ['p' => 'The health page checks PHP and its extensions, that storage is writable, that the schema is current and that production settings are safe. It only reads; fix what it flags before it turns into a blank page or a silently uncached site.'],
                ],
                'Maintenance' => [
                    ['p' => 'Utilities groups its actions by what they touch. Maintenance holds the reversible ones, Content and data the ones that change content state, and Export and system the ones that produce a file or update the schema. Red buttons act on data that cannot be brought back.'],
                    ['ul' => [
                        'Maintenance → Clear Cache: removes all cached pages. They rebuild on the next visit.',
                        'Maintenance → Warm Cache: renders every published page into the cache, so the next visitor does not pay for it.',
                        'Maintenance → Regenerate Sitemap: rebuilds sitemap.xml.',
                        'Content and data → Publish Due Content: publishes anything past its scheduled time. The site also does this automatically from time to time.',
                        'Content and data → Clear Trash: permanently deletes everything in the trash. Not reversible.',
                        'Content and data → Reset Analytics: deletes all recorded page views. Not reversible.',
                        'Export and system → Export Static Site: downloads the cached pages, theme assets and media as a zip. The pages need no PHP, so this is how you publish to static hosting.',
                        'Export and system → Download Backup: downloads the database, media library and sitemap as a zip.',
                        'Export and system → Run Migrations: applies schema updates after upgrading the code.',
                        'Content package → Export a package: opens a dialog with two downloads, content.json for the pages, posts, categories, tags and menus, and settings.json for the settings that describe them.',
                        'Content package → Import a package: opens a dialog to choose the theme\'s demo files or upload your own, preview exactly what would change, then confirm.',
                    ]],
                    ['p' => 'Export and system actions need the PHP zip extension. Without it the CMS falls back to Phar; if neither is available those buttons are disabled and say so.'],
                    ['h' => 'Moving content between sites'],
                    ['p' => 'A content package is JSON: content.json holds the pages, posts, portfolio items, categories, tags and menus, and settings.json holds the settings that describe the content — site title, homepage, layout defaults, URL prefixes and the menu assignments. Nothing carries a database id, so parents, categories and the homepage are matched by slug when the package lands.'],
                    ['p' => 'Import reads either your uploaded files or, when you choose none, the theme\'s own demo files — an upload replaces the demo rather than adding to it. Environment settings — the site URL, timezone, media options, contact address, custom CSS and scripts — are deliberately never part of a package, so importing one cannot break the install it arrives at.'],
                    ['p' => 'Uploaded files are held in storage/imports/ until you confirm or cancel, and are removed as soon as the import runs or after an hour.'],
                    ['p' => 'Loading the theme\'s demo files is the quickest way to get a working demo back after experimenting: preview, then Import now.'],
                    ['h' => 'Restoring a backup'],
                    ['p' => 'Unzip the archive and put data.sqlite, media/ and sitemap.xml back into storage/, replacing what is there. The cache is not in the backup; it rebuilds on the next visit. There is deliberately no upload-and-restore button for the database: a wrong one would brick the site. Moving content is what the content package above is for.'],
                ],
                'Upgrading' => [
                    ['p' => 'Replace the code, then open Utilities and run the migrations. The database is upgraded in place; content, users and settings are preserved. The same happens automatically on the next admin request.'],
                ],
            ],
        ],
    ];
}
