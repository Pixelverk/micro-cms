# Micro CMS — developer guide

Orientation for someone about to change this codebase. It describes how the
pieces fit and where to put a change; `README.md` is the product view,
`AGENTS.md` is the short rulebook, and `plan.md` is the plan of record.

---

## 1. What this is, and the constraints that shape it

A small, procedural PHP CMS on SQLite. One install per site, one theme.

The constraints are deliberate and are not an invitation to modernise:

* **PHP 8.0+**, no classes, no namespaces, no autoloader, no composer.
* **SQLite** through PDO. No ORM.
* **Apache** with `.htaccess`; every request routes through `index.php`.
* **No build step, no bundler, no CDN.** Assets are plain files shipped in the
  repo.
* All functions are **global** and grouped by area. A new helper gets a
  distinct, prefixed name.
* **Imagick** is a real requirement (image variants and LQIP placeholders).
  The `zip` extension is optional; a Phar fallback covers it.

Target runtime is PHP 8.0+ on cheap shared hosting, deployed by `git pull` plus
`php tests/run.php`.

---

## 2. The 60-second mental model

```
request
  └─ .htaccess ──────────────────────────────► index.php
       index.php: installer? cache hit? media? admin? front?
         front: route_request()  builds a $page array
                render_page($page) → render_layout() → component()…
                the HTML is echoed and, if cacheable, written to storage/cache/
```

Two ideas carry most of the design:

1. **A page is a plain array.** `route_request()` returns an array with `title`,
   `layout`, `components`, `meta`, etc. Layouts and components render from it.
2. **A component is a PHP file that returns a spec** (schema, css, js, render
   closure). Assembled pages are a list of those specs in the content `body`
   JSON.

---

## 3. Directory map

```text
index.php                 front controller (installer, cache firebreak, dispatch)
config.php                env, url, session/security, cache lifetime, retention
.htaccess                 routing contract (see §4)

core/
  router.php              route_request(), route_admin_request(), 404 fallback
  bootstrap/              per-entry-point wiring + the installer
    front.php             check_cache(), serve_cached(), serve_fresh()
    admin.php             serve_admin(): session, CSRF, dispatch
    media.php             serve_media(): files under storage/media
    setup.php             first-run schema + demo import (source of truth)
  components/             fallback components (404, quill-editor, sample)
  modules/
    platform/             foundation: bootstrap, http, db, settings, cache, auth,
                          access (capabilities), i18n, preview, migrate,
                          validate, throttle, csrf, activity, analytics,
                          pagination, maintenance, theme, datetime, zip, perf
    media/                media.php (rows/URLs), upload.php (Imagick variants)
    seo/                  seo.php (head), manifest.php (icons/manifest),
                          sitemap.php, robots.php
    content/              content.php, taxonomy.php, trash.php, components.php
                          (schemas/rich-text), meta.php, checklist.php,
                          versions.php, publishing.php, search.php,
                          redirects.php, menus.php
    render/               render.php, images.php, theme.php (logo/favicon), icons.php
    forms/                submissions.php, submit.php, token.php (endpoints)
    admin/                admin.php (asset stamping, 403), nav.php, media-usage.php
    operations/           export.php, backup.php, content-package.php, health.php

admin/                    server-rendered admin area (see §8)
  partials/               layout.php, header.php, sidebar.php, pickers, templates
  lang/{en,sv}.php        every admin string
  assets/                 style.css, main.js, content-editor.js, menu-editor.js,
                          icons/*.svg, vendor/{quill,sortable}
  auth/, content/, category/, tag/, media/, menu/, user/   one dir per area

theme/                    the active theme (see §7)
  theme.php               the manifest
  layouts/, components/, partials/, assets/, demo/
theme/demo/               content.json + settings.json imported on install

tests/                    dependency-free harness (see §10)
storage/                  SQLite DB, cache, logs, sessions, media, sitemap (gitignored)
```

### Modules only depend downward

```
platform → media → seo → content → render / forms / admin → operations
```

A module may call its own functions or a lower module's, never a higher one.
Entry points (`core/router.php`, `core/bootstrap/`, `admin/` pages, `theme/`)
are top layer and may call anything. `tests/design.test.php` enforces this with
a source-level test, so a reach-back fails the suite. There is one allowlisted
edge: `platform/access.php` renders the admin 403 page.

**Where does a new function go?** In the module that owns the concept. Config,
HTTP, auth, caching and validation are `platform`; content, taxonomy, menus,
search and redirects are `content`; anything that turns a page into HTML is
`render`; a feature an operator runs (export, backup, health) is `operations`.
The loader is one explicit list in `core/modules/platform/bootstrap.php` —
there are no manifests, discovery or autoloading, so **every new file needs a
`require_once` line there**.

