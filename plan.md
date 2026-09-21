# Micro CMS — plan

The next round of work for the same CMS: procedural PHP over SQLite, no build
step, no composer, no framework and no plugins. This file is the **single plan of
record** for what is left to do. The programme that built the CMS — phases 1–24,
its dropped items and the deferred multi-language design — is kept as
[`old-plan.md`](old-plan.md). The shipped features are listed in `README.md`.

## Document map

| File | Role |
| --- | --- |
| `plan.md` | This file. The single plan of record for what is next. |
| `old-plan.md` | The completed programme (phases 1–24), the dropped items and the deferred multi-language design. History; do not extend it. |
| `README.md` | Product description, requirements and the shipped-feature list. |
| `AGENTS.md` | The constraints and conventions every phase must respect. |

## Fixed constraints

These are not up for renegotiation inside a phase:

* Procedural PHP 8.0+, SQLite, Apache, one theme at `theme/`, Imagick; `zip`
  optional with a Phar fallback.
* No composer, framework, ORM, build step, bundler, CDN dependency or test
  framework.
* One install per client site. No multisite, no theme switching.
* Developers own structure and design in `theme/`; editors own content.
* Install is hosted on cheap shared hosting and delivered by `git pull` plus
  `php tests/run.php`.

## Tracks

* **A — the CMS core** (data model, admin, helpers).
* **B — building themes** (the developer-facing side).
* **C — running a site** (the editor/operator side).
* **D — multi-language front end** (deferred; design kept in `old-plan.md`).
* **E — structure refactor** (organisational only: `core/` grouped into modules
  plus the boundary fixes that make the modules point one way; no behaviour
  change). Phases M1–M8, then optional M9, in the track at the end of this file.

## Rules every phase follows

1. **One phase at a time.** Implement, verify, report, stop. Do not start the
   next phase speculatively. A phase ships alone; a phase with ordered steps
   ships each step alone.
2. **Verification bar at the end of every phase:**
   * `php tests/run.php` passes. Extend the existing suite nearest the change;
     add no new harness. Suites must call `test_fresh_database()` because
     `tests/admin.test.php` mutates the shared user table.
   * Anything the suite cannot see (theme CSS/JS, admin layout, rendered markup)
     is checked through the local server:
     `CMS_CONFIG_FILE="$PWD/tests/config.server.php" php -S 127.0.0.1:8080 tests/router.php`.
   * Any new admin page gets a capability in `admin_page_capabilities()`,
     sidebar entry, both language keys and a `$pageHelp` block.
   * Any write path that changes public output calls `invalidate_cache()`.
   * **New file under the web root must be world-readable:** `find admin core
     theme *.php -type f ! -perm -o=r` must print nothing (`chmod 644`). A file
     created `600` returns a blank 500.
   * `storage/` must be writable by the web server user.
   * Schema changes go in **both** `core/bootstrap/setup.php` (fresh installs) and
     `migrate_registry()` (upgrades), and migrations are idempotent.
   * Removing a component from `available_components` is a content-affecting
     change: check existing content first (an unknown type becomes an HTML
     comment and is dropped on the next save).
3. **Admin UI and public theme may assume JavaScript.** Public navigation, menus and forms can use theme scripts to work.
4. No phase may introduce a new concept unless its Why says why.

## Phase table

Track E (the structure refactor) has shipped, phase by phase; the track at the
end of this file is now a record of it. Everything the last tables held has
shipped too: small fixes and tidy-ups, media fallbacks without theme
placeholder files, component previews with the Add component dialog, and the
whole-site backup download.

**Shipped:** rich-text-only content types — a content type can declare
`'editor' => 'rich-text'` and be edited as one rich text field; content-type
meta fields — a content type can declare its own meta keys under `'fields'`;
and component field widths — a component schema field can declare
`'span' => 'full' | 'half' | 'third'`.
See both below. What is left is in the Backlog, which is unscheduled: confirm an
item before starting it.

---

## Rich-text-only content types

**Status:** shipped.

### Why

`blog_post` and `portfolio_item` already store exactly one `quill-editor`
component, so the component machinery adds nothing for them: the editor shows a
collapsed Rich Text component wrapped in a details/summary, with move, duplicate
and remove controls, and an "Add component" area whose only tile is the same
Rich Text block. The theme developer should be able to say a content type is
just rich text, and the editor then shows one rich text field with none of that.

### How it stays simple

**The stored body does not change.** It stays `[{type, props, children}]` like
every other content type, so the front end (`render_components()` in the blog
and portfolio layouts), `search_text`, media usage, the publish checklist,
versions, autosave and the content export all carry on untouched. Only the
editor presents that one component differently.

**The manifest says it once.** A content type adds:

```php
'editor' => 'rich-text',
```

It is an editor mode, not a schema change, and it defaults to `'components'`
when absent — so `page`, every component type and every other theme in the wild
are unaffected. `editor` follows `layout`/`header`/`footer`: a plain string the
theme owns. The editor stays honest if it is misspelled: an unknown value falls
back to the component editor (and Health reports it).

**Which rich text is not a new key.** The type's `available_components` names
it; the editor takes the first listed component whose schema has a `quill`
field. `theme/theme.php` declares `[... 'available_components' => ['quill-editor'], 'editor' => 'rich-text']`,
so no component name is repeated and no second source of truth is introduced.

### Editor (`admin/content/edit.php`)

* `content_rich_text_editor($ctConfig)` (new, in `core/modules/content/components.php`)
  returns `['component' => name, 'field' => key]` when the type declares the
  mode and one of its named components exists with a `quill` field; `null`
  otherwise. `save.php` and `health.php` use the same helper.
