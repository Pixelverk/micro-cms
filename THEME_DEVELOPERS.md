# Theme developer guide

How to build a theme for this CMS: the manifest, components, layouts, forms,
taxonomies, menus, images and styling, and how a request becomes HTML. It is
the written counterpart of the in-app **Theme developer** tab (`/admin/docs`),
with the render internals spelled out. For the engine itself, see
`DEVELOPERS.md`; for the CMS from an editor's side, see `USERS.md`.

---

## 1. The rules

A theme is PHP files and assets. Nothing is compiled, fetched from a CDN, or
loaded from a package manager.

* **Procedural PHP only.** No classes, no framework, no composer.
* **No build step.** No bundler, no npm, no CDN. Vendor anything third-party
  locally under `theme/assets/`.
* **Output is HTML rendered from the database on request.**
* **One theme per install**, always at `theme/`. There is no theme switching.
* **Escaping is the theme's job.** Wrap every dynamic value in `e()`.

---

## 2. Folder layout

```text
theme/
  theme.php          the manifest: layouts, content types, taxonomies, assets
  layouts/           page wrappers (default, blog, search, …)
  components/        one file per component
  partials/          shared PHP/CSS includes (form.php, pager.php, …)
  assets/            css, js, images, vendored libraries
    icons/           SVG icons the icon field and theme_icon() use
    previews/        one image per component for the Add-component library
  demo/              content.json + settings.json a fresh install imports
```

Content, users and settings live in SQLite under `storage/`; the engine is
`core/`. A theme rarely needs to touch either. `admin/` is the CMS's own UI and
is not part of the theme.

---

## 3. The manifest (`theme/theme.php`)

`theme/theme.php` returns one array describing everything the theme supports.
Anything in it that names a file, a component or another declared name is
checked by **Admin → Health**, which reports what does not resolve.

```php
return [
    'name'   => 'My Theme',
    'schema' => true,

    'layouts' => ['default' => 'Default', 'blog' => 'Blog Post'],
    'headers' => ['site-header' => 'Default Header'],
    'footers' => ['site-footer' => 'Default Footer'],
    'defaults' => ['layout' => 'default', 'header' => 'site-header', 'footer' => 'site-footer'],
    'menu_locations' => ['main' => 'Main Menu', 'footer' => 'Footer Menu'],

    'content_types' => [ /* see below */ ],
    'taxonomies'    => [ /* see §7 */ ],
    'form_types'    => [ /* see §8 */ ],

    'meta' => [
        'viewport'         => 'width=device-width, initial-scale=1.0',
        'charset'          => 'UTF-8',
        'theme_color'      => '#212529',   // browser chrome; Settings can override
        'background_color' => '#ffffff',   // installed-site splash colour
        'theme_color_dark' => '',          // optional dark-scheme variant
    ],
    'icons' => [
        'favicon' => 'favicon.ico',
        'app'     => ['icon-192.png', 'icon-512.png'], // square PNGs for the manifest
        // 'logo' => 'logo.svg',  // optional fallback for the site logo
    ],

    'styles'  => ['layout.css', 'utilities.css', 'style.css'],
    'scripts' => [['src' => 'main.js', 'defer' => true]],
];
```

| Key | Meaning |
| --- | --- |
| `name` | Shown on the Health page and in the admin. |
| `schema` | `true` emits JSON-LD structured data; the homepage also gets an Organization entry. |
| `layouts` | Page layouts an editor may choose. The name maps to `layouts/<name>.php`. |
| `headers`, `footers` | Header/footer components an editor may choose. |
| `defaults` | The last fallback for a page's layout/header/footer. |
| `menu_locations` | Named menu slots; the Menus admin page lists them and a component references one. |
| `content_types` | The content model: what the editor offers and which URLs it produces. |
| `taxonomies` | The taxonomies the site has. Core ships `category` and `tag`. |
| `form_types` | Public forms and their fields. |
| `meta` | `<head>` charset/viewport and the app/browser colours. |
| `icons` | Fallback favicon, app icons and logo. Settings values win. |
| `styles`, `scripts` | Global assets, loaded in the order given. |
| `search_layout` | Optional; the layout for `/search` (default `search`). |