---

## 4. Request lifecycle

### Routing contract (`.htaccess`)

* `theme/assets/` and `admin/assets/` are served directly.
* `core/` and `storage/` are blocked outright.
* A real file under `theme/` or `admin/` is blocked (only assets are public).
* Everything else goes to `index.php`.

`php -S` ignores `.htaccess`, so local development uses `tests/router.php`,
which mirrors the same rules.

### `index.php`

1. Defines `CMS_PATH`, `CORE_PATH`; loads `config.php` (override with the
   `CMS_CONFIG_FILE` env var); defines `STORAGE_PATH`; loads the platform
   bootstrap and sends security headers.
2. **Installer.** `database_is_ready()` checks that the DB file exists and has
   the core tables/columns. If `setup_completed` is false or the DB is
   unusable, `core/bootstrap/setup.php` runs and creates the schema + demo
   content. A zero-byte/partial DB file is deleted first (the installer refuses
   to overwrite an existing file).
3. **Cache firebreak.** An anonymous `GET` with no session cookie, no
   `?preview`, not `/admin` or `/media`, not search, page ≤ 1, and no publish
   check due is served straight from `storage/cache/<path>.html` if it is
   fresh. This branch intentionally loads only `cache.php` + `analytics.php` so
   a hit is cheap.
4. **`/media`** → `core/bootstrap/media.php`. Serves a file under
   `storage/media/`, rejecting `..`.
5. **`/admin`** → `core/bootstrap/admin.php`: `session_boot()`,
   `session_timeout_check()`, `migrate_run()`, `session_validate_identity()`,
   CSRF assertion on every POST except login, then `route_admin_request()`.
6. **Front end** → `core/bootstrap/front.php`: `bootstrap_core()`, start the
   session only if the request carries a session cookie, `migrate_before_read()`,
   ingest buffered analytics, maintenance-mode check, `check_cache()`, else
   `serve_fresh()`.
   `serve_fresh()` resolves a redirect first, then routes, renders, writes the
   cache when allowed, and records the view.

### `bootstrap_core()`

Loaded once per request (guarded by `$GLOBALS['cms_boot_loaded']`). It requires
every module file in dependency order. Tests call it too, so the two never
drift. `perf.php` is the one file loaded on demand (only when
`perf_logging` is on).

---

## 5. Data model

Schema source of truth for **fresh installs** is `core/bootstrap/setup.php`;
**upgrades** run keyed, idempotent closures from `migrate_registry()` in
`core/modules/platform/migrate.php`. When you add a schema change, change
**both**. A marker file (`storage/.migrations`) skips the registry when it is
current, so a normal request does no migration work.

| Table | Holds |
| --- | --- |
| `content` | Every page/post/portfolio item. `type`, `slug`, `parent_id`, `title`, `status`, `layout`/`header`/`footer`, `meta` JSON, `body` JSON, `published_at`, `scheduled_at`, `created_by`/`updated_by`, `search_text`, timestamps, `deleted_at`. Unique on `(type, parent_id, slug)`. |
| `content_versions` | Snapshots for history/restore: title/status/layout/body/meta, a `reason`, and a content hash. |
| `activity_log` | Audit trail (who did what), with retention. |
| `page_views` | Built-in analytics. No IPs; a per-day `visitor_hash`, plus `cache_hit`, `status`, referrer host. |
| `redirects` | `from_path` → `to_path`, status, hit count. |
| `users` | `username`, name, `email`, `password_hash`, `role`, `ui_language`. |
| `settings` | Key/value; arrays are JSON-encoded. |
| `menus` | `slug`, `label`, `items` JSON. |
| `form_submissions` | Public form entries plus workflow `status`. |
| `media` | One row per upload: `base_path` (`YYYY/MM/random`), mime, dimensions, `formats_json` (variant paths), `sizes_json`, `lqip_base64`, alt text. |
| `taxonomy`, `taxonomy_term_relationships` | Categories and tags and their links to content. |
| `login_attempts`, `form_rate_limits` | Throttling. |
| `password_resets` | Hashed, expiring, single-use reset tokens. |

### The content `body`

A list of component nodes, recursively:

```php
[
    ['type' => 'hero-section', 'props' => [...], 'children' => []],
    ['type' => 'quill-editor', 'props' => ['content' => '<p>…</p>'], 'children' => []],
]
```