* In rich-text mode the main column is one card holding a single field: the
  label from the component's schema and
  `<input type="hidden" name="components[0][props][<field>]">` beside the Quill
  host. The component is built from the existing `quill-editor-template` by the
  editor script, which also names the posted `components[0][type]`, so the form
  posts the same shape a component editor posts.
* The card's legend is the component's own label ("Rich Text"), not the generic
  "Components", and no `#components-container`, add zone or details wrapper is
  rendered — there is nothing to add, move or remove.
* Prefill: the value of the body's first component whose type is the declared
  one; otherwise the first `quill` field anywhere in the body. If neither
  exists (a type switched to rich text while its items still hold other
  components) the field starts empty and the first save writes the rich-text
  shape; the component being dropped is the documented consequence of the
  declaration, and the change log keeps the old version.
* Quill assets load whenever `content_rich_text_editor()` returns a component —
  not only when a palette contains a `quill` field.
* The image and icon picker partials stay (the sidebar and SEO panel use them).
  The component picker dialog is not included in this mode.
* The field markup shares the `quill-editor-template` shape that already exists
  (a `.quill-editor` host next to a hidden `.quill-hidden` input), so it looks
  identical to a component rich-text field.

### Editor script (`admin/assets/content-editor.js`)

* Component creation, renumbering, sorting and the add dialog are guarded on the
  container: `renumberComponents()` becomes a no-op without it, as
  `attachImagePicker()` already is.
* One generic Quill bootstrap initialises every `.quill-editor` that is not yet
  initialised, wiring it to its sibling hidden input. Both a component rich-text
  field and the new single field go through it; `createComponent()` keeps no
  Quill branch of its own.
* `window.initialComponents` stays: it is empty or unused in rich-text mode, and
  the other payloads are unchanged.

### Save (`admin/content/save.php`)

The editor posts the component's type and its one field, and a guard replaces the
rebuilt body with exactly the component the manifest names, carrying only its
declared field values. That keeps the editor's contract (one component), stops a
hand-made POST with extra components from stranding content, and is where the
component type is resolved from the manifest. Any other type is otherwise the
present behaviour.

One thing the implementation had to fix on the way: the rebuild dropped every
component that did not post a `type`, which is exactly the shape a rich-text
field has, so a save (and an autosave) stored an empty body. The filter now keeps
an entry that carries props even without a type. `tests/http.test.php` covers
both the normal save and a field-only request.

### Health and docs

* `theme_manifest_problems()` gains a `components`-group check: every type whose
  `editor` is `rich-text` must declare and resolve a component with a `quill`
  field. A typo in `editor` is reported the same way, instead of silently
  falling back.
* `theme/theme.php` documents the key where content types are declared, and the
  theme developer guide's manifest code block (`admin/partials/docs-content.php`)
  shows it. `README.md` needs no change.

### Verification

* `php tests/run.php` passes. Extend `tests/content.test.php`:
  `content_rich_text_editor()` returns `quill-editor`/`content` for a
  `rich-text` type, `null` for `page`, prefill reads the body's rich text and
  ignores other components, and `save_content()` keeps the single-component body
  a rich-text save produces. Extend `tests/theme.test.php` so every rich-text
  declaration resolves to a component with a `quill` field (the theme's own
  declaration included).
* Manual check through the local server with an admin login, which is the part
  the suite cannot see:
  * `admin/content/edit?type=blog_post&id=<id>` shows one rich text toolbar and
    its saved HTML, and no `.component-add-zone`, `.component-title` or
    component picker; saving it changes nothing in the stored body.
  * `admin/content/edit?type=page&id=<id>` still shows the component editor and
    the Add component dialog.
  * A blog post whose body held two components before the switch loads its rich
    text and leaves one component after a save.

### Non-goals

* No second rich-text library, no toolbar change, no media insert into rich text
  (both were dropped deliberately).
* No new editor mode beyond `components` and `rich-text`.
* No change to the component editor, the body shape, or how the front end
  renders a rich-text component.

---

## Content-type meta fields

**Status:** shipped.

### Why

`theme/layouts/portfolio.php` reads `$meta['project_url']` for its "View
project" link, but nothing declared it, so the editor had no field for it. The
same held for `excerpt` (read by the blog archive, taxonomy pages and the blog
sections) and `author_role` (read by the blog layout): meta keys the theme can
read but cannot ask an editor to fill in. Only two things could reach meta
before this — the `images` declaration and the core-owned SEO panel — and
everything else a theme needed was unreachable.

### How it works

**The declaration.** A content type adds `fields`, its own meta keys:

```php
'fields' => [
    'project_url' => ['type' => 'url', 'label' => 'Project link', 'help' => 'Where "View project" points.'],
],
```

It uses the same vocabulary as a component schema, so a theme author learns one
set of names: `text`, `textarea`, `url`, `email`, `number`, `checkbox`, `select`
(with `options`) and `media` (one media-library image). A field takes `label`,
`help`, `max`, `default` and `required` where they mean something. An entry with
no `type` is text, and no `label` falls back to the key.

**`images` stays separate.** The user asked for it: the two are different cards
in the editor (Images, and Details for `fields`), and the existing image
mechanism with its galleries is untouched. `content_meta_fields()` reads only
`fields`.

**Where things happen.**

* `core/modules/content/meta.php` — `content_meta_fields()` normalises the
  declaration, `content_meta_field_value()` picks the value the form shows, and
  `content_collect_meta_fields()` shapes what was posted (trimmed, capped at
  `max`, blank clears the key, a checkbox is true/false, an unposted field is
  left alone so partial saves keep it).