### Content types

```php
'page' => [
    'label'                => 'Page',
    'default_layout'       => 'default',
    'default_header'       => 'site-header',
    'default_footer'       => 'site-footer',
    'available_components' => ['hero-section', 'cta-section', 'quill-editor'],
    'url_prefix'           => '',                    // pages live at the root
    'taxonomies'           => [],                    // none offered
    'images'               => [ /* §9 */ ],
    'fields'               => [ /* §9 */ ],
],
'blog_post' => [
    'label'                => 'Blog Post',
    'available_components' => ['quill-editor'],
    'editor'               => 'rich-text',           // one rich-text field
    'url_prefix'           => 'blog',
    'taxonomies'           => ['category', 'tag'],
    'images'               => ['thumbnail' => ['label' => 'Featured image']],
    'fields'               => [
        'excerpt' => ['type' => 'textarea', 'label' => 'Excerpt', 'max' => 200],
    ],
],
```

* `available_components` is the type's palette. A component not listed cannot
  be added to that type (existing content keeps rendering it).
* `editor => 'rich-text'` makes the type one rich-text field instead of the
  Add-component editor. The component is the first in `available_components`
  whose schema has a `quill` field; the stored body is still the usual
  one-component list, so nothing downstream changes. Absent means
  `'components'`.
* `taxonomies` lists the taxonomies this type's editor offers. Terms are
  shared, so a type can pick any term of a taxonomy it lists.
* `url_prefix` is the first path segment for the type's content.

---

## 4. How a request becomes HTML

```text
index.php → core/bootstrap/front.php
  route_request()        builds the $page array (or a 404)
  render_page($page)     renders the layout, assembles <head>, wraps the document
```

`$page` is a plain array. A content page carries `id`, `type`, `slug`, `path`,
`title`, `status`, `layout`, `header`, `footer`, `meta`, `components`,
`taxonomies`, `published_at`, `updated_at`. A taxonomy archive carries
`taxonomy` (the term row) and `taxonomy_config` (its declaration) instead of
`components`; search carries `query`, `filters`, `results`.

`render_page()`:

1. Renders the layout into a buffer — this is where layouts and components
   collect their CSS and JS.
2. Assembles `<head>` in this order: charset and viewport; SEO tags
   (`seo_head_tags()`), JSON-LD; `rel=prev/next` for a paged listing; favicon;
   app/manifest tags; the core image-placeholder CSS; `styles`; `scripts`;
   collected component CSS; Settings custom CSS; collected component JS
   (wrapped in a `DOMContentLoaded` handler).
3. Builds the document: skip link, token-preview bar, the layout's output.
4. In `env => production`, minifies the HTML, then injects the Settings
   header/footer snippets **after** minification (they are code).

### The layout contract

A layout is `theme/layouts/<name>.php`. It receives:

* `$page` — the page array.
* `$headerComponent`, `$footerComponent` — resolved from page → settings → theme defaults.
* `&$collectedJs`, `&$collectedCss` — pass them through so anything the layout
  or its components emit ships on the page.

```php
<?php
component($headerComponent, [], $page, $collectedJs, $collectedCss);

echo '<main id="main-content">';
if (($page['title'] ?? '') !== '') {
    echo '<h1 class="visually-hidden">' . e((string) $page['title']) . '</h1>';
}
render_components($page['components'] ?? [], $page, $collectedJs, $collectedCss);
echo '</main>';

component($footerComponent, [], $page, $collectedJs, $collectedCss);

// Layout-specific CSS, shipped only on pages that use this layout:
require theme('partials/taxonomy-archive.css.php');
```

Every layout must mark its `<main>` with `id="main-content"` — the skip link
targets it, and the design test checks every layout for it.

### The component contract

`component($name, $props, $page, &$js, &$css)` resolves `theme/components/<name>.php`
first and falls back to `core/components/<name>.php`. It collects the
component's `css` and `js` (de-duplicated by name), fills an empty image prop
from its schema `default`, then calls the spec's `render` closure. A missing
file or a spec that is not an array is a warning plus a visible placeholder.

---