`meta` is a flat map. Which keys exist is decided by the theme manifest: the
`images` declaration (featured image, gallery) and the `fields` declaration
(per-type meta fields such as `excerpt`, `author_role`, `project_url`).

### Statuses

`draft`, `scheduled`, `published`, `archived` (plus `deleted_at` for the trash).
`resolve_content_status()` is the rule: publishing with a future
`scheduled_at` becomes `scheduled`; only `published` carries a `published_at`.
Front-end reads always AND `content_visibility_sql()`.

---

## 6. Rendering

### The page array

Built by `route_request()`, `load_taxonomy_archive()` or
`route_search_request()`. Common keys:

```php
[
  'id' => int|null, 'type' => 'page', 'slug' => 'about', 'path' => 'about',
  'title' => 'About', 'status' => 'published',
  'layout' => 'default', 'header' => 'site-header', 'footer' => 'site-footer',
  'meta' => [...], 'components' => [ ... body nodes ... ],
  'updated_at' => int,
]
```

Archives add `taxonomy`, `items`, `pagination`; search adds `query`, `filters`,
`results`, `no_cache`.

### `render_page()`

1. `render_layout()` in an output buffer (this is where component CSS/JS is
   collected).
2. Assembles `<head>`: charset/viewport, `seo_head_tags()`, `seo_json_ld()`,
   pagination `rel=prev/next`, favicon, app/manifest tags, the core image
   placeholder CSS, theme stylesheets, theme scripts, collected component CSS,
   Settings custom CSS, collected component JS (wrapped in a
   `DOMContentLoaded` handler).
3. Builds the document: skip link, the token-preview bar, the layout body.
4. In `production`, `minify_html()`; then header/footer snippets from Settings
   are injected **after** minification (they are code, not markup).

### `render_layout()` and `component()`

A layout is `theme/layouts/<name>.php` and receives `$page`,
`$headerComponent`, `$footerComponent`, and `&$collectedJs` / `&$collectedCss`.
It renders the header/footer with `component()` and the body with
`render_components()`.

`component($name, $props, $page, ...)` resolves `theme/components/<name>.php`
first, then `core/components/<name>.php`. It collects the component's `css`/`js`
(de-duplicated by name), fills empty image props from the schema's `default`,
and calls the spec's `render` closure. Every layout marks its `<main>` with
`id="main-content"` for the skip link.

### Asset versioning

`asset('style.css')` returns `…/theme/assets/style.css?v=<filemtime>`, so
editing a stylesheet or script needs no version bump. Admin assets use
`admin_asset()` the same way.

---

## 7. Theme development

`theme/theme.php` is the manifest. It declares:

| Key | Meaning |
| --- | --- |
| `layouts`, `headers`, `footers` | Names offered to the editor |
| `defaults` | Last-resort layout/header/footer (page → type → settings → here) |
| `menu_locations` | Named menu positions |
| `content_types` | The content model (below) |
| `form_types` | Public forms (below) |
| `meta` | viewport, charset, `theme_color`/`background_color` (+ dark) |
| `icons` | `favicon`, and `app` square PNGs for the manifest |
| `styles`, `scripts` | Global assets, in order |
| `schema` | Emit JSON-LD |

A content type declares `label`, `default_layout`/`default_header`/
`default_footer`, `available_components`, `url_prefix`, `taxonomy_layout`,
`images` (editor image slots), `fields` (editor meta fields), and `editor`
(`'components'` — the default — or `'rich-text'`).

### A component

```php
<?php
return [
    'label'       => 'Hero',
    'description' => 'One line the Add-component library shows.',
    'schema'      => [
        'title' => ['type' => 'text', 'label' => 'Title', 'default' => '', 'span' => 'full'],
        'image' => ['type' => 'image', 'label' => 'Image'],
    ],
    'children'         => 'any' | 'none' | 'some',
    'allowed_children' => [...],   // only when children === 'some'
    'css' => '…',
    'js'  => '…',
    'render' => function (array $props, array $page, array &$collectedJs = [], array &$collectedCss = []) {
        extract($props, EXTR_SKIP);
        // echo markup with e() around output
    },
];
```

Schema field types (templates in
`admin/partials/content-editor-templates.php`): `text`, `textarea`, `number`,
`color`, `checkbox`, `url`, `email`, `select` (with `options`), `quill`, `icon`,
`image`. A field may declare `span` (`full` | `half` | `third`); anything
unknown falls back to full width.

`core/components/sample-component.php` is the commented contract. Add a
component to a type's `available_components` and, optionally, a preview image
at `theme/assets/previews/<name>.<ext>`.

### Form types