* `content_meta_field_error()` in the same file is the type rule the save handler
  runs: an absolute `http(s)` URL, an email, a number, or one of a select's
  options. Text, textarea, media and checkbox are not judged.
* `admin/partials/content-meta-field.php` renders one field — the same markup
  patterns the SEO panel and the image picker already use, so the media picker
  in `content-editor.js` picks a `media` field up with no new JavaScript.
* `admin/content/edit.php` renders a Details card in the sidebar, after Layout
  and before Images.
* `admin/content/save.php` collects the fields and adds each type failure to the
  error list, so an invalid value is refused before anything is written.
* `core/modules/admin/media-usage.php` counts a `media` field among the keys
  `media_usage_map()` scans, so an image held in one is not "unused".
* Validation messages are `content_error_meta_url`, `..._email`, `..._number`
  and `..._select` in both language files. `content_broken_links()` already
  treats any `*_url` meta key as a link, so a dead project link is reported with
  no further work.

### The shipped theme

* `portfolio_item` declares `project_url` as a `url` field. Because a `url` field
  has to be absolute, the demo's placeholder value `"#"` was changed to
  `https://example.com/project-one` and `.../project-two` in
  `theme/demo/content.json`; a fresh install seeds those.
* `blog_post` declares `excerpt` (textarea, 200) and `author_role` (text, 70),
  closing the same gap for the blog archive, taxonomy pages and blog sections.
* Note for an existing install: content seeded before this change still holds
  `"#"` for a project link, which the field now rejects. Editing the value is a
  one-field fix; nothing migrates it.

### Health and docs

* A new **Theme meta fields** group in the report fails on an unknown field type
  or a `select` with no options — a typo would otherwise silently render a text
  input.
* The developer guide documents `fields` beside `images`.

### Verification

* `php tests/run.php` passes. `tests/content.test.php` covers normalising a
  declaration, the value fallback, collecting (trim, cap, clear, checkbox,
  unposted) and each type rule. `tests/http.test.php` covers the editor card and
  the save path: a `#` value is refused and leaves the stored URL alone, a valid
  one is saved and rendered by the layout. `tests/health.test.php` expects the
  new group.
* Manual check through the local server: the portfolio editor shows the Details
  card with a `type="url"` input holding the saved value, the Images card is
  unchanged, and the published page renders the link.

### Non-goals

* Folding `images` into `fields` (the user chose to keep them separate).
* Repeatable `media` fields: a gallery stays the `images` mechanism, and
  `content_meta_fields()` ignores `multiple`.
* Per-field required-field blocking in the publish checklist, and per-type form
  error rendering beside a field: a failed field reports through the existing
  save-error path.
* Loosening `validate_url()` for relative, `mailto:` or `tel:` links.

---

## Component field widths

**Status:** shipped.

### Why

Component fields used to take a whole row each, which is wasteful on a wide
card. They now share a row, but a flow decides the pairings by width rather than
by what the fields are, and a theme had no way to say "this one wants the row".

### The declaration

A schema field may add one key:

```php
'title' => ['type' => 'text', 'label' => 'Title', 'span' => 'full'],
```

`full`, `half` or `third`. Omitted — or a value the editor does not know — means
the field takes the whole row, exactly as if `full` had been written. That is
deliberate: the author sees the field at full width and opts into `third` or
`half`, rather than a silent middle value deciding for them, and a field added
to an existing schema keeps the width it always had.

### How it works

* The row is six tracks, which is what lets `third` (two tracks) and `half`
  (three) both be exact. A field that declares nothing is the whole row (see
  above); `third` and `half` are the only reasons to declare anything. Each span
  is a custom property, so the narrower layouts change only the track count.
  The grid is switched by the card's own width (`@container`), not the viewport:
  six tracks, then three on a card under 1040px, then one field per row under
  640px.
* `core/modules/content/components.php` owns the allowed values and normalises the schema
  once, so the editor script only applies the class it is handed and there is
  one list to change. The shipped theme declares a span only where it is not the
  default — the hero's and CTA's paired fields.
* Health reports a `span` that is not one of the three, because a typo would
  otherwise be silently ignored — the same reasoning as the meta field types.
* Components only. The meta fields and the SEO fields use their own grids and
  are unchanged; the same hint could be added to them later.

### Verification

`php tests/run.php` passes with a test for the schema normalisation (known
values kept, an unknown one dropped) and for the editor applying the class.
Through the local server: a component whose schema declares spans renders those
classes, and one that declares none keeps the automatic flow.

---

# Track E — structure refactor (module-oriented)

**Status:** shipped. Organisational only: files, folders, and where functions live.
No behaviour change, no new concept beyond the module folders themselves, no
class, namespace, autoloader, build step or dependency. `core/` is grouped into
**modules** — one folder per concern, with the single explicit loader grouped to
match — and the few genuine boundary inversions are fixed so the modules point
one way.

## Why

* **Three helpers mix unrelated concerns and are too big to navigate.**
  `core/helpers/common.php` (1293 lines) holds config, security headers,
  sessions, the loader, theme, escaping, URLs, asset stamping, image rendering,
  media, dates and auth state. `core/helpers/content.php` (2299 lines) holds
  CRUD, preview, trash, taxonomy, component schema, meta fields and the publish
  checklist. `core/helpers/export.php` (1472 lines) holds three features:
  static-site export, full-site backup and the content package.