## 5. Writing a component

A component is one file that returns an array:

```php
<?php
return [
    'label'       => 'Call To Action',
    'description' => 'A closing call to action on a dark band.',

    'schema' => [
        'title' => ['type' => 'text', 'label' => 'Title', 'default' => 'Hello'],
        'body'  => ['type' => 'quill', 'label' => 'Body'],
        'image' => ['type' => 'image', 'label' => 'Image'],
        'link'  => ['type' => 'url', 'label' => 'Link'],
        'span'  => ['type' => 'text', 'label' => 'Side by side', 'span' => 'half'],
    ],

    'children'         => 'none',   // 'none' | 'any' | 'some'
    'allowed_children' => [],       // only when children === 'some'

    'css' => <<<CSS
    .cta { padding: 3rem 2rem; text-align: center; }
    CSS,
    'js' => <<<JS
    // runs after DOMContentLoaded
    JS,

    'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
        ?>
        <section class="cta">
            <h2><?= e($props['title'] ?? '') ?></h2>
            <?= render_image($props['image'] ?? '', ['class' => 'cta-image']) ?>
        </section>
        <?php
    },
];
```

### Schema field types

`text`, `textarea`, `number`, `color`, `checkbox`, `url`, `email`, `select`
(with `options`), `quill` (rich text), `image` (media-library picker), `icon`
(icon browser built from `theme/assets/icons/`).

A field may take `label`, `help`, `default`, `required`, `max`, and:

* `span => 'third' | 'half'` shares the editor row. Omitted (or unrecognised)
  means the whole row, so a field added to an existing schema keeps its width.
* A `select` needs `options` as `value => label`.
* An **image** field's `default` fills a new component and comes back when the
  editor clears the field. `':placeholder'` means the CMS placeholder box;
  `ratio` in the render attrs sets its shape. Render the value with
  `render_image()`, which accepts a media id or an absolute URL.
* A field named `menu` is filled with `menu_locations`, so the editor picks a
  slot (see §10).
* `required => true` is enforced at publish: the publish is refused, the save is
  kept as a draft, and the Pre-publish checklist names the field.

Render with `theme_icon($props['icon'] ?? '')` for an icon field: it inlines the
SVG and returns nothing for a name the theme does not ship.

### Previews

Drop an image at `theme/assets/previews/<component>.<ext>` and the
Add-component library shows it on that component's tile. Without one the tile
is a neutral placeholder. The same folder is read for fallback components too.

### Nesting

`children => 'some'` with `allowed_children` limits what may be nested;
`'any'` allows any, `'none'` none. In the render closure the children arrive as
`$props['children']`; render them with
`render_components($props['children'], $page, $collectedJs, $collectedCss)`.

---

## 6. Images and media in templates

One kind of content image: an editor upload (a media row, addressed by id) or an
absolute URL.

| Call | Use |
| --- | --- |
| `render_image($value, $attrs)` | The one to reach for. A media id becomes a responsive `<picture>` (WebP source, LQIP background, alt from the media row); an absolute URL becomes a plain `<img>`; anything else the CMS placeholder box. Extra `$attrs` (class, alt, ratio, …) are passed through. |
| `resolve_image_value($value, $width)` | The same value as a plain URL — for `og:image`, a CSS background, or JSON-LD. |
| `picture($mediaId, $attrs)` | The `<picture>` element directly, when you know you have a media id. |
| `media_url($id, $width)` | A URL for any uploaded file, images included (PDF, MP4, …). |
| `image_placeholder($ratio, $attrs)` | The placeholder box on its own. |

The theme ships **no** content images: only its icons, component previews and
the app icons the manifest falls back to. There is no `img()` helper and no
theme filename space to collide with a media id.

---

## 7. Taxonomies

Core ships `category` (one term per item) and `tag` (many). Declare the
manifest's `taxonomies` key only for what you add or change:

```php
'taxonomies' => [
    // Override the built-in two.
    'category' => ['label' => 'Section', 'url_prefix' => 'section'],

    // Add one.
    'topic' => [
        'label'        => 'Topic',
        'label_plural' => 'Topics',
        'url_prefix'   => 'topic',   // /topic/design
        'multiple'     => true,      // many terms per item
        'layout'       => 'taxonomy',// archive layout; default 'taxonomy'
    ],

    // Remove one.
    'tag' => false,
],
```