```php
'contact' => [
    'label' => 'Contact',
    'fields' => ['name' => ['type' => 'text', 'label' => 'Name', 'required' => true], …],
    'notification_email_setting' => 'contact_email',
    'store_submission' => true,
],
```

`theme/partials/form.php` renders it; `core/modules/forms/submit.php` validates,
stores and notifies. Field validation types live in
`form_submission_field_types()`.

---

## 8. The admin area

### Routing and layout

`route_admin_request()` maps `/admin/<page>` to `admin/<page>.php` or
`admin/<page>/index.php`. `login`, `forgot-password` and `reset-password` are
public; everything else runs `require_login()` and then `admin_guard($page)`,
which checks the page capability from `admin_page_capabilities()`.

A page sets `$pageTitle`, builds `$content` in an output buffer, sets
`$pageHelp`, then includes `admin/partials/layout.php`. The layout adds the
sidebar, header, toasts and confirm dialog. Optional `$pageStyles` /
`$pageScripts` arrays load page-specific assets.

### Capabilities

Three flat roles (`admin`, `editor`, `author`) and string capabilities
(`content.publish`, `media.manage`, `settings.manage`, …) in
`core/modules/platform/access.php`.

* `admin_can('x')` — does the current user hold it?
* `require_capability('x')` — stop with a 403 if not.
* `admin_can_open('page')` — may the sidebar link be shown?
* `admin_guard($page)` — the per-page check.

Content-level rules add on top: an author edits only their own content and
cannot publish or delete (`can_edit_content()`), and the last administrator
cannot be removed.

### Editing content

`admin/content/edit.php` is the big one. A type with `'editor' => 'rich-text'`
shows a single rich-text field; every other type shows the component editor
(drag to reorder/nest, duplicate, remove, plus the Add-component dialog). The
body is saved in the same shape either way, so the front end, search, versions,
media usage and export do not care which editor was used.

Also in the editor: per-type Details fields, the Images card, the SEO cards,
the publish checklist, autosave, and token-based preview.

`admin/content/save.php` rebuilds the body from the posted components, applies
`resolve_content_status()`, records a version, reindexes search text and
invalidates the cache.

### Admin conventions the suite enforces

* Every `admin_trans('key')` literal exists in **both** `admin/lang/en.php` and
  `admin/lang/sv.php`; keys are `area_element`, never a sentence.
* No inline `<style>` in admin pages; classes used in markup exist in
  `admin/assets/style.css`.
* Every page that includes the layout sets `$pageHelp`.
* Every admin POST is CSRF-checked centrally; the login form is the exception.

---

## 9. Cross-cutting systems

* **Settings** (`platform/settings.php`) — `get_setting()`/`load_settings()`
  memoise for the request; `set_setting()`/`save_settings()` clear that cache
  and call `invalidate_cache()`. Arrays are stored as JSON. Some keys control
  the site (homepage, prefixes, timezone, date format, logo, favicon, custom
  CSS, header/footer scripts, maintenance, media quality/sizes, admin language).
* **Cache** (`platform/cache.php`) — one file per request path under
  `storage/cache/`. `invalidate_cache()` clears one page (or all). Only
  anonymous, non-preview GETs of published 200 pages are cached; search and
  paged listings never are. HTML is minified only in `production`. Any write
  path that changes public output must call `invalidate_cache()`.
* **Preview** (`platform/preview.php`) — signing in changes nothing on its own.
  Only `?preview=<token>` with the matching `cms_preview` cookie relaxes
  visibility and disables caching. The token is issued on login.
* **Redirects** (`content/redirects.php`) — 301/302 lookups run before routing;
  slug changes can be recorded; there is an audit/repair tool.
* **Versions** (`content/versions.php`) — a snapshot per save, keep-N retention,
  restore and a textual diff.
* **Search** (`content/search.php`) — indexes a plain-text copy of each body in
  `content.search_text`; LIKE by default, with optional FTS5 when SQLite
  provides it. Always AND-ed with the visibility rule.
* **Media** (`media/`) — uploads are stored under `storage/media/YYYY/MM/<id>/`
  with resized variants and an LQIP; `render_image()` turns a media id or an
  absolute URL into a responsive `<picture>`, and anything else into the core
  placeholder box. Serving is `core/bootstrap/media.php`.
* **Forms** (`forms/`) — `submit.php` validates, stores and emails (in dev it
  logs instead); `token.php` refreshes the signed token that cached pages
  embed; there is a honeypot and a per-IP rate limit. The inbox is `admin/messages`.
* **Analytics** (`platform/analytics.php`) — views are buffered to a file and
  batched; a per-day visitor hash stands in for an IP.