* **Several functions sit in a file that does not own their concept.**
  `load_taxonomy_archive()`/`taxonomy_per_page()` are in `core/db.php` (a
  connection file); `maintenance_*()` is in `settings.php`; `form_rate_limit_ok()`
  is at the bottom of the `core/form-submit.php` endpoint; `processMenuItems()`,
  `setNestedComponent()`, `reindexRecursive()`, `sanitizeFilename()`,
  `media_usage_map()` and the `is_active()` family live in the page or partial
  that happens to call them, leaking generic globals (`is_active`, `showToast`).
* **The boundaries are undefined, and the code compensates with guards.** With
  comments stripped, the real cross-file graph shows the cost: **30+
  `function_exists()` checks** used as availability workarounds
  (`auth.php` guards `preview_token_*`, `throttle_*`, `log_activity`;
  `content.php` guards half its collaborators; `validate.php`/`csrf.php` guard
  `redirect_with_toast`), and genuine inversions where low-level code reaches
  upward (`settings.php → load_content_by_id()`, `auth.php → seo_absolute_url()`
  and `admin_roles()`, `admin.php → activity/settings`).
* **The test bootstrap duplicates the loader.** `tests/bootstrap.php` hand-lists
  23 `require`s that `bootstrap_core()` already owns, so the two lists drift.
* **A handful of PHP functions are camelCase** (`checkCache`, `serveFresh`,
  `showToast`, `sanitizeFilename`, …) where the convention is snake_case.
* **Comments and docs name paths that will move**, and `tests/README.md`
  documents 4 of the 30 suites.
* Two pieces of dead code ride along: `bootstrap_core(bool $withContent)` is
  never called with `false`, and `load_settings()` sets `homepage_title`, which
  nothing reads.

## What "module" means here — and what it does not

A **module** is a folder under `core/modules/` plus its lines in the one
explicit loader. That is the whole contract.

* **No manifests, no discovery, no registry, no autoloader, no namespaces, no
  classes, no per-module `init.php`.** Functions stay global and keep their
  prefixes; `bootstrap_core()` keeps one explicit, grouped `require` list.
* **Modules are an organising contract, not a plugin system.** Nothing is
  registered, loaded on demand, or swappable. Adding a file to a module still
  means adding one line to the loader — the same discipline as today.
* The theme already works this way (`theme/` is a folder with a manifest and a
  fixed contract); this gives `core/` the same shape without inventing a plugin
  layer the project's fixed constraints rule out.

## Fixed constraints for this track

* **Output and behaviour are identical.** Same URLs, stored data, markup, CSS
  classes, language keys and component contract. A move never renames a function
  unless the phase says so.
* **`admin/`, `theme/`, `tests/` and `storage/` keep their shape**, except the
  in-code comment paths a phase updates. No admin page changes URL.
* **The architectural contract is preserved.** No classes, namespaces,
  autoloader, composer, build step or dependency. `index.php` still requires the
  one early file before `send_security_headers()`; `bootstrap_core()` still loads
  the rest. `.htaccess` blocks `core/` wholesale, so module depth is invisible to
  it.
* New or moved files under the web root are world-readable (`644`).
* One phase ships alone; `php tests/run.php` passes before the next starts.

## Target layout for `core/`

```text
core/
├── router.php            dispatch: route_request(), route_admin_request(),
│                         route_search_request(), load_fallback_404()
├── bootstrap/            front.php, admin.php, media.php  (entry wiring)
│                         setup.php — the installer, moved here from helpers/
├── components/           404.php, quill-editor.php, sample-component.php
└── modules/
    ├── platform/         foundation; depends on nothing above it
    │   ├── bootstrap.php   config(), security headers, session_boot(), bootstrap_core()
    │   ├── http.php        e(), url(), sanitize_slug(), like_escape(),
    │   │                   request_wants_json(), debug_log(), redirect(),
    │   │                   redirect_with_toast(), site_origin(), absolute_url()
    │   ├── theme.php       theme(), theme_config(), asset(), version_asset_url()
    │   ├── datetime.php    site_timezone(), format_date(), format_local_datetime()
    │   ├── db.php          db()
    │   ├── settings.php    load/get/set/save settings, settings_cache_clear()
    │   ├── cache.php       cache_file_for(), invalidate_cache(), cache_write(), minify_html()
    │   ├── migrate.php     migrations + database_is_ready() + database_is_writable()
    │   ├── validate.php    validate_*()
    │   ├── throttle.php    throttle_*()
    │   ├── csrf.php        csrf_*(), form_token_*()
    │   ├── access.php      roles, capabilities, admin_guard(), can_edit_content()
    │   ├── i18n.php        admin_languages(), admin_locale(), admin_trans()
    │   ├── auth.php        users, login/logout, session, is_logged_in(), require_login()
    │   ├── preview.php     can_preview_content(), preview_token*, is_preview_request()
    │   ├── activity.php    audit log
    │   ├── analytics.php   page views
    │   ├── pagination.php  pagination_*()
    │   ├── maintenance.php maintenance mode + the 503 response
    │   ├── zip.php
    │   └── perf.php        (required on demand by index.php, as today)
    ├── media/            depends on platform only — a leaf
    │   ├── media.php       media_by_id/formats/url/is_image, resolve_image_value(),
    │   │                   media_delete(), delete_media_directory(), media_directory_size()
    │   └── upload.php      sanitize_filename(), save_resized_image(), generate_lqip()
    ├── seo/              depends on platform, media
    │   ├── seo.php         canonical, metadata, robots, head tags, JSON-LD, editable fields
    │   ├── manifest.php    icons, app icons, manifest, theme colour
    │   ├── sitemap.php
    │   └── robots.php
    ├── content/          depends on platform, media, seo
    │   ├── content.php     visibility, load/list, status, save, URLs, links
    │   ├── taxonomy.php    terms + archive loading (incl. from db.php)
    │   ├── trash.php
    │   ├── versions.php
    │   ├── publishing.php
    │   ├── search.php
    │   ├── redirects.php
    │   ├── menus.php       + process_menu_items()
    │   ├── components.php  component definitions, schema, spans, rich-text mode
    │   ├── meta.php        meta fields + image collection
    │   └── checklist.php   publish checklist
    ├── render/           depends on platform, media, seo, content
    │   ├── render.php      page/layout/component rendering
    │   ├── images.php      render_image(), picture(), image_placeholder*()
    │   ├── theme.php       site_logo_url(), site_favicon_url()
    │   └── icons.php
    ├── forms/            depends on platform
    │   ├── submissions.php form_submission_*(), form_rate_limit_ok()
    │   ├── submit.php      the /form-submit endpoint (from core/form-submit.php)
    │   └── token.php       the /form-token endpoint (from core/form-token.php)
    ├── admin/            depends on platform, media, seo, content
    │   ├── admin.php       admin_asset(), render_admin_forbidden()
    │   ├── nav.php         admin_nav_active() family (from admin/partials/sidebar.php)
    │   └── media-usage.php media_usage_map(), media_referenced_paths(),
    │                       media_orphans(), media_delete_orphans(),
    │                       media_delete_missing_rows()
    └── operations/       operator tools; depends on platform, media, seo, content, render
        ├── export.php        static-site export + warm_cache()
        ├── backup.php
        ├── content-package.php  content_package_*(), utilities_package_*()
        └── health.php
```