A content type lists the taxonomies it offers under `taxonomies` (§3). Terms
are shared, so every type that lists a taxonomy can pick any of its terms, and
the editor shows a control per declared taxonomy (single or multi per
`multiple`).

Read and link them:

```php
<?php foreach (content_taxonomies($page)['topic'] ?? [] as $term): ?>
    <a href="<?= e(taxonomy_url('topic', $term['slug'])) ?>"><?= e($term['name']) ?></a>
<?php endforeach; ?>
```

| Helper | Returns |
| --- | --- |
| `content_taxonomies($page)` | The item's terms, keyed by declared taxonomy name (empty lists included). Accepts an item array or the page. |
| `taxonomy_url($name, $slug)` | The archive URL from the taxonomy's `url_prefix`. |
| `theme_taxonomies()` | Every declaration, merged with the core defaults. |
| `taxonomy_config($name)` | One declaration, or `null`. |
| `content_type_taxonomies($type)` | The names a content type offers. |
| `taxonomy_label($name, $plural)` | The editor-facing label (the built-in two are translated). |

An archive page's `$page` carries `taxonomy` (the term row) and
`taxonomy_config` (the declaration), so a layout can title and style from the
declaration rather than a literal. One taxonomy may set `primary => true`; its
first term supplies the article `section` in the page's structured data (the
first single-term taxonomy is used when none is marked). `url_prefix` must be
unique and must not collide with a content type prefix or a reserved route;
Health reports it.

---

## 8. Forms

Public forms are declared under `form_types`; the CMS validates, stores and
emails, and the theme renders the fields.

```php
'form_types' => [
    'contact' => [
        'label' => 'Contact',
        'fields' => [
            'name'    => ['type' => 'text', 'label' => 'Your name', 'required' => true],
            'email'   => ['type' => 'email', 'required' => true],
            'subject' => ['type' => 'select', 'required' => false,
                          'options' => ['general' => 'General', 'sales' => 'Sales']],
            'message' => ['type' => 'textarea', 'required' => true],
        ],
        'notification_email_setting' => 'contact_email',
        'store_submission'           => true,
    ],
],
```

* Field types: `text`, `textarea`, `email`, `tel`, `url`, `number`, `select`,
  `radio`, `checkbox`.
* `label` is optional; without it the field name is turned into a label.
* `select` and `radio` require `options` as `value => label`.
* `max` bounds a text or textarea value (500 and 5000 by default).
* `notification_email_setting` names a Settings value, which may hold several
  comma-separated addresses.
* `store_submission => false` validates and emails without keeping the row.

The default theme renders every type through one partial,
`theme/partials/form.php`, included by `contact-section` and
`blog-preview-section` alike. It ships its own CSS and JS with the first form
on the page and comes in two variants — stacked (a card, floating labels) and
inline (a signup row). A new form needs only a `form_types` entry and somewhere
to put it.

---

## 9. Meta: images and fields

A content type can declare editor fields that land in the item's `meta` array.

```php
'images' => [
    'thumbnail' => ['label' => 'Featured image'],
    'gallery'   => ['label' => 'Gallery', 'multiple' => true],
],

'fields' => [
    'project_url' => ['type' => 'url', 'label' => 'Project link',
                      'help' => 'Where "View project" points.'],
    'excerpt'     => ['type' => 'textarea', 'label' => 'Excerpt', 'max' => 200],
],
```

`images` renders in the editor's Images card; `fields` in the Details card.
Both use the component schema vocabulary (`text`, `textarea`, `url`, `email`,
`number`, `checkbox`, `select`, `media`), and both are read back the same way:

```php
<?= render_image($page['meta']['thumbnail'] ?? '', ['class' => 'card-img-top']) ?>
<a href="<?= e($page['meta']['project_url'] ?? '') ?>">View project</a>
```

`url`, `email`, `number` and `select` values are checked on save. A `media`
field is one media-library image; a gallery stays the `images` mechanism.

