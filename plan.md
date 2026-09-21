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
   * Schema changes go in **both** `core/helpers/setup.php` (fresh installs) and
     `migrate_registry()` (upgrades), and migrations are idempotent.
   * Removing a component from `available_components` is a content-affecting
     change: check existing content first (an unknown type becomes an HTML
     comment and is dropped on the next save).
3. **Admin UI and public theme may assume JavaScript.** Public navigation, menus and forms can use theme scripts to work.
4. No phase may introduce a new concept unless its Why says why.

## Phase table

No phase is scheduled. Everything the last tables held has shipped: small fixes
and tidy-ups, media fallbacks without theme placeholder files, component
previews with the Add component dialog, and the whole-site backup download.

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

* `content_rich_text_editor($ctConfig)` (new, in `core/helpers/content.php`)
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

* `core/helpers/content.php` — `content_meta_fields()` normalises the
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
* `core/helpers/common.php` counts a `media` field among the keys
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
* `core/helpers/content.php` owns the allowed values and normalises the schema
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