## Dependency direction

The target is one-way, low to high:

```text
platform  →  media  →  seo  →  content  →  render
                                       →  forms (platform only)
                                       →  admin
                                       →  operations
```

| Module | Owns | May depend on |
| --- | --- | --- |
| `platform` | app plumbing and cross-cutting primitives | — |
| `media` | media rows, URLs, uploads, value resolution | platform |
| `seo` | head metadata, manifest, sitemap, robots | platform, media |
| `content` | the data model and everything that reads/writes it | platform, media, seo |
| `render` | turning a page array into HTML | platform, media, seo, content |
| `forms` | public submissions and their two endpoints | platform |
| `admin` | the admin shell and operator-facing helpers | platform, media, seo, content |
| `operations` | export, backup, content package, health | platform, media, seo, content, render |

`core/router.php`, `core/bootstrap/*` and the admin pages under `admin/` are the
**top layer**: they may call anything. Within `platform`, `cache ⇄ settings` and,
within `content`, the `content ⇄ redirects/search/versions/menus` pairs stay
mutually dependent; those are internal to one module and deliberately not
untangled (see Non-goals).

## Boundary fixes (the cheap inversions)

These are the fixes that make the direction above true. Each is a move of
existing code plus the removal of guards that only existed because the boundary
was undefined.

| # | Symptom today | Fix | Functions / sites |
| --- | --- | --- | --- |
| B1 | `validate.php`, `csrf.php`, `auth.php`, `content.php` guard calls that are always present on a booted request | move the callee down; delete the guard | `redirect_with_toast` → `platform/http.php` and drop the guard in `validate.php:39`, `csrf.php:82`; `preview_token_*` → `platform/preview.php` and drop the guards in `auth.php:136,165,335` |
| B2 | `router.php` owns `redirect()`/`redirect_with_toast()`, so five files point at the dispatcher | move both to `platform/http.php`; `router.php` keeps only routing | `redirect`, `redirect_with_toast` |
| B3 | `settings.php → load_content_by_id()` for two convenience keys | resolve the homepage where it is shown; drop the dead key | `load_settings()` no longer sets `homepage_slug`/`homepage_title`; add `content_homepage_slug()` in `content/`; update `seo.php:142`, `admin/content/edit.php:410,416`, `admin/content/index.php:21` |
| B4 | `admin.php` is a single file mixing authorization, i18n and the admin shell, so `content → admin` and `auth → admin` | split it by layer | `access.php` + `i18n.php` → `platform/`; `admin_asset()`, `render_admin_forbidden()` → `admin/admin.php`; nav helpers → `admin/nav.php` |
| B5 | `auth.php ⇄ content.php` via preview tokens | move preview to `platform/preview.php` | `can_preview_content`, `preview_cookie_name`, `preview_token*`, `is_preview_request`, `preview_url` |
| B6 | `common.php` scans content and menus to map media usage | move the scanning out; leave media read a leaf | `media_usage_map`, `media_referenced_paths`, `media_orphans`, `media_delete_orphans`, `media_delete_missing_rows` → `admin/media-usage.php` (only admin pages call them) |
| B7 | `common.php` mixes theme, images, media and HTTP | split it into its modules | `http.php`, `theme.php`, `datetime.php` → `platform/`; `media.php` → `media/`; `images.php` → `render/` |
| B8 | `db.php` loads taxonomy archives | move to `content/taxonomy.php` | `taxonomy_per_page`, `load_taxonomy_archive` |
| B9 | `auth.php → seo_absolute_url()` for a password-reset link | move generic URL builders to platform | `seo_site_url` → `site_origin()`, `seo_absolute_url` → `absolute_url()` in `platform/http.php`; update `seo.php`, `auth.php` |
| B10 | `setup.php` is an installer that imports the demo package, so it sits above every module | classify it as entry wiring | move to `core/bootstrap/setup.php`; it still calls `bootstrap_core()` itself |
| B11 | dead parameter and dead key | remove | `bootstrap_core(bool $withContent)`; `homepage_title` (covered by B3) |