* **Activity log** (`platform/activity.php`) — audit entries with retention.
* **Maintenance mode** (`platform/maintenance.php`) — a 503 for visitors while
  the admin and previews keep working.
* **Sitemap / robots / manifest** (`seo/`) — `sitemap.xml` is generated into
  storage; `robots.txt` and `site.webmanifest` are virtual routes.
* **Migrations** (`platform/migrate.php`) — see §5.

### Security model

* Escape all output with `e()`. Raw HTML is only ever deliberate: component and
  layout render bodies, and admin-authored custom CSS / header / footer scripts.
* CSRF: a session token for admin POSTs (`csrf_assert()`), a signed time-boxed
  token for public forms rendered into cached pages.
* Authorization: capabilities plus per-content ownership rules.
* Login throttling and a per-address password-reset limit; reset tokens are
  hashed, expiring and single-use.
* Security headers (nosniff, frame policy, referrer policy, HSTS over TLS) on
  every response; no CSP yet (see the backlog in `plan.md`).
* Uploads are checked against an extension/mime allowlist and processed with
  Imagick; media paths are resolved and traversal-checked before any delete.

---

## 10. Testing

```bash
php tests/run.php              # every suite
php tests/run.php content      # suites whose filename matches
```

* Every `tests/*.test.php` runs in its own PHP process, so per-request statics
  cannot leak between suites.
* `tests/bootstrap.php` boots against a throwaway storage under `tests/.tmp/`
  and refuses to run if that resolves inside the real `storage/`.
* `test_fresh_database()` restores a seeded template DB; suites that mutate
  shared tables call it first.
* `tests/helpers.php` has the assertions (`assert_eq`, `assert_contains`,
  `assert_count`, `t()`, `test_summary()`).
* `tests/http.test.php` drives `index.php` end to end on a real PHP server.
* `tests/design.test.php` is source-level: it enforces the admin conventions in
  §8, the module direction in §3, and a few structural invariants.

Add a suite for new behaviour. Anything the suite cannot see — theme CSS/JS,
admin layout, rendered markup — check through the local server:

```bash
CMS_CONFIG_FILE="$PWD/tests/config.server.php" \
  php -S 127.0.0.1:8080 tests/router.php
```

First run: set `setup_completed => false` in `config.php`. Demo logins are
`admin`/`admin`, `editor`/`editor`, `author`/`author`.

---

## 11. Common changes (short version)

Full steps are in `AGENTS.md`; the essentials:

* **Add a helper** — put it in the owning module and add a `require_once` to
  `bootstrap_core()`. Keep the module direction.
* **Add a theme component** — a file in `theme/components/`, listed in the
  type's `available_components`, optional preview image.
* **Add an admin page** — `admin/<page>.php` (or `admin/<page>/index.php`), add
  a capability in `admin_page_capabilities()` if it needs more than "signed in",
  a sidebar link, both language keys, and a `$pageHelp` block.
* **Add a schema change** — `migrate_registry()` **and** `setup.php`.
* **Add a language** — copy `admin/lang/en.php`, translate the values, add it to
  `admin_languages()`.

---

## 12. Gotchas

* **A new file under the web root must be world-readable (`644`).** Apache runs
  as another user; a file created `600` produces a blank 500 with no message.
  Check with `find admin core theme *.php -type f ! -perm -o=r`.
* **`storage/` must be writable by the web server user**, or SQLite fails every
  write ("readonly database"). Admin → Health reports it.
* **`url()` reads the global `$config`**, not `config()`. `index.php` sets it;
  prefer `config()` for new config reads.
* **Cache decisions live in more than one place.** A new query-driven view must
  be excluded from both the `index.php` firebreak and `check_cache()`.
* **A signed-in user must not see different public output without a preview
  token**, or editor traffic leaks into the shared cache.
* **Removing a component from `available_components` strands existing content**
  (an unknown type becomes an HTML comment and is dropped on the next save).
* **Do not prune conditional CSS** just because a static grep finds no users;
  `.status-*`, `.notice*`, `.field-error` and friends only render in their state.
* **Keep the module direction** — the design test fails on an upward call.

---

## 13. Where to read more

| File | What |
| --- | --- |
| `README.md` | Product description, requirements, shipped features |
| `AGENTS.md` | The rules and step-by-step for common changes |
| `plan.md` | The single plan of record (what is next / what shipped) |
| `old-plan.md` | The completed build programme; history, do not extend |
| `tests/README.md` | Test isolation and the local-server recipe |
| `/admin/docs` | In-app guide, with separate editor and theme-developer tabs |