---

## 10. Menus

`menu_locations` is the single source of truth for where a menu can appear. A
component declares a slot with a field named `menu`; the editor fills its
options from the manifest, so the theme keeps only the default:

```php
// theme/components/site-header.php
'schema' => [
    'menu' => ['type' => 'select', 'label' => 'Menu slot', 'default' => 'main'],
],

// in the render function
$menu = get_menu_for_location((string) ($props['menu'] ?? 'main'));

foreach ($menu['items'] as $item) {
    if ($item['broken'] || $item['url'] === '') {
        echo '<span class="nav-link">' . e($item['label']) . '</span>';
        continue;
    }
    echo '<a class="nav-link' . (!empty($item['active']) ? ' active' : '') . '"'
       . ' href="' . e($item['url']) . '"'
       . (!empty($item['current']) ? ' aria-current="page"' : '') . '>'
       . e($item['label']) . '</a>';
}
```

Each item is `type`, `label`, `slug`, `target`, `hidden`, `children`, plus
`url` (empty when it no longer resolves) and `broken` added by the resolver.

* A link to content is stored as a reference (`content_id`), so renaming a page
  moves its menu link with it. A taxonomy link names its taxonomy.
* `hidden` prunes the whole branch before the component sees it.
* `active` marks the current page and its ancestors; `current` marks the exact
  page (put `aria-current` on that one).
* `content_url($row)` builds a content URL from its type prefix and parents —
  use it rather than assembling one by hand.
* A slot nobody assigned renders nothing. Assign menus under **Menus** in the
  admin.

---

## 11. Assets and styling

* `utilities.css` is the shared class layer (grid, spacing, cards, buttons);
  its class names are a public API.
* `style.css` holds design tokens and theme-wide rules.
* Anything specific to one component belongs in that component's `css` block.
* Load order: theme `styles`, then collected component CSS, then Settings
  custom CSS. A component can therefore override the shared layer, and an
  operator can override everything without editing the theme.
* `asset('style.css')` returns a URL stamped with the file's modification time,
  so editing a file needs no version bump. External URLs pass through.
* `theme_icon($name)` inlines an SVG from `theme/assets/icons/`;
  `theme_icons()` lists every icon the theme ships.

---

## 12. Caching

Rendered pages are cached as HTML under `storage/cache/`, keyed by path.

* Only anonymous, non-preview `GET` requests for a published `200` page are
  cached. Cached HTML is never served to a signed-in user.
* Search results and paged listings are never cached or served from the cache.
* Editing a template does not invalidate the cache — it holds rendered HTML
  keyed by path. After a template, component CSS/JS or layout change, run
  Utilities → Clear Cache (or save any content item) before the change appears.
  A production site minifies the HTML.

---

## 13. Demo content

`theme/demo/content.json` and `theme/demo/settings.json` are imported on a
fresh install, and **Utilities → Import** puts them back after experimenting.
Both files are written by the package exporter, so a demo can never contain
something an import could not reproduce. A theme with no `demo/` folder
installs empty.

---

## 14. What the theme does not render

A few responses bypass the theme on purpose, so a theme cannot style them:

* Maintenance mode answers with a standalone 503 page while the public site is
  closed.
* A database the CMS cannot upgrade shows its own explanatory page.
* When no page has the slug `404`, `core/components/404.php` renders the not
  found page. Override it with your own `theme/components/404.php`, or give a
  page the slug `404`.

---

## 15. Health checks for a theme

Admin → Health reports the manifest as a set of rows; a theme is clean when
they are all `ok`:

* **Layouts** — every declared layout resolves, and the selected default does.
* **Components** — every header, footer, component and child name resolves.
* **Meta fields** — every content-type field is a type the editor can render.
* **Assets** — every stylesheet, script and icon exists.
* **Partials** — every partial a layout or component includes exists.
* **Form fields** — every declared form field is well formed.
* **Taxonomies** — prefixes are valid and unique, layouts resolve, and each
  content type names taxonomies that exist.

Run it after any manifest change; a name that does not resolve is otherwise a
blank 500 or a "component not found" placeholder only a visitor would see.