B1–B5 are the ones that remove real coupling; B6–B11 are placement and dead-code
cleanup that fall out of the same pass.

## Verification bar for every phase

1. `php tests/run.php` passes.
2. **The function inventory is unchanged** except for names a phase deliberately
   changes. Before the phase, save
   `grep -rhoP '^function \K[a-zA-Z0-9_]+' core admin theme | sort` to
   `tests/.tmp/`; diff it after. A phase that moves code loses no function.
3. **Rendered output is unchanged.** With the local server running, capture the
   body of a fixed URL set before and after and diff it: `/`, one page, one blog
   post, a taxonomy archive, `/search?q=…`, a missing URL (404), and each admin
   page the phase touches. The suite does not see markup; this does.
4. `git status` / `git diff --stat` are reviewed. Pure moves use `git mv`; the
   diff should be a move plus loader lines plus the comments naming the path.
5. `find admin core theme *.php -type f ! -perm -o=r` prints nothing.
6. Anything the suite cannot see is checked in the browser through
   `CMS_CONFIG_FILE="$PWD/tests/config.server.php" php -S 127.0.0.1:8080 tests/router.php`.

From M8 on, once every module is in place, add one more:

7. **Module direction holds.** `tests/design.test.php` gains a source-level
   `t('modules only depend downward', …)` that maps every function to its module
   from the file that defines it, strips comments with
   `php_strip_whitespace()`, and asserts no call goes from a lower module to a
   higher one (layer order plus a short allowlist for the two deliberate
   intra-module cycles). It fails if a future change reaches back up.

## Phase table

| Phase | Change | Risk |
| --- | --- | --- |
| M1 | Create `core/modules/` and move **platform** (split `common.php`; db, settings, cache, migrate, validate, throttle, csrf, activity, analytics, pagination, maintenance, zip, perf, auth) | medium |
| M2 | Boundary fixes B1–B6, B9–B11 (guards, redirects, homepage, admin split, preview, media-usage, seo URLs, setup move, dead code) | medium |
| M3 | Move **content** (and split `content.php`; taxonomy out of `db.php`, B8) | medium |
| M4 | Move **media**, **seo** | low |
| M5 | Move **render** | low |
| M6 | Move **forms** (incl. the two endpoints) | low |
| M7 | Move **admin**, **operations** | low |
| M8 | Docs and stale references; add the direction test | low |
| M9 | Optional, confirmed separately | medium |

---

## M1 — Platform module

**Steps, each shippable alone.**

1. Create `core/modules/platform/` and split `common.php` into `bootstrap.php`
   (config, security headers, `session_boot()`, `bootstrap_core()`), `http.php`,
   `theme.php` and `datetime.php`. Keep `bootstrap.php` the single early file:
   it must still define what `index.php` uses before `bootstrap_core()`
   (`config()`, `send_security_headers()`, `debug_log()`, `theme()`,
   `bootstrap_core()`) — which `core/helpers/setup.php` also relies on.
2. The rest of `common.php` goes straight to its final home in the same step, so
   no function is ever parked: the media read helpers and `resolve_image_value()`
   → `media/media.php`; `render_image()`, `picture()` and the placeholder → 
   `render/images.php`; the usage/orphan scanners → `admin/media-usage.php`;
   `is_logged_in()`/`require_login()` → `platform/auth.php`. (This is B7.)
3. Move `db.php`, `settings.php`, `cache.php`, `migrate.php`, `validate.php`,
   `throttle.php`, `csrf.php`, `activity.php`, `analytics.php`,
   `pagination.php`, `maintenance.php`, `zip.php`, `perf.php` and `auth.php`
   into `platform/`.
4. Update the loader (`bootstrap_core()`), `index.php`, `core/bootstrap/admin.php`,
   `core/bootstrap/front.php`, `core/helpers/setup.php` and
   `tests/bootstrap.php`.
5. Fold in the E1 consolidation: `tests/bootstrap.php` calls `bootstrap_core()`
   instead of hand-listing helpers, then requires `render.php`/`router.php`.

**Verify.** Tests; function inventory; homepage and admin dashboard render;
`git mv` shows the platform files moving with their contents intact.

## M2 — Boundary fixes

Apply B1–B6 and B9–B11 (B7 landed with M1's split; B8 waits for M3, when the
content module exists). Do them as ordered steps, each shipping alone: B2 and B9
first (they are pure moves with call-site updates), then B1 (guard removal), B3,
B5, B4, B6, B10, B11.

**Verify.** Tests after each step; the function inventory changes only by the
three deliberate renames (`seo_site_url` → `site_origin`, `seo_absolute_url` →
`absolute_url`, plus any M8 naming); grep confirms no `function_exists('redirect_with_toast')`,
`function_exists('preview_token_` or `function_exists('throttle_` remains;
password reset still produces a working link (manual, via the log in dev);
Settings → Maintenance still toggles the 503.

## M3 — Content module

**Change.** Move the content helpers into `core/modules/content/`, splitting
`content.php` into `content.php` (load/list/save/status/URLs/links),
`trash.php`, `components.php`, `meta.php`, `checklist.php`, and adding
`taxonomy.php` (which also absorbs `taxonomy_per_page()` and
`load_taxonomy_archive()` from `db.php` — B8). Move `versions.php`, `publishing.php`,
`search.php`, `redirects.php` and `menus.php` in, and move `processMenuItems()`
from `admin/menu/save.php` into `menus.php`.

**Verify.** Tests (content, http, search, redirects, pagination, seo, versions,
trash, menus); golden diff includes a taxonomy archive and a token preview;
menu editor saves.

## M4 — Media and SEO modules

**Change.** Move `media.php` and `upload.php` into `core/modules/media/`
(media read, `resolve_image_value()`, `media_delete()`, `delete_media_directory()`,
`media_directory_size()`, and the upload helpers from `admin/media/save.php`).
Move the SEO helpers into `core/modules/seo/`, splitting `seo.php` into
`seo.php` and `manifest.php` (the `seo_icon_*`, `seo_app_icons*`,
`seo_manifest*`, `seo_theme_color`, `seo_app_head_tags` families), and move
`sitemap.php` and `robots.php` in.

**Verify.** Tests (media, seo, backup, export); a media upload still produces
variants; `robots.txt`, `sitemap.xml` and `site.webmanifest` still serve through
the local server; golden diff on a page with `<head>` metadata.

## M5 — Render module

**Change.** Move `core/render.php` → `core/modules/render/render.php`, the
image helpers (`resolve_image_value` stays in media; `render_image()`, `picture()`,
`image_placeholder()`, `image_placeholder_css()`) into `render/images.php`, the
logo/favicon resolvers (`site_logo_url()`, `site_favicon_url()`) into
`render/theme.php`, and `icons.php` in. Update
`core/bootstrap/front.php`, `core/bootstrap/admin.php` and `tests/bootstrap.php`
to the new `render.php` path.

**Verify.** Tests (theme, design, seo); the homepage and every layout render;
component CSS/JS collection and the image placeholder still appear in `<head>`.

## M6 — Forms module

**Change.** Move `forms.php` → `forms/submissions.php`; move
`core/form-submit.php` → `forms/submit.php` (with `form_rate_limit_ok()` moved
into `submissions.php`) and `core/form-token.php` → `forms/token.php`. Update the
two `require`s in `core/router.php` and the comments in `theme/partials/form.php`
and `platform/auth.php`.

**Verify.** Tests (forms, forms-http, csrf); a contact submission through the
local server stores a row and logs the notification in dev; a stale token is
refreshed by `/form-token`.

## M7 — Admin and operations modules

**Change.** Create `core/modules/admin/` (`admin.php`, `nav.php` — the
`admin_nav_active()` family from `admin/partials/sidebar.php` — and
`media-usage.php` from M2). Create `core/modules/operations/` with `export.php`,
`backup.php`, `content-package.php` (plus `utilities_package_sections()` /
`utilities_import_read()` from `admin/utilities.php`) and `health.php`.

**Verify.** Tests (admin, design, health, backup, export, settings); the sidebar
active state is correct on dashboard/content/category/messages; Utilities
export/backup/import preview all render; admin → Health loads; a fresh install
still seeds.

## M8 — Docs, names, and the direction test

**Change.**
* PHP function names to snake_case: `checkCache` → `check_cache`, `serveCached`
  → `serve_cached`, `serveFresh` → `serve_fresh`, `serveAdmin` → `serve_admin`,
  `serveMedia` → `serve_media`, `setNestedComponent` → `set_nested_component`,
  `reindexRecursive` → `reindex_recursive`, `processMenuItems` →
  `process_menu_items`, `sanitizeFilename` → `sanitize_filename`, `showToast` →
  `admin_toast`. Browser JavaScript keeps camelCase.
* Add the module-direction test to `tests/design.test.php` (verification point 7).
* Update `AGENTS.md` (the "Where things are" table and the `core/helpers/*`
  references), `README.md` (the `core/` line), `tests/README.md` (its suite table
  lists 4 of 30), this file's own `core/helpers/setup.php` reference under "Rules
  every phase follows", and in-code comments naming moved files
  (`theme/theme.php`, `admin/partials/docs-content.php`, `core/router.php`,
  `theme/partials/form.php`).

**Why the direction test belongs in the suite:** `tests/design.test.php` is
already source-level (it greps markup and translation keys), so a structural
assertion is in keeping, and it is what stops the next change from quietly
reaching back up out of `platform`.

**Verify.** `grep -rnP '^function [a-z]+[A-Z]' core admin` returns nothing
(JS excluded); a repo-wide grep for every old path returns nothing outside
`old-plan.md`; tests pass with the new suite.

## M9 — Optional, confirmed separately

* Split `platform/access.php` further if roles and capabilities outgrow one file.
* Deduplicate the category and tag admin pages: `bulk.php` is ~87% identical,
  `edit.php` ~78% and `index.php` ~63%. Extract the shared bulk routine first;
  treat the list and form pages as a larger job. URLs, markup and language keys
  stay identical.
* Factor the two identical `renumberContainer()` copies
  (`content-editor.js`, `menu-editor.js`) into one shared admin script.

## Non-goals

* No change to output, URLs, stored data, markup, CSS classes, schema, language
  keys or the component/theme contract.
* **No plugin system.** No manifests, discovery, registry, autoloader,
  namespaces or classes. The loader stays one explicit list.
* No reorganisation of `admin/`, `theme/` or `admin/assets/` (pages, partials,
  vendor, icons and previews are already grouped; moving admin pages would change
  URLs).
* Not untangling the two deliberate intra-module cycles (`cache ⇄ settings`,
  `content ⇄ redirects/search/versions/menus`), and not forcing `render` and
  `admin` apart from `content` — those are correct downward edges.
* No fix of `url()`'s `global $config` read (a real but separate behaviour
  change) and no cleanup unrelated to a phase.

---

# Backlog

Confirm each before starting; none is scheduled.

* **Content Security Policy** — deferred from old phase 6. Report-only first, then
  nonces for the admin's inline scripts and the deliberate raw header/footer
  snippets. The whole reason it was deferred is that a useful policy needs one of
  those two. Might just leave this out. No CDN loads, and output is always escaped with e().
* **Editor live preview** — an iframe over the existing token preview URL, so an
  editor sees the rendered page without leaving the editor.
* **Theme developer guide outside the app** — publish the in-app guide as a
  standalone document for theme authors.
* **More admin languages** — a language file plus its strings; no mechanism work.
* **Media library cost at scale** — `media_usage_map()` makes one pass per library
  page and the tabs scan whole columns. Fine today; revisit if a library reaches
  tens of thousands of files.
* **One-time reindex on upgrade** — an install upgraded past old phase 20 needs
  Utilities → Rebuild search index before author names are searchable. A
  marker-guarded reindex would remove that manual step.

# Deferred

* **Multi-language front end** — the design is agreed and recorded as Track D in
  `old-plan.md`: a `content_translations` table, non-default locales under a URL
  prefix, the default locale unchanged. Do not build it until a real client needs
  a second locale; start from that design rather than re-deciding it.

# Considered and not planned

* **Plugin/theme marketplace, visual builder, multisite, theme switching** — the
  product is one install, one theme; the theme's demo content and the integrity
  check cover the developer need.
* **REST/JSON API, headless, GraphQL, SDKs, CLI** — a whole public surface to
  secure and version; static export covers simple static hosting. No CLI by
  design; migrations run on request and from Utilities.
* **E-commerce, membership, comments, front-end accounts** — a different product.
* **External CAPTCHA** — honeypot, signed token and per-IP rate limit are the
  dependency-free answer.
* **Scheduled expiry / auto-unpublish** — scheduling publishes; taking content
  down is a deliberate status change, not a timer.
* **2FA, per-resource ACLs, GDPR/consent suite** — effort out of proportion to
  the threat model of a small brochure site; the existing roles and audit log are
  the bar.
* **S3/CDN/file-storage adapters, offsite/scheduled backups, local backup retention** — hosting choices, not CMS features, and a backup is a download you keep rather than a pile on the server.
* **Publish webhooks and outbound integrations** — nothing should call out when
  content is published; the static export stays a command the operator runs.
* **RSS/Atom feeds** — a blog-era distribution channel with no audience for a
  brochure site, and the sitemap already covers crawler discovery.
* **Marketing/CRM integrations, maps, oEmbed, mega menus, custom taxonomies** —
  raw header/footer scripts are the integration point; the rest waits for a
  concrete requirement.
* **Download-and-restore backup button** — deliberately absent; a wrong database
  bricks the site. Restore stays a documented manual step.
* **Rich-text editor completion** (blockquote/table/HR buttons, a media-library
  insert for rich text, new-tab links, an internal page picker) — dropped.
* **Live style switch in preview** — dropped; the browser inspector is enough.
* **SMTP delivery** — dropped. Password reset keeps using the host's `mail()`,
  and a host that blocks it needs a relay at the host level, not in the CMS.
* **Reusable/global content** (a blocks or globals table for a shared CTA,
  banner, testimonials or FAQs) — dropped; a content-model addition only worth
  making if a client asks.
* **JavaScript-free public navigation and forms** — the admin UI and the public
  theme may both assume JavaScript, so no-script fallbacks are not a goal.

Content import/export, once listed as item 29, **shipped** as the content package
utility (old phase 15): `theme/demo/*.json`, `content_package_*` and the
Utilities Export/Import cards. It is not a non-goal.

# Open decisions

Settle each before starting the work it belongs to.

* **Backlog:** is a Content Security Policy wanted at all, and if so how far —
  report-only, or report-only plus nonces for the admin's inline scripts?
* **Track D:** schedule the multi-language work when a real client needs a second
  locale, and decide then whether per-locale menu labels are in its first version.

# Notes

* `old-plan.md` is history. It records how the shipped features were built and
  why; do not add new phases to it.
* Query-driven views and the page cache: the cache key is the path, so every
  cache decision point must know about query parameters (`index.php` firebreak,
  `checkCache()`, the write path). Pagination needed two guards, not one.
* One kind of content image, one owner: **media** are editor uploads in the
  `media` table, addressed by id (or by an absolute URL somewhere else).
  `render_image()` and `resolve_image_value()` accept those two and nothing else;
  a value that is neither renders the CMS placeholder box. The theme ships no
  content images — only its SVG icons, its component previews and the app icons
  the manifest falls back to — so there is no `img()` helper and no theme
  filename space to collide with a media id anymore.
* Scheduled publishing is request-triggered (at most once a minute via
  `storage/.publish-check`); there is no cron.
* `theme/theme.php` decides the component palette per content type; `setup.php`
  is the schema source of truth for fresh installs.
* Two permission traps have already cost debugging time: a new web-root file
  created `600` (blank 500) and a `storage/` directory the web user cannot write
  ("readonly database"). Admin → Health reports the second; the `find` command in
  the phase rules catches the first.
* Keep conditional state and feedback CSS (error/success colours, `.status-*`,
  empty states, `.field-error`, `.notice*`, `.off-screen`); a static grep finding
  no uses is not evidence they are dead.
