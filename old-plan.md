# Micro CMS — plan

One ordered programme for a CMS that stays procedural PHP over SQLite with no
build step, no composer, no framework and no plugins. This file is the **single
plan of record**. It consolidates the former backlog, phased programme, advice
inventory and gap analysis, all of which have been removed.

## Document map

| File | Role |
| --- | --- |
| `plan.md` | This file. The single plan of record for scope, order and verification. |
| `README.md` | Product description, requirements and the shipped-feature list. |
| `AGENTS.md` | The constraints and conventions every phase must respect. |

Do not add another plan document. Extend this file, and update it when a phase
ships.

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
* **D — multi-language front end** (deferred; design already agreed).

## Rules every phase follows

1. **One phase at a time.** Implement, verify, report, stop. Do not start the
   next phase speculatively. A phase ships alone.
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
3. **Admin UI may assume JavaScript. The public front end may not.** Public
   navigation, menus and forms must render and work without script.
4. No phase may introduce a new concept unless its Why below says why.

## Phase table

Wave 1 makes the CMS complete for its actual job. Wave 2 adds depth and polish.
Wave 3 is opportunistic and can be dropped.

| # | Wave | Track | Phase | Size | Depends on |
| --- | --- | --- | --- | --- | --- |
| 1 | 1 | A | Correctness fixes | S | — |
| 2 | 1 | A | Test-suite reliability | S–M | — |
| 3 | 1 | A | Password reset | M | — |
| 4 | 1 | A | Editor autosave + unsaved-changes warning | M | — |
| 5 | 1 | A | Pre-publish checklist | M | — |
| 6 | 1 | C | Security headers | S | — |
| 7 | 1 | C | Maintenance mode | S–M | — |
| 8 | 1 | C | Form model completion | S–M | — |
| 9 | 1 | C | Settings gaps | S | — |
| 10 | 1 | A/C | Scheduling and visibility | S–M | — |
| 11 | 1 | A/B | Media pipeline into the theme + `og_image` picker | M | 1 |
| 12 | 2 | A | Media library UX + media usage before delete | S–M | 11 |
| 13 | 2 | B | Theme integrity check + Health | S–M | — |
| 14 | 2 | B | Theme asset auto-versioning | S | — |
| 15 | 2 | B | Theme demo content as data + reference parity | M | 13 |
| 16 | 2 | C | Navigation: content links, hide, server-side active state | M | — |
| 17 | 2 | C | Redirect search + conflict detection | M | — |
| 18 | 2 | C | SEO output polish | M | 1 |
| 19 | 2 | B/C | Accessibility pass | S–M | — |
| 20 | 2 | A | Search hardening | M | 1 |
| 21 | 2 | A | Version diff and compare | M | — |
| 22 | 2 | C | Publish webhook (dropped) | S | — |
| 23 | 2 | C | RSS/Atom feed (dropped) | S | — |
| 24 | 2 | C | Site scans: orphaned media, broken links | M | — |
| 25 | 3 | A | Rich-text editor completion | S–M | — |
| 26 | 3 | B | Live style switch in preview | S | 14 |
| 27 | 3 | C | SMTP delivery | M | 3 |
| 28 | 3 | A | Reusable/global content | M | — |
| 29 | 3 | A | Content import/export | M | — |
| D1–D6 | — | D | Multi-language front end | per plan | Wave 1 done |

Already shipped and therefore absent from the list: content CRUD and nesting,
taxonomy/archives, component editor, users/roles, menus, media variants, SEO and
redirects, revisions and trash, scheduled publishing, forms inbox with CSV,
pagination, duplication, robots.txt, sitemap, page cache, static export, backup
download, health, migrations, admin i18n. See `README.md` for the full list.

---

# Wave 1 — CMS completeness

## 1. Correctness fixes (A, S)

**Shipped.** All five fixes are in: `core/components/404.php` renders the
no-content fallback and a 404 answers `noindex, follow`; JSON-LD is enabled in
`theme/theme.php`, with an Organization entry on the homepage; the installer
builds the search index and Utilities gains "Rebuild Search Index"; `<html lang>`
falls back to `en`; the sitemap skips items whose robots override is `noindex`.

**Why.** Five latent defects make existing features wrong, and each is cheap to
fix. Correct them before adding more features.

**Work.**
* 404: ship `core/components/404.php` so the no-content fallback renders, and
  make the 404 response emit `noindex` (`seo_robots()` returns `index, follow`
  for status `404` today).
* JSON-LD: either set `'schema' => true` in `theme/theme.php` so the helper
  actually emits, or delete the dead gate. Prefer enabling it (add Organization
  for a brochure site).
* Search index: rebuild the index after first setup and expose a Utilities
  "Rebuild search index" action; `search_reindex_all()` currently has no
  production caller and seeded content has no `search_text`.
* `<html lang>` must fall back to the default when `site_language` is unset.
* Sitemap: skip published items whose per-page robots override is `noindex`.

**Verify.** Extend `tests/seo.test.php`, `tests/search.test.php`, `tests/http.test.php`; render the no-content 404 through the local server.

**Reject if** it grows into an SEO audit; this is five small fixes.

## 2. Test-suite reliability (A, S–M)

**Shipped.** Reproduced: two concurrent runs share `tests/.tmp/storage` and each
replaces `data.sqlite`, so one deletes the database out from under the other's
open connections. `tests/run.php` now takes an exclusive lock on
`tests/.tmp/run.lock` for the run, and the three server suites that were missing
it reap their `php -S` process with `proc_close()`. `tests/README.md` records why.

**Why.** The verification bar for every later phase depends on a clean run, and
the HTTP suites are flaky under load here: two runs gave 271/292 and 283/292,
failing with transient SQLite `database is locked` / `attempt to write a
readonly database`, while the suites pass individually.

**Work.** Reproduce, then make the harness robust: give each HTTP suite its own
database, retry or serialise on SQLite lock, or ensure `tests/.tmp` is reset
per suite. If the cause is the sandbox rather than the harness, record the
environment caveat in `tests/README.md` instead of changing product code.

**Verify.** `php tests/run.php` three times, green each time.

**Reject if** it touches product behaviour or introduces a test framework.

## 3. Password reset (A, M)

**Shipped.** A `password_resets` table (hashed token, expiry, single use) with
its migration; public `admin/auth/forgot-password.php` and
`admin/auth/reset-password.php` in the login page's shell and both languages; the
link is mailed in production and appended to `storage/logs/forms.log` otherwise;
requests and resets reach the activity log, and the page never confirms whether an
address exists. The per-address limit counts every request, so it cannot leak
existence either. A completed reset ends other sessions through a password-hash
fingerprint kept in the session. The login page links to it.

**Why.** There is no recovery path: a client who forgets a password is locked
out and needs a manual database edit. This is the most conspicuous missing
mainstream CMS feature.

**Work.**
* A `password_resets` table (schema + migration): hashed token, user id, expiry,
  single use.
* A rate-limited "forgot password" page and a reset page, both in the admin's
  own look; both language files.
* Send the link through the same `mail()` path the forms use; in non-production,
  log it the way `core/form-submit.php` logs instead of sending.
* Log the request and the reset to the activity log; never reveal whether an
  address exists.

**Verify.** New tests in `tests/auth.test.php`: token expiry, single use, bad
token, rate limit, and that a successful reset invalidates old sessions.
Manual round trip through the local server with `forms.log`.

**Reject if** it needs an email service dependency (SMTP is phase 27) or a
second auth system.

## 4. Editor autosave + unsaved-changes warning (A, M)

**Shipped.** The editor posts its own form to the existing `content/save` with
`autosave=1` every 60s while the form differs from its state on load, so the
snapshot goes through the same capability check and validation a real save does
and only writes a version (`reason` `autosave`); the live row and the cache are
untouched. At most one autosave is kept and it does not count against
`versions.keep`. Reopening an item whose autosave is newer than the stored row
shows a notice that loads the draft back into the editor; an autosave is unsaved
work, so it is never written straight over the live row (which could publish
half-finished content). The version history offers the same load-in-editor action
for an autosave instead of a restore, and Dismiss discards the draft.
`beforeunload` warns while the form is dirty. Autosave needs the item to exist,
so a brand-new item is not covered until its first save.

**Why.** A crashed or navigated-away tab loses work that `content_versions` can
already hold. This is the top editor-safety gap.

**Work.**
* A draft version every ~60s through the existing versions helper, reason
  `autosave`; offer the most recent autosave back on reload through the existing
  restore path.
* `beforeunload` warning while there are unsaved edits.
* Do **not** add tables or a separate store. Keep autosaves from filling
  `versions.keep`.

**Verify.** Extend `tests/versions.test.php` for the reason and the retention
interaction; manual round trip in the editor.

**Reject if** it needs an AJAX endpoint that bypasses `save_content()`'s
validation, capability checks or cache invalidation.

## 5. Pre-publish checklist (A, M)

**Shipped.** `content_publish_checklist()` walks the body against each
component's own schema. Settled decision: an empty title and empty `required`
component fields **block**; image descriptions, dead links (a label with no
target) and a missing meta description only **warn**. A blocked publish is saved
as a draft instead, so nothing goes live and the editor's work is kept; the
editor then reloads with the checklist and an explanation. Bulk publish skips
blocked items and reports the count. Drafts are never checked.

**Why.** Editors can publish a page with no H1, images with no alt text, empty
links or a missing description. The per-block `required` schema flag is a UI hint
today and is not re-checked on save.

**Work.**
* Server-side, on a save that transitions to `published` (and in bulk publish):
  required component fields, image alt text, empty links, missing title and
  missing meta description.
* Present as a checklist in the editor; decide per rule whether it blocks or
  warns (see open decisions). Drafts are never blocked.

**Verify.** Extend `tests/validate.test.php` and `tests/content.test.php` for
each rule; manual editor check.

**Reject if** it becomes an SEO audit tool or blocks saving a draft.

## 6. Security headers (C, S)

**Shipped.** `security_headers()` in `core/helpers/common.php` returns the
baseline and `send_security_headers()` is called once from `index.php`, so every
response — front, admin, media, redirects, 404s and the cache firebreak — carries
`X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` and
`Referrer-Policy: strict-origin-when-cross-origin`, plus
`Strict-Transport-Security: max-age=31536000` over HTTPS only (no
`includeSubDomains`). Settled decision: **no CSP in this phase.** The admin boots
from inline scripts and Settings deliberately allows raw header/footer snippets,
so a useful policy needs nonce plumbing or `'unsafe-inline'`; the phase's own
Reject clause says to ship the non-CSP headers and defer the rest.

**Why.** No CSP, `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`
or HSTS is sent anywhere.

**Work.** One helper sets the safe baseline on front and admin responses:
`X-Content-Type-Options: nosniff`, a frame policy, `Referrer-Policy`, and HSTS on
HTTPS. Add a CSP once the admin's vendored Quill and the raw header/footer
snippet settings are accounted for; a report-only CSP first is acceptable.

**Verify.** Extend `tests/http.test.php` for the headers; confirm the editor and
the public front end still work through the local server.

**Reject if** CSP requires a nonce plumbing exercise or breaks the admin editor;
ship the non-CSP headers and defer the rest.

## 7. Maintenance mode (C, S–M)

**Shipped.** Settings gained a "Maintenance" group with a toggle and a message.
With it on, the front path answers `503` with `Retry-After: 3600`, `no-store`
and the unthemed message; any signed-in user (and therefore token previews)
keeps working, and the admin is untouched. Enabling clears the page cache,
nothing writes to it while the site is closed, and `warm_cache()` refuses to run,
so the cookie-less cache firebreak cannot serve a stale page. A dashboard notice
reminds signed-in editors while it is on.

**Why.** A launch, migration or host move needs the public site taken down while
the admin keeps working.

**Work.** A Settings toggle and message. Visitors get `503` with `Retry-After`;
signed-in admins and token previews keep working; the response is never cached.

**Verify.** Extend `tests/http.test.php`: anonymous 503, admin unaffected,
nothing written to the cache.

**Reject if** it becomes a scheduling or "coming soon" page feature.

## 8. Form model completion (C, S–M)

**Shipped.** `contact-section.php` renders `select` and `radio` as real markup
and uses an optional `label`, falling back to the key; the demo contact form
gained a `select` and a `radio`, and both demo forms gained labels. Validation
moved into `form_submission_validate()` in `core/helpers/forms.php`: required,
the declared type (email/tel/url/number), select/radio options, and a per-field
length bound with a `max` override. A notification setting may name several
comma-separated addresses, and each is sent a separate message. The inbox search
was already implemented (`form_submission_filter()` searches the JSON `data` and
the inbox has a search box), so that work item needed nothing.

**Why.** Forms are a main CMS feature and the model is thin: a `select` or
`radio` field declared in `theme.php` renders as invalid markup, labels are
auto-derived from keys, only required+email are validated, there is one
notification address, and the inbox cannot be searched.

**Work.**
* Render `select` and `radio` correctly in `theme/components/contact-section.php`;
  accept an optional `label` in the field definition.
* Server-side validation by declared type (length bound for text, tel/url
  sanity) in addition to required.
* Multiple notification recipients (validated comma list) instead of one.
* Submission search in the inbox over the JSON data (`LIKE` is fine).
* Keep forms developer-defined in `theme.php`; no builder UI.

**Verify.** Extend `tests/forms.test.php` and `tests/forms-http.test.php`;
manual contact and newsletter forms.

**Reject if** it grows into a drag-and-drop form builder or adds a CAPTCHA
service.

## 9. Settings gaps (C, S)

**Shipped.** Six settings added, each validated and translated: timezone (IANA,
replacing the `SITE_TIMEZONE` constant), date format, site description (the
meta-description fallback), logo, favicon and custom CSS. The theme now formats
dates through `format_date()`/`site_timezone()`, so the date format and timezone
reach the public pages, and the header brand is the logo setting, then the theme
manifest, then the site title. Settled decision: **custom CSS is a raw setting**
in the Custom code group beside the existing raw header/footer scripts, injected
last so it can override the theme — not a design editor.

**Why.** Several things operators expect are hardcoded or absent: timezone
(`SITE_TIMEZONE` is a constant), date format, site description, logo, favicon
and custom CSS.

**Work.** Add settings with validation and both language files: timezone
(default stays `Europe/Stockholm`), date format, site description (default meta
description), logo, favicon, custom CSS. Keep the theme manifest as the fallback
for logo/favicon.

**Verify.** Extend `tests/settings.test.php`; check each value's effect through
the local server.

**Reject if** it becomes a theme/design editor.

## 10. Scheduling and visibility (A/C, S–M)

**Shipped.** The content list already had a Scheduled column showing the date,
with a Scheduled status tab that filters to it, and the dashboard already
surfaced scheduled items and new/waiting form submissions as attention tiles.
The dashboard's submissions count and tile now require `forms.view`, so an author
is no longer shown a count and a link to an inbox that 403s.

The admin sidebar is filtered by the same map: every navigation link is gated on
the capability its page requires (`admin_can_open()`), and a section disappears
when none of its links survive. That generalises the `forms.view` fix to the
whole navigation — before it, an author was offered Media, Menus, Redirects,
Categories, Tags, Activity, Health, Settings, Users and Utilities, nearly all of
which answered 403, and the in-app docs claimed otherwise.

**Decision: no publish-date sorting.** Sorting or filtering the content list by
publish date is deliberately skipped: the Scheduled status tab already narrows
the list to exactly the items whose date matters, so a second sort control would
add UI without answering a question the tab does not.

**Decision: no expiry feature.** Content is taken down by changing its status,
not by a timer, so there is deliberately no `expires_at` and no expiry branch in
`publishing_check()`. Scheduling still publishes.

**Reject if** it becomes a calendar UI.

## 11. Media pipeline into the theme + `og_image` picker (A/B, M)

**Why.** The media pipeline is built but unreachable: `picture()`/`media_url()`
have no caller in `theme/`, so uploaded media never renders as WebP/srcset/LQIP.
The trap is that a media filename in `meta.thumbnail` resolves as a theme asset
and 404s. The SEO `og_image` field is declared `media` but rendered as a text
input.

**Work.**
* Let listing components and layouts resolve media ids through `picture()` /
  `media_url()`, and expose the presentation meta that drives them (thumbnail,
  gallery) as editor fields for the content types that use them — or document a
  deliberate per-theme decision either way.
* Wire the SEO panel's `og_image` to the existing image picker.
* Keep theme-asset filenames (`img()`) valid where they are intended.

**Verify.** Extend `tests/media.test.php`, `tests/seo.test.php` and
`tests/theme.test.php`; confirm rendered WebP/`srcset` on a real page through the
local server.

**Reject if** it forces media ids into every theme or changes the content model
for all types.

**Shipped.** Settled decision: **a media id is the primary stored value**, with a
theme filename or absolute URL accepted as a placeholder/fallback, so no theme is
forced onto the media library and seeded content keeps rendering. New
`render_image()` is the one call a theme uses for an editor-settable image: a
media id becomes `picture()` (WebP `srcset`, LQIP, `alt` from the media row), a
filename or URL becomes a plain `<img>` — deliberately *not* wrapped in
`.image-wrapper`, which `main.js` only reveals for `picture()`. Every
editor-settable image in `theme/components/` and `theme/layouts/` now goes
through it (the header brand keeps `site_logo_url()`, which resolves the same
value shapes); that also fixed `blog-archive.php`/`taxonomy.php` emitting a bare
relative `src="900x400.png"` — a guaranteed 404 — and gave
`blog-featured-section.php` a `media_url()` variant for its CSS background.
`resolve_image_value()` gained an optional `$width`. Schema `image` props use the
media picker, an empty image prop falls back to its schema `default` at render
time (so clearing a section image brings the theme's placeholder back, while a
cleared text field stays empty), and the pre-publish alt rule accepts a library
image's own `alt_text`.

Presentation meta is declared per content type in the manifest — `blog_post` and
`portfolio_item` get a `thumbnail`, `portfolio_item` also a repeatable `gallery`
— and saved from a matching Images panel (`content_collect_images()`). The SEO
panel's `og_image` is now a real picker field. Verified through the local server:
a media id in `meta.thumbnail`, a component image prop, a portfolio gallery and
a taxonomy archive all render `<picture>` with WebP `srcset`, the media file
serves 200, and a theme filename in the same slot stays a plain absolute `<img>`;
an editor save round-trips `meta_gallery[]` and clears it when emptied.

---

# Wave 2 — depth and polish

Each phase below is still independently shippable; detail is deliberately
shorter. Ask the user to expand on each item before implementation.

## 12. Media library UX + media usage before delete (A, S–M)

**Shipped.** The library already had the file-type tabs (with counts) and search
over name, alt text and description from earlier work. This phase added:

* **"Where is this file used?" before delete.** `media_usage_map()` makes one
  pass over content, settings and menus and returns the places each file is
  referenced; both delete entry points name them in the confirmation. Matching is
  deliberately narrow — an id is only read from image-typed component props and
  the image settings, because matching any number would collide with a `limit` of
  3 or a WebP quality of 80, while a media URL or path is matched anywhere (a
  base path is a unique `YYYY/MM/random` folder name). Saved versions and form
  submissions are excluded: they are history, not what the site renders.
* **Paging and a cheaper listing.** 24 rows a page, filters applied by the
  database, and variant dimensions read from `sizes_json` instead of a
  `getimagesize()` on every variant file of every row — one filesystem read per
  variant per page view, which was the library's real cost.
* **Bulk delete.** A checkbox per row, a select-all, and a toolbar that appears
  with the selection; the toolbar is its own form outside the table (rows already
  contain a delete form, and forms cannot nest) and the checkboxes join it with
  the `form` attribute. One `media_delete()` serves the single button and the
  bulk action, so the two cannot drift; the endpoint re-reads every id and
  applies the same 200-item limit as the content bulk endpoint.
* **`media.title` dropped** — it was never read or written. `setup.php` no longer
  creates it and a migration removes it from existing databases.

**Decisions.** Deletion warns and allows in one step: the confirmation names up
to three places plus the true count. Bulk media deletion confirms first, because
unlike content it cannot be undone. **Drag-and-drop upload and AVIF generation
are deliberately out** — WebP already covers current browsers, and multi-file
upload needs one POST per file. No crop unless a concrete client asks.

**Two things this uncovered, both fixed in passing.** `admin_trans()` replaced
`:page` before `:pages`, so `common_page_of` rendered "Page 1 of 1s"; placeholders
are now substituted longest-first, which also fixes the activity log, wrong since
that page shipped. And `migrate_drop_column()` is best-effort: SQLite only learned
`DROP COLUMN` in 3.35, and on an older host the column stays rather than aborting
the registry and stalling every later migration.

**Known, deliberately left.** `media_delete()` reports `invalid_path` when a
row's folder is already missing, so such a row cannot be removed from the
library — phase 24's Utilities media scan now handles that class (it reports the
row, and its repair removes it). The per-page `media_usage_map()` pass and the
tabs' full-column scan are both cheap today and can be revisited if a library
reaches tens of thousands of files.

**Verify.** `tests/media.test.php` (the usage map, `media_delete()`), `tests/http.test.php`
(the confirmation content, paging, stored variant sizes, bulk delete),
`tests/admin.test.php` (placeholder order) and `tests/migrate.test.php` (the
column drop keeps the rows around it); manual library use.

## 13. Theme integrity check + Health (B, S–M)

**Shipped.** `theme_manifest_problems()` in `core/helpers/health.php` checks
everything the manifest names against the filesystem and against the manifest
itself, grouped into the five areas below, and `health_checks()` reports one row
per group: `ok` with what was checked, or `fail`/`warn` with the specific
problems (the first four, plus a count) and a fix hint. It resolves theme-first
with the `core/components/` fallback exactly as `component()` does, and checks
only what the manifest reaches — an unreferenced component file, or the
placeholder child name inside `core/components/sample-component.php`, is not an
error. `theme.php` now declares `defaults` explicitly, turning an implicit
contract into a checked one, and `form_submission_field_types()` is the single
list of field types a public form may declare.

Verified with fixture tests for every problem class and severity, a clean
fixture for false positives, a guard that the shipped theme reports none, the
Health rows on a healthy install, and a live check with a deliberately ghost
layout and component in the manifest: those two rows went to Problem with the
exact names, and the other three stayed OK.

**Why.** Nothing validates the manifest. `theme_config()` only checks that
`theme/theme.php` exists and returns an array, so a broken declaration is silent
until a visitor hits it: a missing layout throws out of `render_layout()` (a
blank 500), a missing header/footer or `available_components` name prints
"component not found" into the page, a missing partial is a fatal `require`, and
a missing stylesheet is a 404 that leaves the page unstyled.

**Checks.** Grouped by area, each becoming one Health row; everything resolves
theme-first with the `core/components/` fallback, exactly as `component()` does.

* **Layouts** — every `layouts` key has `theme/layouts/<key>.php`; every content
  type's `default_layout` and `taxonomy_layout` resolves; `search_layout`, when
  declared, resolves; `defaults.layout`, when declared, resolves; and the layout
  the **settings** select resolves against the manifest (a stale setting 500s
  every page). *fail*
* **Components** — every `headers`/`footers` key, every `available_components`
  name, and every `allowed_children` name resolves (only for components the
  palette reaches, so `sample-component.php`'s placeholder is not flagged);
  `defaults.header`/`.footer` resolve; the settings' header/footer selections
  resolve; a component's `menu` field default is a declared `menu_locations` key
  and its `content_type` default a declared content type. *fail*, except the two
  field-default cases, which only mislead the editor: *warn*
* **Assets** — every `styles` entry and `scripts[].src` exists under
  `theme/assets/` with `?v=` stripped (absolute URLs skipped, since they cannot
  be checked); `icons.favicon`/`icons.logo`, when declared, exist. *fail*, icons
  *warn*.
* **Partials** — every literal `theme('partials/…')` referenced from
  `theme/**/*.php` exists; a missing one is a fatal require. *fail*
* **Forms** — every declared form field type is one the validator knows, and
  every `select`/`radio` field declares options. An unknown type silently falls
  back to plain text today. *warn*

**Decisions.** Grouped rows rather than one cell, so a developer can see which
part of the theme broke. Selections are checked from **settings only** — the
content columns are not scanned; stale per-item values are a different class of
problem. All four optional check groups are in, including form fields, which is
as close to a general linter as this goes. `defaults.layout/header/footer` should
become an **explicit manifest block** (`theme.php` gains one, the docs example
follows): twelve read sites currently end their fallback chain at
`$theme['defaults'][…]` without a literal, which is an undefined-key warning the
moment settings ever lack the value, and declaring it turns an implicit contract
into a checked one.

**Design.** `theme_manifest_problems(array $theme, string $themePath): array`
returning problems grouped by area, in `core/helpers/health.php` (its only
consumer). Taking the manifest and base path as arguments is what makes the
fixture below possible without touching `theme/`. Health row labels stay plain
English like the existing ones, so no new translation keys.

**Verify.** `tests/theme.test.php` with a deliberately broken fixture under
`tests/.tmp/` — one case per problem class, a clean fixture proving there are no
false positives, and a guard that the **shipped theme reports none**;
`tests/health.test.php` asserting the rows exist and are `ok` on a healthy
install.

**Reject if** it becomes a general theme linter: no PHP syntax checks, no CSS
analysis, no dead-file detection, no schema-type auditing.

**Boundary.** If `theme/theme.php` itself is missing or returns a non-array, the
admin cannot render at all (`sidebar.php` calls `theme_config()`), so Health
cannot report it. That case stays a blank 500.

## 14. Theme asset auto-versioning (B, S)

**Shipped.** `asset()` now stamps theme asset URLs with the file's modification
time, exactly as `admin_asset()` already did for the admin UI, and the three
hand-maintained `?v=` counters are gone from `theme/theme.php`. Because
`asset()` has only four production callers, this versions CSS, JS, the theme
logo **and** the favicon in one change, with no call site touched. Both
functions now stamp through one small helper, `version_asset_url()`, so the rule
cannot drift between them.

**Why.** `admin_asset()` already stamped admin URLs, but theme assets relied on
someone remembering to bump a counter in the manifest — and `AGENTS.md` told
theme developers to do exactly that. Forgetting meant visitors kept a stale
stylesheet.

**Details worth knowing.** `asset()` strips any query before resolving the path,
so an old `?v=5` entry is replaced by the stamp rather than doubled. External
URLs pass through untouched. A missing file gets the plain URL, not a version
that points at nothing. The `admin_asset()` docblock and `AGENTS.md` were
corrected — both had described the removed counters.

**Decisions.** `img()` is **not** stamped, so theme images keep clean URLs and
`og:image` and media fallbacks are unaffected. Cached pages are **not**
re-invalidated when a theme file changes: a cached page keeps the stamp it was
rendered with until it expires or is cleared (demonstrated, not just asserted) —
making the cache revalidate against asset mtimes is a separate mechanism.

**Verify.** `tests/theme.test.php`: a plain and a nested asset are stamped,
absolute URLs pass through, an old `?v=` is replaced rather than doubled, a
missing file is unstamped, `version_asset_url()` follows a file that changes
(and drops the stamp when it disappears), and a rendered page carries the stamp
for every stylesheet and script. `tests/export.test.php` covers the stamped URL
surviving static-export rewriting, which now matters because every rendered URL
carries a query string. Live: editing `style.css` changed the URL on the next
render, a cached page kept the old stamp until the cache was cleared, and the
admin's own stamp still matched its file's mtime.

## 15. Theme demo content as data + reference parity (B, M)

**Shipped.** The package format and the importer are in.
`theme/demo/content.json` and `theme/demo/settings.json` were **generated from
the old seed through the new exporter**, so no content was retyped by hand, and
`setup.php` now seeds by importing them: **1047 lines down to 437**, with no
behavioural change (the whole suite passes untouched). The `content_package_*`
helpers sit beside the existing export functions, `format: 1` guards both
documents, and nothing carries an id — parents, taxonomy links and the homepage
are `<type>:<slug path>` references resolved on import.

Utilities now has a **Content package** panel of two cards, each opening a dialog.
**Export** explains the two
documents and offers one button each — `content.json` and `settings.json`, no
checkboxes and no zip. **Import** asks where a package comes from (uploaded
files, or the theme's own demo files with the field left empty — the dialog says
which, and an upload replaces the demo) and what to take, then **Preview import**
renders the report *inside the reopened dialog* — what would be deleted, created
and changed — and **Import now** applies it. Uploaded
files are stashed under `storage/imports/` (deleted on apply, or an hour later) so
the second step needs no re-upload, and the health report gained
`storage/imports/`.

The round trip is exact: export, wipe, import reproduces both documents byte for
byte, search is reindexed and the homepage resolves to the new row. A package
naming a content type, component, layout or unportable setting this theme does
not have is refused with the names in the report and nothing written; content and
settings import independently; an unresolvable homepage is skipped with a warning
rather than written as a dangling id. A fresh install deliberately does **not**
write a sitemap — it has no configured origin yet, so it would fill with
localhost URLs.

**A content-only import now carries the homepage across.** Replacing content
replaces every id, which used to leave `homepage_id` dangling — and a dangling
homepage serves the **404 page at `/`**. The importer remembers what the homepage
was as a `<type>:<path>` reference, re-matches it among the imported rows, and if
the package has no such page it clears the setting and says so in the preview and
the toast, instead of leaving `/` broken.

The coverage pass landed with it. The demo now carries **four taxonomy terms** —
a blog category and two tags attached to the demo posts, and a portfolio category
— so `/category/…` and `/tag/…` render the blog archive and the generic taxonomy
archive instead of 404ing, and the blog layout's badges have something to show. A
**`landing` page** uses the manifest's header-less layout, which no page used
before, so every declared layout renders on a fresh install. `contact-section`
and `blog-preview-section` now share **`theme/partials/form.php`**, which renders
any declared form type (fields, token, honeypot, feedback) and ships its own CSS
and JS with the first form on the page: the blog signup finally posts to the
`newsletter` type instead of being dead markup. The theme developer guide gained
the reference-page-to-demo-page mapping, and says blog comments are out of scope.
The demo stayed exporter-written — `tests/bootstrap.php` rebuilds the seed
template when `theme/demo/*.json` change, and counts in the suite derive from the
demo rather than from a hard-coded 15.

The installer now also seeds **one account per role** — `admin` (administrator),
`editor` and `author`, each password its username, listed in `README.md` — so the
capability matrix can be tried from every point of view on a fresh install.
Users are still never part of a content package: they are schema-side seed data,
not content.

**Parity fixes after shipping.** Comparing the rendered demo against the
reference turned up three theme bugs, all fixed: the shared layer had no `h1`
rule, so every page heading fell back to the browser's 2rem and bold instead of
the reference's 2.5rem medium (the whole heading block now mirrors Bootstrap's,
including `line-height: 1.2`); the universal reset in `style.css` declared
`font-family: sans-serif` on `*`, which applies to every element and silently
overrode the stack `body` declares; and the portfolio layout forced the project's
listing *thumbnail* into the full-width slot, so a 600×400 image sat left-aligned
in a 1044px column while the wide shot was squeezed into a half column — the
gallery drives that page now, and the projects carry a wide cover plus two
supporting images like the reference's project page. A fourth: the shared layer
never defined `.order-first` / `.order-lg-last`, so the about feature section's
**Image Position: right** option rendered exactly like `left` — a prop promising
something the stylesheet could not do. Both classes are in now, at the
breakpoint Bootstrap uses, and `tests/theme.test.php` renders the component both
ways so the option cannot quietly become a no-op again.

**Why.** Two things were wrong with treating `theme/` as the worked example. The
demo content that shows what the theme can do was buried in `setup.php` — about
500 of its 1047 lines — so a theme developer's data lived away from their theme
and only the installer could produce it. And the demo did not exercise everything
the theme implements: it seeded **no categories or tags**, so `blog-archive.php`
and `taxonomy.php` never rendered on a fresh install, the blog layout's category
and tag badges never appeared and `taxonomy-archive.css.php` was never used; the
`landing` layout belonged to no page; and `blog-preview-section` shipped a
hard-coded newsletter form that did nothing (no action, no token) while
`contact-section` already rendered the declared `newsletter` form type properly.

The minimal starter theme this phase used to describe is **dropped**: the
default theme is the example, and the developer need is met by it being complete,
reachable and documented. It already mirrors the reference site page for page —
index, about, contact, faq, pricing, portfolio overview, portfolio item, blog
home and blog post all map to existing components and layouts.

**Work.** Three ordered steps, each shippable on its own.

1. **A package, split in two.**
   * `content.json` — `content` (type, slug, title, status, layout/header/footer,
     meta, body, published_at, and a parent named by **slug**), `taxonomies` with
     the content that uses them, and `menus`.
   * `settings.json` — only the settings that describe the site's content: site
     title, homepage **slug**, default layout/header/footer, per-type URL
     prefixes, menu-to-location assignments, site description and title suffix.
   * Both carry `format: 1`. Ids are never carried; parents, taxonomy links and
     the homepage are resolved by slug on import. Media is deliberately absent —
     a demo references theme image filenames, which `resolve_image_value()`
     already handles, so a package stays text.
   * Excluded **by name**, because a package must never carry them: `site_url`,
     `timezone`, `admin_default_language`, `media_sizes`, `generate_webp`,
     `quality_webp`, `strip_metadata`, `contact_email` (environment and ops), and
     `custom_css`, `header_scripts`, `footer_scripts`, `robots_extra`,
     `maintenance_mode`, `maintenance_message` (raw and admin-only). Importing a
     file that carried `site_url` or `timezone` would wreck the install it lands
     on.
2. **`content_export()` / `content_import()`** beside the existing export
   helpers. Export streams the two documents. Import applies the sections it is
   given — **content, taxonomies and menus as one unit**, settings as the other —
   and **validates first, refusing the whole import with a report** if the
   package names a content type, component, layout, header or footer this theme
   does not have; nothing half-imports. It runs in a transaction, reindexes
   search, invalidates the cache and rebuilds the sitemap. A **dry run** reports
   what would be deleted, created and changed, including each setting's old and
   new value, and resolves the homepage slug against the content that will exist:
   an unresolved homepage is skipped with a warning rather than written as a
   dangling id. Menus are wired by the imported assignments when settings come
   too, and by the existing slug-matching fallback in `get_menu_for_location()`
   when they do not.
3. **Wire it up and fill the gaps.**
   * `theme/demo/content.json` and `theme/demo/settings.json`; `setup.php` seeds
     by importing them instead of 500 lines of arrays — the same items in the
     same order, so slugs and URLs were unchanged.
   * Utilities gains **Export** — one download button per document, no zip — and
     **Import** with two sources: the theme's demo files (choose content, settings
     or both) or uploaded JSON — one input accepting several files, sections
     detected from the document keys, a stray or duplicated section refused.
     Import is
     two-step: **Preview** validates and reports, then **Import now** applies.
     Uploads are stashed under `storage/imports/` (random name, deleted on apply
     or after an hour) so the second step needs no re-upload; the health check's
     writable-directory list gains that directory. The demo path needs no stash —
     it re-reads the files.
   * The coverage pass: categories and tags in the demo content attached to the
     demo posts, the `landing` layout on a new `/landing/` demo page, and the form
     markup `contact-section` and `blog-preview-section` share extracted into
     `theme/partials/form.php` so the blog signup posts to the newsletter form
     type. The developer guide
     gains the reference-page-to-demo-page mapping, so "resembles the reference"
     stays checkable, and says comments are out of scope — the reference's blog
     comments have no CMS counterpart, which "considered and not planned" already
     records — so nobody hunts for the missing component.

**Decisions.** A package carries content, taxonomies, menus and that one
content-facing settings list — never users, media binaries, schema, or
environment settings, which is what keeps this a content tool rather than a
site-migration tool. Export produces **two files** matching the theme's layout;
import takes any subset, which is also how live content moves between installs.

**Verify.** A round trip per section: content only, settings only, and both —
slugs, types, titles, URLs, taxonomy links, menus, assignments and settings all
match, and the settings-only case leaves content untouched. A package naming an
unknown content type, component or layout is refused with the offending names in
the report and nothing written. Seeding from the JSON reproduces every demo item
and taxonomy link — counts, types, slugs and attachments — with the search index
built. **Every layout the theme ships renders on the front end**, which is what
the coverage pass buys: `tests/http.test.php` fetches one seeded URL per layout,
and `tests/export.test.php` asserts the demo uses every declared page layout and
both archive layouts. The phase-13 check stays clean, and the admin flow is
exercised through the local server: preview, import, reset, and a rejected file.

**Reject if** it becomes a full site-migration tool — users, media binaries,
schema — or introduces runtime theme selection.

## 16. Navigation: content links, hide, server-side active state (C, M)

**Shipped.** Menu items are `{type, label, slug, target, hidden, children}` inside
the `menus.items` JSON blob, so nothing about this needed a schema change. What
was wrong, measured against a fresh install before touching anything:

* **Active state was client-side and mostly wrong.** The header's `js` set
  `link.style.fontWeight = '700'` on any `nav a` whose `href` equalled
  `location.pathname`. It marked `/`, `/about/` and `/pricing/`, missed every
  URL item (stored as `/blog`, `/blog/…`, `/portfolio` — no trailing slash),
  missed posts, archives and search entirely, never marked a parent, wrote no
  `aria-current` and no class, and the public front end may not depend on JS.
* **A rename was only survived by accident** — through the 301
  `save_content()` records for a moved page, i.e. a redirect hop on every menu
  click.
* **Nested pages linked to the wrong URL**: the picker stored the leaf slug and
  the header rendered `url($slug)`, so `/services/consulting/` became
  `/consulting/`.
* **Only pages could be linked**, which is how the demo's Blog and Portfolio
  submenus ended up as hand-typed URLs nothing validated.

**What shipped.**

* **Items identify content.** `content_id` (content items) and `hidden` (any
  item) joined the item shape, and `type` is now a content type key, `url`, or
  `category` / `tag` for an archive. `admin/menu/save.php` whitelists all of it
  and writes neither key when it carries no information.
* **One resolver, at render.** `menu_items_prepare()` resolves each item —
  `content_url()` (new, with `content_path_rows()`) builds a content URL from
  the type's prefix and the row's **current** parent chain — drops hidden
  branches, and marks the active trail. A link with a live id follows a rename;
  with no id, or an id whose row is gone, unpublished or trashed, it falls back
  to a live row matching the stored slug, which keeps every menu written before
  this change working; when neither resolves the item is `broken` and both
  components render its label as text instead of a dead link.
* **Active state is markup.** The exact match gets `active` + `aria-current`,
  its ancestors get `active`, matching ignores the trailing slash and the query
  string, and an item owns its subpaths — so Blog is active while reading a
  post, and the site root only ever matches itself. `utilities.css` gained
  `.nav-link.active`, `.link-light.active` and `.dropdown-item.active` at
  Bootstrap's colours. The inline-style script is deleted.
* **The editor offers what exists.** The picker lists every published page,
  blog post and portfolio item with its real path, plus category and tag
  archives, grouped by type; content items keep their slug as a fallback but
  show the resolved link read-only, and only a custom URL is typed. Hidden items
  stay in the tree, dimmed, and an item whose link no longer resolves is flagged
  where it was created. The hidden switch is an **eye button** beside the row's
  other actions — struck through and muted while hidden — so the row, its legend
  and the button all read one state; a child of a hidden item keeps the plain eye
  because it carries no state of its own. A parent's controls sit together in the
  middle of its row, and the whole row is the drag handle.
* **Packages stay id-free.** Export strips `content_id` recursively, import
  re-resolves it from `type` + slug once the content has landed, and `hidden`
  travels.
* **The demo menus** now link to content (blog page, a post, the portfolio page,
  a project) plus a `News archive` child that demonstrates a taxonomy link.

**A prerequisite bug, found on the way and fixed.** `save_content()`'s UPDATE
never wrote `slug` — it has been missing since the first commit — while
`redirect_record_slug_change()` assumed it did. So renaming a page kept the old
URL *and* recorded a 301 from that still-live URL to the new one, which does not
exist: **renaming a page bricked it**, because redirects are served before
routing (`core/bootstrap/front.php`). One column and one bound parameter fix it;
`tests/redirects.test.php` now asserts the new slug is written, which is the
assertion whose absence let this survive.

**Decisions.** The id is a *resolution hint*, never a requirement. Broken items
render as text, not as a 404 link. **No rendered preview in the editor** — the
item tree is the editor's view and the live site is a click away; the only
addition is the inline broken-link marker. Labels stay the editor's text and are
never rewritten from the page title. `content_url()` is now the one place a
content URL is built: the two archive layouts and the blog list section use it
instead of assembling prefix + slug themselves, which also gave those links the
trailing slash and subfolder base they were missing.

**Verify.** `tests/menus.test.php` (17): a renamed page resolves to its new path,
a nested page to its full path, a trashed page is broken while a legacy item with
no id still resolves by slug, archive and custom items resolve, the active trail
marks the page and its section, the root is only active on itself, matching
ignores slash and query, a hidden branch is pruned but kept for the editor, and a
package export carries no ids while import resolves them. `tests/http.test.php`
fetches `/about/`, a post and `/privacy/` and asserts which link is active and
that exactly one carries `aria-current`, with a hidden item proving absent from
the site and present in the editor — markup, so the no-JS case is covered.
`php tests/run.php` → 403 passed. Verified in the browser too: the active
colours, and the editor's picker, hidden switch and read-only links.

## 17. Redirect search + conflict detection (C, S)

**Shipped.** `redirect_save()` used to write whatever it was given; the admin page
checked only that the old path was non-empty, unreserved and that the target
looked like a URL. Probed against a fresh install, six things went wrong:

* **A redirect could shadow live content.** `redirect_save('about', 'contact')`
  succeeded while the About page existed, and because a redirect is served before
  routing (`core/bootstrap/front.php`) the live page became unreachable.
* **Reserved paths got through the helper.** The admin form refused `admin`, but
  `redirect_save('admin', 'dashboard')` stored it — and `/admin/` then 301s away,
  locking the editor out of the CMS with no way back through the UI.
* **Self-targets** (`loop-a → loop-a`) were stored, so the request redirected to
  itself forever, and **mutual loops** (`loop-b ↔ loop-c`) with it.
* **Renaming a page twice bricked it.** Renaming `about` → `about-us` and back
  left `about → about-us` *and* `about-us → about`: `/about/` was live but still
  redirected away, into a loop.
* **A duplicate old path silently replaced** the existing redirect.

**What shipped.**

* **One validator at the choke point.** `redirect_conflicts(from, to, ignoreId)`
  returns `{rule, detail}` rows — the shape the publish checklist already uses —
  for `empty`, `reserved`, `shadows`, `self`, `loop` and `existing`. The admin
  form renders one sentence per rule (`redirects_rule_*`), and `redirect_save()`
  refuses the breaking ones itself, so no caller — present or future — can write
  a redirect the front end cannot serve. Replacing the entry that already owns a
  path stays the storage layer's job; the add form reports it instead.
* **A live path always outranks a redirect.** `save_content()` now clears any
  redirect that owns the path being published, *before* recording a move's 301 —
  which turns the rename-back loop into a working page and also covers publishing
  a page on a redirected path. `redirect_forget_path()` is that one rule.
* **Search.** The list filters on the old and the new path (`?q=`, the same
  `.content-search` markup as the media and content lists), with its own
  "nothing matches" state and Clear.
* **Chains are visible, not forbidden:** a target that is itself a redirect is
  flagged in the row with its hop count (`2 hops`) and listed in the check.
  Every chain walk goes through one `redirect_target_map()`, so a page costs one
  query, not one per hop.
* **Check and repair.** "Check redirects" reports what cannot work — self,
  loop, shadows, reserved — and what merely costs a hop — chains — and removes
  the broken ones on confirmation (chains are kept: they work). That is how an
  install already carrying one of the rows above gets clean without SQLite.

**Decisions.** A redirect is a repair for a URL that no longer exists, so the
validator refuses anything that would take a working URL away, and a path that
already redirects is edited rather than silently replaced. Chains are allowed and
flagged instead of collapsed: a second hop is a wart, not a break, and rewriting
a target someone typed is a surprise. Redirects stay **out of the content
package** — they belong to a site's history, not its content. The 404 list keeps
driving new entries.

**Verify.** `tests/redirects.test.php` (13): reserved, shadows (page, post,
archive, portfolio item), self, three-step and shorter loops, a draft and a
trashed path still being free to redirect, `redirect_save` refusing all three
kinds itself while still replacing in place, the rename-away-and-back case
leaving the page reachable with nothing to repair, publishing clearing a
redirect, search on either path, and the audit reporting all five kinds while the
repair removes exactly the broken entries and keeps the chain.
`tests/http.test.php` proves a redirect answers 301 before routing and that one
refused for shadowing leaves `/about/` serving 200. Verified in the browser too:
the refusal messages name the rule and the page it would hide, the list flags a
chain at `2 hops`, search narrows to it, the check reports five findings,
"Remove 5 broken redirect(s)" goes through the confirm modal, and afterwards only
the chain and the legitimate entries remain. `php tests/run.php` → 412 passed.

**Reject if** it becomes a URL-management suite: no regex source matching, no
bulk import of redirect lists, no hit analytics beyond the existing counter.

## 18. SEO output polish (C, M)

**Shipped.** The output was already thorough — title, description, canonical,
robots, Open Graph, Twitter, JSON-LD behind `schema => true`, paging prev/next
and the favicon link, with eight per-item fields and four SEO settings — so this
filled gaps rather than rebuilding it. Probed against a fresh install, six were
real:

* **Nothing said the site could be installed.** No manifest, no `theme-color`,
  no `apple-touch-icon`, and the theme's only icon was a 75×75 `.ico`, which is
  not a usable app icon.
* **An article never said when or by whom.** `og:type` was already `article` for
  a post, but no `article:*` tag was emitted — although the page array already
  carried `published_at`, `updated_at`, `categories` and `tags`.
* **No `twitter:creator`**, only the site handle.
* **`meta.author` had no way in.** The blog layout rendered it and the JSON-LD
  emitted it as a Person, but no admin field set it, so an editor could not add
  or change an author.
* **Nothing previewed any of it**, so an editor could not see the title,
  description or image a crawler would actually get.
* **The static export dropped virtual documents.** It wrote `sitemap.xml` but
  not `robots.txt`, and a manifest served the same way would have gone with it.
  (`AGENTS.md` also still described `theme/assets/favicon.png`, deleted in
  4f38adc.)

**What shipped.**

* **A manifest at `/site.webmanifest`**, served virtually like `robots.txt` and
  built from Settings (name, short name, description, colours, `start_url`,
  `scope`, `display`, icons). The head gained `<link rel="manifest">`, `<link
  rel="apple-touch-icon">` and `<meta name="theme-color">`, the last with a dark
  scheme variant when a theme declares `meta.theme_color_dark`.
* **Icons are taken, not generated.** An uploaded logo or favicon contributes its
  media variants at their *recorded* pixel sizes, then the theme's `icons.app`
  files, biggest first: `seo_app_icons()` offers 192 and 512 and the apple icon is
  the one nearest 180. A manifest with no usable icon is still a valid manifest.
  The theme ships `img/icon-192.png` and `icon-512.png` in its usual placeholder
  style and declares them, plus `meta.theme_color` and `meta.background_color`;
  `theme_manifest_problems()` understands the new list.
* **Article metadata** for anything with `og:type=article`: `article:published_time`
  and `article:modified_time` as ISO 8601 UTC, then `article:author`,
  `article:section` (first category) and one `article:tag` per tag — all from the
  page array, so no extra query. `twitter:creator` follows it, per item with the
  site handle as the fallback.
* **Author and Twitter/X creator fields** in the SEO panel, so `meta.author` is
  editable and the blog layout, the JSON-LD Person and `article:author` read one
  value.
* **A live preview** in the SEO panel: a search snippet and a social card,
  rendered server-side from `seo_metadata()` so the fallbacks an editor cannot
  see in the form are the ones shown, then following the title, slug, SEO and
  social text, canonical and picked image as they change.
* **The export keeps its virtual documents**: `robots.txt`, `site.webmanifest`
  and `sitemap.xml` are written out, so the robots.txt an export now ships no
  longer points at a missing sitemap.
* Docs: the editor guide covers the preview and the two new fields, the theme
  guide covers `icons.app`, `meta.theme_color`, `meta.background_color`,
  `meta.theme_color_dark` and the virtual manifest.

**Decisions.** Icons come from what the site already has — **existing media
variants, then theme files** — rather than a generation step or a new upload.
`twitter:creator` and the author are **per-item fields** with the site handle as
the creator's fallback, which avoids a users-table migration and makes the
existing JSON-LD author editable. The preview shows **both the search snippet and
the social card, live**, from the resolved values. The static export writes
**both** the manifest and `robots.txt`. The manifest stays a virtual document
like `robots.txt`, because the settings behind it are the site owner's, and the
head stays assembled in `core/helpers/seo.php`. Deliberately out of scope:
keywords, per-page robots.txt, sitemap pinging, and any ranking tooling.

**Verify.** `tests/seo.test.php` (29 checks) covers the icon candidates and the
192/512/apple picks, the manifest built from Settings with the theme colour as
the fallback and valid JSON, the head tags, the Article set on a post and its
absence on a page, ISO 8601 UTC times, the creator fallback and per-item
override, and the new fields through `seo_collect_meta()`. `tests/http.test.php`
fetches the manifest and every icon it declares, checks the content type, the
links in a rendered page and a post, the article tags on the post only, and that
the virtual documents stay out of the sitemap. In the browser, Chrome's
`Page.getAppManifest` parsed the served manifest with **no errors**, both icons
loaded at their declared sizes, and the editor preview was driven with real
clicks and typing: the title and description follow the fields, a renamed page
title flows into the fallback, and picking and clearing a social image swaps the
card image and its empty state.

## 19. Accessibility pass (B/C, S–M)

**Shipped.** Every page now carries a skip link as the first focusable element
and exactly one `<h1>`: the nine components that hardcoded one render `<h2>`
(keeping their size through the classes they already carried), and the `default`
and `landing` layouts — the two that showed no document heading at all — emit the
page title as a visually hidden `<h1>`. The skip link is emitted once in
`render_page()`, so it reaches every layout including the preview bar, and all
eight theme layouts plus the admin mark their `<main>` with `#main-content`. One
shared dialog helper in `admin/assets/main.js` gives all six `.modal-backdrop`s
`role="dialog"`, `aria-modal`, a labelled heading, initial focus, a Tab trap,
Escape and focus restore — replacing four copies of the same bespoke open/close
code — and the image-grid thumbnails became keyboard-operable controls in the
same pass. The editor's four component-toolbar glyphs, the picker's close button
and search box, and the help affordance (now a real `<button>`) all have names,
in both languages. `icon()` output is `aria-hidden`; the admin toast container
and the public form's feedback are live regions; the sidebar and header
landmarks are labelled.

**Why.** Most of the admin is already usable by keyboard — the list icon buttons,
header controls, mobile drawer and account menu all carry names and an Escape
handler — so this is a short list of places where it is not. Four of them are
blockers rather than polish: the public submenus cannot be opened at all without
JavaScript, no page offers a way past the navigation, section components make
several `<h1>`s on one page, and the editor's own component controls are unnamed
punctuation marks.

**Findings (probed on a fresh install through the local server).**

* **No skip link anywhere.** Every theme layout and the admin layout render a
  `<main>` with no `id`, so there is nothing to jump to. The admin sidebar is
  ~20 links; a keyboard user tabs it on every page.
* **Three seeded pages render two `<h1>`s**: `/services/` and `/landing/`
  (`hero-section` + `cta-section`) and the seeded `/404` page. Eight section
  components hardcode an `<h1>` — `hero-section`, `cta-section`,
  `about-hero-section`, `contact-section`, `pricing-section`, `faq-section`,
  `blog-featured-section`, `portfolio-grid-section` — and
  `core/components/404.php` does too. The `default` and `landing` layouts render
  no page heading of their own, which is why the first section's `<h1>` has been
  standing in for one.
* **No dialog semantics or focus handling.** `admin/partials/confirm.php` is a
  `<div class="modal-backdrop">` with no `role="dialog"`, no `aria-modal` and no
  label; `admin/assets/main.js` shows it with `style.display = 'flex'` and never
  moves focus into it, never traps Tab, never closes it on Escape and never
  returns focus to the trigger. The image picker
  (`admin/partials/image-picker.php`) is the same. The other four backdrops
  (`utilities` ×2, `messages`, `media`) do focus the first control and close on
  Escape, but still have no dialog role and no trap.
* **The editor's glyph-only buttons are unnamed.** In
  `admin/partials/content-editor-templates.php` the component toolbar is
  `<button>&#8593;</button>`, `&#8595;`, `&#9868;`, `&#33;` — a screen reader
  announces "up arrow", "down arrow" and so on, with no idea what they act on.
  `admin/partials/image-picker.php`'s close control is a bare `&times;`, and
  `admin/partials/help.php`'s help affordance is a `<span>` that is not
  focusable at all.
* **Menus are pointer-only.** The public header dropdowns rely on `.show` being
  added by `theme/assets/main.js`; it handles click and Escape but no arrow keys.
  `theme/assets/utilities.css` gives `.dropdown-menu` `display: none` with no
  `:focus-within`/`:hover` fallback, so without JavaScript (or from the
  keyboard's point of view, before the toggle is clicked) the submenus are
  unreachable.
* **Every decorative icon is exposed.** `icon()` in `core/helpers/icons.php`
  inlines the SVG with no `aria-hidden`, so each one is an unlabelled graphic in
  the accessibility tree. Both live regions are silent too: the admin toast
  container (`admin/partials/toasts.php`) and the public form's `.message`
  (`theme/partials/form.php`) announce nothing when they fill.

**Work.**

1. **Skip links.** The theme link belongs in the one place every layout passes
   through — `render_page()` in `core/render.php`, immediately after `<body>` so
   it is the first focusable element, ahead of the preview bar. Point it at
   `#main-content` and put that id on the `<main>` of all eight theme layouts.
   The admin layout gets the same link before `.admin-layout` and the id on its
   `<main>`. Every new string — the skip link and the control names from step 4 —
   goes in **both** language files. Give the link a `.skip-link`/`:focus` rule
   that brings it on-screen top-left with a high `z-index`; the theme has no
   visually-hidden helper yet, so add one and use it for the page-title `<h1>`
   below.
2. **One `h1` per page.** Demote the nine components above from `<h1>` to `<h2>`,
   keeping the current rendering by carrying the size in the class where the tag
   was doing the work: add `h1` where the markup has only `fw-bolder`
   (`about-hero-section`, `contact-section`, `pricing-section`, `faq-section`,
   `portfolio-grid-section`), leave `display-5`/`fs-5` cases as they are, and
   change `cta-section`'s own `.cta h1` rule to match its new tag. The `default`
   and `landing` layouts then render the page's own title as a visually hidden
   `<h1>` — the layouts whose visible heading is a section, not the document
   title. The layouts that already render `<h1>` (blog, portfolio, policy,
   search, taxonomy, blog-archive) and the admin header are unchanged. Change
   `default.php` to always emit `<main id="main-content">`, so a component-less
   page still has both the landmark and the heading.
3. **Dialog semantics and focus.** One small helper in `admin/assets/main.js`:
   `role="dialog"`, `aria-modal="true"` and `aria-labelledby` (pointing at each
   dialog's existing `<h3>`; the confirm title is set at runtime, so a label id
   is the right shape), focus moves to the first control on open, Tab cycles
   inside the dialog, Escape closes it, and focus returns to the control that
   opened it. Apply it to `#confirm-modal` and the image picker, and replace the
   four bespoke open/close blocks with it, so one implementation covers every
   `.modal-backdrop` and the duplicated code goes away.
4. **Names on the glyph-only controls.** Name the four component toolbar buttons
   (move up, move down, duplicate, remove) with the same `title` +
   `.off-screen` pair the menu editor's icon buttons already use, so the tooltip
   and the accessible name come from one key. The image-picker close button gets
   `type="button"` and a label; its search box gets a label (`placeholder` is not
   one). The help affordance becomes a real `<button type="button">` with a label
   and `aria-expanded`/`aria-controls`.
5. **Menus by keyboard.** The public dropdowns in `theme/assets/main.js` gained
   ArrowUp/ArrowDown/Home/End over their items, and ArrowDown on a closed toggle
   opens it and focuses the first item; Enter already opened it, because the
   toggle is a link and the existing click handler runs. The `:focus-within`
   reveal the plan first described is **not** in: Escape returns focus to the
   toggle, so a focus-based rule would leave on screen the menu the keypress just
   closed. The no-JavaScript half — the mobile collapse and the submenu reveal —
   is untouched and stays the open decision below.
6. **Smaller, same-class fixes taken while here.** `icon()` gains
   `aria-hidden="true" focusable="false"` (every icon-only control has a name of
   its own by then, so nothing is silenced). The admin toast container and the
   public form's `.message` become `role="status"`. The admin sidebar `<nav>`
   and the public header `<nav>` get an `aria-label`.

**Decisions.** The page's own title owns the single `<h1>`, as a visually hidden
heading on the two layouts that do not render one, rather than letting the first
section's heading be the page heading — which component is "first" is not
knowable at render time and the section heading is the section's, not the
document's. The dialog helper is **shared across all six backdrops**, not special
cased for the confirm modal: they are one pattern and the four existing blocks
were copies of each other. The picker's thumbnails became keyboard-operable
(`tabindex`, `role="button"`, Enter/Space) rather than staying click-only, so
nothing in the pass is left pointer-only. The `:focus-within` submenu reveal was
dropped for the Escape conflict above, which also means the no-JS submenu case is
still unreached.

**Explicitly out.** The public form's no-JavaScript path is a separate defect
(`core/form-submit.php` always answers JSON), so it is not this phase's work; it
is raised in the open decisions rather than folded in. No colour-contrast audit,
no screen-reader sweep beyond the paths above, no ARIA on every widget, no
`prefers-reduced-motion` changes (already handled where it matters).

**Verify.** `tests/design.test.php` (18): a skip link in the theme render path and
the admin layout, `id="main-content"` in every theme layout, `role="dialog"` /
`aria-modal` / `aria-labelledby` on every backdrop, a name on each editor toolbar
button, `icon()` emitting `aria-hidden`, and the two live regions.
`tests/http.test.php`: 15 seeded URLs render **exactly one `<h1>`** with the skip
link before `<main id="main-content">`, plus the synthesised 404.
`php tests/run.php` → 429 passed. Through the local server: every seeded URL
counted one `<h1>`, the skip link rendered first in the body, the admin
dashboard showed the labelled skip link, sidebar and help button, the editor
rendered the named toolbar and the labelled picker dialog, and the fallback 404
(seeded page removed) still rendered one `<h1>`. Keyboard-only and no-JS
walkthroughs remain the manual check a browser owns; the headings were left with
the classes that fixed their size, so the demotion is not expected to move
anything on screen.

**Reject if** it becomes an ARIA retrofit or a WCAG audit. If the mobile-collapse
no-JS half turns into a navigation rebuild, ship the skip link, the single
heading, the dialogs, the names and the arrow keys, and record the rest.

## 20. Search hardening (A, M)

**Shipped.** Search runs `LIKE` or FTS5, chosen per request.
`search_fts5_available()` probes by creating a temporary FTS5 table once per
request, with `search_override_fts5()` as the test seam, and `search_content()`
dispatches to `search_content_like()` or `search_content_fts()`; both share the
visibility, type and taxonomy clauses and keep the `published_at DESC, id DESC`
order. The derived `content_fts` table (`fts5(search_text)`, `rowid =
content.id`) is created and filled by the read path, updated by
`search_index_content()`, rebuilt by `search_reindex_all()` and cleared by
`purge_content()`; an FTS failure is logged and the request falls back to `LIKE`.
Taxonomy names match at query time through an `EXISTS`, `meta.author` is in
`search_text`, and `like_escape()` plus `ESCAPE '\'` closed the wildcard hole in
all eight text searches.

Two things the first cut got wrong, both caught by the tests.
`search_index_content()` must not probe or build the index: it runs while the
save's write statements are live, and SQLite refuses the schema change with
"database table is locked" — so creation moved to the read path and to
`search_fts_refresh()` after a reindex, and the write path only updates an index
that already exists. And `content_fts MATCH …` cannot sit inside the
`OR EXISTS(taxonomy)` expression (FTS5: "unable to use function MATCH in the
requested context"), so the FTS matcher is a subquery:
`c.id IN (SELECT rowid FROM content_fts WHERE content_fts MATCH :fts)`.

An existing install picks up author indexing on its next reindex (Utilities →
Rebuild search index); the derived table appears on the first search. On a host
without FTS5 nothing changes: `LIKE` answers, and no FTS object is created or
touched. The residue of "either backend" is asserted and documented rather than
hidden — FTS5 folds non-ASCII case (`ångström` finds `Ångström`) and matches
tokens and prefixes, where `LIKE` matches any substring but folds ASCII only.

**Why.** The admin half of this item is already done — every list has its own
search — so the phase is the front end, where four things are wrong: a query can
be silently broadened by a wildcard, an author cannot be found, a category or tag
name works only as a filter, and the matcher assumes one SQLite build. The last
is now a requirement: **search must work on a host with FTS5 and on one without
it**, so FTS5 becomes an optional accelerator behind a runtime probe and `LIKE`
stays the guaranteed baseline.

**Findings (probed on a fresh install).**

* **Admin search is already shipped, and the cross-type search is dropped.**
  Users (`admin/user/index.php`, over username, first and last name and email),
  form submissions (`form_submission_filter()`, `q` over the JSON `data`), and
  the content, media, redirects, category, tag and activity lists each carry
  their own `?q=` box. A cross-type admin search answers no question the sidebar
  and the per-list search do not, so it is **out**.
* **`%` and `_` are wildcards wherever a search binds `LIKE`.** Each binds
  `'%' . $query . '%'` into `LIKE` with no `ESCAPE`, so the user's characters are
  treated as patterns. On the seeded site `search_content('%%')` returns 16
  results and `search_content('__')` 16, where the correct answer is 0. That is
  the front end plus six admin searches that reach SQL — media, form
  submissions, redirects, activity, categories and tags — and the unused `q`
  branch of `content_list_rows()`/`list_content_page()`. The admin **user**
  search (`stripos`) and the admin **content list** (`mb_strtolower`/
  `str_contains` in PHP) never bind `LIKE` and were never affected.
* **`meta.author` is not indexed.** `search_build_text()` takes the title,
  `meta.description`, `meta.excerpt` and the body; the author field phase 18 made
  editable is absent. The demo's posts carry "Valerie Luna", "Kelly Rowan" and
  "Josiah Barclay", and all three queries return 0.
* **Taxonomy names are filters, not search terms.** `search_content()` joins the
  taxonomy tables only for `?category=`/`?tag=`. The demo's "Freebies" and
  "Web Design" return 0; "News" returns 2 only because the word is in the prose.
* **`LIKE` folds ASCII case only.** SQLite's built-in `LIKE` is
  case-insensitive for ASCII alone — `'Ångström' LIKE '%ångström%'` is false
  here, `'Hello' LIKE '%hello%'` is true — which is both the concrete gap behind
  FTS5 and the reason it is worth having when a host offers it.

**The fallback contract.** FTS5 is optional; `LIKE` is the guarantee.

* **Probe, never assume.** `search_fts5_available()` is memoised for the request
  and answers by trying `CREATE VIRTUAL TABLE temp.__fts5_probe USING fts5(x)`
  in a `try`/`catch` — not by version, and not by `PRAGMA compile_options`, which
  a build may omit. A test-only override forces either answer, so both paths run
  on one machine. (PHP cannot enable FTS5 from php.ini, Apache or the
  application; it is fixed when the linked SQLite is built.)
* **The index is derived, never schema of record.** `setup.php` and
  `migrate_registry()` stay identical on every host; the `fts5` table is created
  lazily once the probe says yes, and **without triggers**. A trigger on
  `content` would reference a missing module on a non-FTS5 host and break every
  write; a standalone index left behind is inert — verified: SQLite opens the
  database and reads and writes `content` normally, and only a direct touch of
  the virtual table fails, which the probe already prevents. `content.id` is
  `INTEGER PRIMARY KEY AUTOINCREMENT`, so a stale row can never be reattached to
  a new item.
* **Same results, same order, either way.** Both backends match the same query,
  order by `published_at DESC, id DESC`, and build the excerpt and highlight from
  `search_text`, so a page does not change shape with the host. `bm25` ranking
  stays out for now: it would make ordering host-dependent, which is the thing
  this requirement avoids.
* **A broken index never 500s.** An FTS prepare or execute failure is logged and
  the request falls back to `LIKE`.

**Work.** Steps 1–3 are the `LIKE`-only hardening and ship on their own; steps
4–6 add the optional backend on top.

1. **Escape the wildcards.** One `like_escape()` helper in
   `core/helpers/common.php` (loaded unconditionally, where the users are spread
   across front and admin helpers) escapes `\`, `%` and `_`, and every user-text
   `LIKE` gains `ESCAPE '\'`. Deliberate patterns stay: the media extension match
   and the activity action prefix are built by the code, not typed by a user.
2. **Index the author.** `search_build_text()` adds `meta.author`. Nothing else
   is needed — the save path already reindexes, so it cannot go stale.
3. **Match taxonomy names at query time.** Add an `EXISTS` over
   `taxonomy_term_relationships`/`taxonomy`, OR-ed with the backend's match, so a
   term's name finds its content with no filter. Index-time would need a reindex
   hook in every relationship write — `admin/content/save.php` (which writes the
   links *after* `save_content()` has already reindexed), `duplicate.php` (same
   order), `bulk.php`'s add/remove tag, `taxonomy_delete()` and the category/tag
   rename — and one missed hook is a silently stale index. The `EXISTS` is always
   current and works under either backend. Trade-off: a hit that matched only a
   term name has nothing to highlight in its excerpt.
4. **The backend seam.** `search_fts5_available()` plus a test override,
   `search_content()` dispatching to `search_content_like()` /
   `search_content_fts()`, and the shared clauses (visibility, type, taxonomy)
   assembled once so the two cannot drift.
5. **The FTS index.** `content_fts USING fts5(search_text)` with
   `rowid = content.id`. Created with `IF NOT EXISTS` on first use; synced in PHP
   by `search_index_content()` (delete by rowid, then insert) and rebuilt by
   `search_reindex_all()` (clear, then repopulate); cleared for a purged item by a
   small `search_index_remove()`. The MATCH expression is built from the user's
   query by quoting it as an FTS5 string with internal `"` doubled and a prefix
   marker on the final token, so punctuation cannot become FTS syntax; tests pin
   the exact expression and the queries it must match.
6. **Both paths under test.** Force each backend through the override and run the
   same contract over both: `%`, `_` and `\` match literally, an author name
   finds the post, a category name and a tag name find their item, unpublished
   content never leaks, filters and pagination hold, and the index stays fresh
   after a save and a rebuild. Plus: a failed probe still answers through `LIKE`,
   and a database carrying `content_fts` works when the probe is forced false.

**Decisions.** `LIKE` is the baseline and FTS5 an accelerator, chosen by a
runtime probe; the search API and the page are identical either way. The index is
standalone and trigger-free, so a database stays portable in both directions and
no migration depends on the host. Author is prose, so it belongs in
`search_text`; taxonomy links are relational, so they belong in the query. Both
backends share the ordering and the excerpt, so "which backend" is invisible
except for speed and non-ASCII case folding. `bm25` stays out until ranking is
deliberately wanted.

**Reject if** it becomes a second search system: no triggers, no host-dependent
schema, no FTS5-only behaviour, no stemming, synonyms or ranking, and no
cross-type admin search.

**Verify.** `tests/search.test.php` (27): `like_escape()` and `search_fts_query()`
directly, the probe seam, and a `search_each_backend()` matrix holding both
backends to one contract — literal `%`, `_` and `\`, an author name, a category
and a tag name, and the FTS index lifecycle (a save, a rebuild, a purge); plus a
run with `content_fts` present and the probe forced false.
`tests/forms.test.php`, `tests/redirects.test.php` and `tests/content.test.php`
cover the escaped submission, redirect and content-list searches.
`php tests/run.php` → 440 passed. Through the local server: `/search?q=` returns
the author and tag hits, `%%` and `__` return nothing, dropping `content_fts` and
searching again rebuilds it (16 rows), and no FTS fallback was logged.

## 21. Version diff and compare (A, M)

**Shipped.** History is a compare view driven by `?from=<id>&to=<id|current>`: a
two-select form (Current, then versions newest-first) and a Compare link on every
row, with the pair named in the header, the changed-field badges above the diff
and a unified line diff below. `version_text_diff()` — LCS over one flat table,
capped at 250 000 cells with a whole-block fallback — and
`content_version_lines()` — scalars, then key-sorted meta, then indented
components as `body[0]: hero-section` — live in `core/helpers/versions.php`.
Every line is printed with `e()`, because a snapshot carries editor HTML. Both
sides are validated against the item, so another item's id opens nothing, and
restore stays a POST offered only when the From side is a real version (an
autosave still offers the editor instead). The old metadata and component-name
lists went with it: the diff shows strictly more. `.diff-add`, `.diff-del`,
`.diff-same`, the compare form and the marker are styled in the admin stylesheet
for both themes, the new strings are in both language files, and the in-app docs
say versions can be compared.

**Why.** History can say *that* a version differs and *which* fields differ, but
never *how*: the single-version view shows a snapshot's title, its metadata
key/values and its component type names, and the list's Change column is a set of
badges. Choosing what to restore is blind, and every comparison is
snapshot-versus-live — two snapshots cannot be put side by side at all.

**What is already there.** `content_version_changes()` names the changed fields
(shared by the list and the single-version view), `restore_content_version()` and
its undo, autosave handling and retention. There is no diff code in the project —
a `grep -i diff` finds only prose — so this is new work with no library to reach
for, by design.

**Work.**

1. **One line diff, in `versions.php`.** `version_text_diff(array $before, array
   $after): array` returns an ordered list of `['op' => 'same'|'add'|'del',
   'text' => string]` from a longest-common-subsequence pass: build the LCS
   table, walk it back, and emit equal runs as `same` with the gaps as `del` and
   `add`. The cost is bounded by a cap (`count($before) * count($after)` over
   ~250 000); past it, fall back to removing everything and adding everything,
   because a pathological body must not exhaust memory. It lives in
   `core/helpers/versions.php`, which is already loaded and is its only consumer
   — no new helper file and no bootstrap change.
2. **Flatten a payload into labelled lines.** `content_version_lines(array
   $payload): array` turns the versioned payload into the lines the diff runs
   over: one per scalar (`title: …`, `status: draft`, `layout: …`, the two
   dates), one per meta key (`meta › description: …`), and a labelled indented
   block per component (`body[0] hero-section`, `body[0] › title: …`,
   `body[0] › children[1] cta-section`). Maps are key-sorted so an unchanged
   value never moves line, and an empty meta or body contributes nothing.
3. **A compare view.** Replace the single `?version=` panel with `?from=<id>` and
   `?to=<id|current>`: the heading names both sides ("Version 3 → Version 5",
   "Version 3 → Current"), the field badges for that pair sit above it (reusing
   `content_version_changes()`), and below is the unified line diff —
   `.diff-add`, `.diff-del`, `.diff-same`. Every line is printed with `e()`: the
   lines carry editor content, rich-text markup included, so nothing in a
   snapshot may render as HTML. Each id is validated against this item, exactly
   as the restore path already does; an id that is not this item's is ignored
   rather than fatal. Restore stays a separate POST and is offered only when the
   "from" side is a real version. The diff supersedes the old metadata and
   component-name lists, which showed less.
4. **The compare form.** The history page gains a two-select GET form — From and
   To, listing each version by number, date and reason, with "Current" an option
   on either side and the default To. The row's View action becomes Compare and
   links to `from=<id>&to=current`; the Change column keeps its badges unchanged.
5. **Presentation and words.** `.diff-*` rules in `admin/assets/style.css` (the
   design test requires every admin class to be defined), the new `versions_*`
   strings in **both** language files, and the History paragraph in the in-app
   docs extended to say versions can be compared, not only restored.

**Decisions.** The diff covers the **whole payload**, not only the body: the
flattener renders scalars, meta and body in one pass, so "which field" and "what
changed" come from one mechanism and the badges stay a summary of it. One
**unified** diff rather than a side-by-side table — it fits the admin column and
needs no JavaScript. A comparison is always `from` → `to` with "current" as a
pseudo-version, so the live row and a snapshot travel the same code path. Line
granularity is one flattened value, which keeps a rich-text field as a single
removed/added line; splitting HTML into block lines is deliberately out, because
it would hide markup-only changes such as a new link. Unchanged lines are shown
muted rather than collapsed: a flattened payload is tens of lines, not hundreds.

**Reject if** it becomes a merge tool or adds a dependency: no diff library, no
three-way merge, no per-character diff, no "apply this hunk", and no writing
content back from the compare view.

**Verify.** `tests/versions.test.php` (21): `version_text_diff()` on identical,
inserted, deleted and replaced lines, on empty sides, and past the cap
(whole-block fallback); `content_version_lines()` for scalars, meta and a nested
body, including that key order does not move a line and that an empty payload
contributes nothing. `tests/http.test.php` (96): an authenticated GET of the
history page with `from`/`to` renders the added and removed component lines and a
muted unchanged one, a `from` id belonging to another item opens no diff and
leaks none of its content, and the compare form lists the stored versions.
`php tests/run.php` → 446 passed. Through the local server with two seeded
versions: the page rendered "Version 1 → Version 2", the `Content` badge, the
removed old hero, the added new hero and CTA lines with the unchanged ones muted,
the From/To selects defaulting to the newest version and Current, and the row
Compare link.

## 22. Publish webhook (C, S) — dropped

**Dropped.** No webhooks: nothing should call out on publish, and no other item
depends on it. The static export stays a command the operator runs. Recorded in
Considered and not planned.

## 23. RSS/Atom feed (C, S) — dropped

**Dropped.** A feed is a blog-era distribution channel with no audience for the
brochure sites this CMS serves; the sitemap already covers crawler discovery.
Recorded in Considered and not planned.

## 24. Site scans: orphaned media and broken links (C, M)

**Shipped.** Utilities gained two read-only cards in its **Content and data**
group, and each report opens in a dialog rather than at the foot of the page:
the request that runs the scan renders the dialog open (the same shape as the
import preview), so nothing has to be fetched and closing it leaves the page
where it was. `?scan=media` runs `media_orphans()`, which walks the two-level
tree under
`storage/media` and compares it with `media.base_path` in both directions, with
each folder's measured size; its repair (a POST behind the confirm modal)
recomputes the scan, deletes only a folder with no row and no path reference
anywhere in content, settings or menus, and removes library rows whose folder is
gone. `?scan=links` runs `content_broken_links()`, which reads published,
non-trashed content for link-shaped component props, link-shaped meta keys and
`<a href>` inside rich text, and resolves each through `content_link_resolves()`
— a mirror of `route_request()` covering the homepage, the virtual documents,
taxonomy archives, redirected paths (served before routing), media files and
content by full path. Findings name the item, the field and the target, and link
to the editor. A new language key group, an activity label (`utility.media_clean`)
and the docs text landed with them.

**Two bugs found on the way, both fixed.** `media_referenced_paths()`
walked the raw `content` JSON columns, where every slash is escaped (`2026\/09\/…`),
so the path regex matched nothing and the repair would have deleted a referenced
folder; it now decodes the JSON before walking, as `media_usage_map()` always
did. And `content_link_resolves()` briefly cached the redirect set in a function
`static`, which goes stale the moment a redirect is added in the same process —
and resolved `/` from the `homepage_id` setting alone, so a draft homepage looked
reachable. Both are gone: the redirect set is read per call, and `/` resolves
through the homepage's own path.

**Why.** Two kinds of rot are invisible until a visitor hits them. The media
library can hold folders with no database row — an interrupted upload, a
restored backup, a file copied in by hand — and rows whose folder is gone, which
phase 12 already recorded as unremovable through the library (`media_delete()`
reports `invalid_path`). And content can link to a path that no longer exists:
a page was renamed and the link was never updated, or it was trashed. Neither
shows up anywhere today. The pre-publish checklist only checks that a link field
is *empty*, never that a non-empty target resolves, and it never looks inside
rich text.

**Findings (probed on a fresh install).**

* **Media has no disk-versus-table check.** `media_usage_map()` answers "where is
  this file used?" and `media_delete()` refuses a row whose folder is missing,
  but nothing compares the folders under `storage/media` with the `media` table.
  Files live at `storage/media/YYYY/MM/<12-hex>/…` and the row's `base_path` is
  exactly that `YYYY/MM/<12-hex>`, so the scan is a two-level walk plus one
  query.
* **The only link check is emptiness.** `content_checklist_is_link_field()`
  recognises a link field (`url`, `href`, a `*_url` name, or schema type `url`)
  and the checklist flags a blank target that still has a label. Nothing resolves
  a target that is present, and rich text (`quill` props, stored as raw HTML) is
  not examined at all.
* **Resolution has one implementation to mirror.** `route_request()` serves the
  homepage, `search`, `sitemap.xml`, `robots.txt`, `site.webmanifest`,
  `category/<slug>` and `tag/<slug>` archives, then content by full path through
  `load_content_by_slug()`. A redirect is served *before* routing, so a
  redirected path resolves as well, and `/media/…` is a file on disk.

**Work.**

1. **`media_orphans()` in `core/helpers/common.php`**, beside the media helpers:
   walk the two-level tree under `storage/media`, compare it with the
   `base_path` values in the table, and return both directions — `files` (on disk
   with no row) and `rows` (a row whose folder is gone) — each with its size, so
   the report can say how much space is involved.
2. **`content_broken_links()` in `core/helpers/content.php`**: walk published,
   non-trashed content, collect every link value (a component prop
   `content_checklist_is_link_field()` accepts, and every `<a href>` inside a
   `quill` prop), keep the ones that point at this site, and resolve each through
   `content_link_resolves()`. That resolver mirrors `route_request()`: the
   virtual paths, `load_content_by_slug()` for content, a `taxonomy` lookup for an
   archive, `redirect_all()` for a path that 301s, and the file for `/media/…`.
   A link is internal when it is root-relative or its host matches the configured
   `site_url`; `#`, `mailto:`, `tel:` and other hosts are skipped, and query
   string and fragment are stripped before resolving. Each finding names the
   content item, the field and the target, and links to the editor for it.
3. **Two Utilities cards.** A new group beside Maintenance, Content, System and
   Package, each card a read-only link to `?scan=media` or `?scan=links` — the
   same shape as Check redirects on the redirects page — that renders the report
   in place: the counts first, then the rows. A scan writes nothing.
4. **One repair, and only where it is safe.** Orphaned media gets a "Delete N
   orphaned folders" action (POST, behind the confirm modal) that removes only a
   folder with no row **and** no path reference anywhere in content, settings or
   menus — a file whose row was removed can still be linked by URL in rich text.
   That second check cannot come from `media_usage_map()`: it keys its result by
   media id, so a path with no row is invisible to it. The reference scan has to
   key by path instead — the same one-pass walk over content, settings and menus
   returning the set of `YYYY/MM/…` strings the site mentions — and only a folder
   absent from both the table and that set is deleted. Rows whose folder is
   missing are deleted from the table, which is what makes them removable at all.
   Broken links get no action: the fix is to edit the content, and the report
   links there.
5. **Words, docs and the phase-12 note.** New `utilities_scan_*` strings in both
   language files; the in-app docs' media and maintenance text mentions the
   scans; and phase 12's "known, deliberately left" paragraph now points at the
   shipped scan instead of promising it.

**Decisions.** A scan is **read-only**; only orphaned media has a repair, and it
refuses anything still referenced by path. Only **published, non-trashed**
content is link-scanned — a draft's links are the pre-publish checklist's
problem, and including them would bury the live ones. `#` is not reported: the
checklist already calls it a dead link, and the demo uses it as a deliberate
placeholder. The scans live in **Utilities, not Health**: they read every content
body and walk the media tree, which is right on request and wrong on every page
load. No external URL is ever fetched.

**Reject if** it becomes a crawler or a link monitor: no HTTP requests, no
scheduled runs, no external-link checking, no automatic link rewriting, and no
deleting a media file that is still referenced.

**Verify.** `tests/media.test.php` (16): `media_orphans()` finds a folder with no
row and a row with no folder in a fixture tree, measures the folder, and reports
neither when the two agree; a referenced orphan survives the repair and an
unreferenced one does not; `media_delete()` still refuses the missing-folder row
while `media_delete_missing_rows()` removes it. `tests/content.test.php` (25):
`content_link_resolves()` for the homepage, a page, an archive, a virtual route,
a redirected path and a media file, and its refusal for a missing path, another
host, a mail link, a placeholder, a bare relative path and a missing media file;
`content_broken_links()` reports the dead component prop and the dead rich-text
link and nothing else. `tests/http.test.php` (97): an authenticated GET of
`?scan=media` lists the orphan folder and offers the repair, `?scan=links` lists
the dead link with its content, and the repair POST removes the folder.
`php tests/run.php` → 451 passed. Through the local server with a seeded orphan
folder, a ghost row and a dead link: the media report read "2 file(s) with no
library row, 1 library row(s) with no file (4.1 KB on disk)", the link report
named the page and `/server-gone/`, the repair redirected to `?scan=media` with
"Every file on disk has a library row, and every row has its folder.", and the
activity log recorded `utility.media_clean | 2 folder(s), 1 row(s)`.

---

# Wave 3 — opportunistic

Drop any of these without affecting the rest of the plan. Each needs its own
"does this solve a problem that exists today?" answer before starting.

* **25. Rich-text editor completion** — blockquote/table/HR buttons, a
  media-library insert for rich text (images currently only by paste/drop),
  new-tab links and an internal page picker.
* **26. Live style switch in preview** — the first item to drop if the browser
  inspector is enough.
* **27. SMTP delivery** — replace bare `mail()` with a small dependency-free SMTP
  client or document the host relay; needed before password reset is reliable on
  strict hosts.
* **28. Reusable/global content** — a blocks or globals table for a shared CTA,
  banner, testimonials or FAQs. A real content-model addition; only if clients
  ask.
* **29. Content import/export** — JSON/CSV content export and import. There is
  deliberately no importer today; revisit only if the delivery workflow changes.
* **Editor extras from the old README "Maybe" list** — live preview inside the
  content editor (an iframe over the existing preview URL), a richer demo site
  and admin theme, a theme developer guide published outside the app, and more
  admin languages.

---

# Track D — multi-language front end (deferred)

**Status:** design agreed, code deferred. Do not start until Wave 1 is complete
and a real client needs a second locale. Each phase ships alone.

**Decisions.**
* The default locale keeps its current URLs; only non-default locales get a
  prefix (`/sv/about/`), so no existing URL changes and no redirect layer is
  needed.
* `content` stays the default-locale record and owns status, layout,
  header/footer, parent nesting, taxonomy and authorship. A new
  `content_translations` table holds the translatable fields.
* Slugs are localized. Untranslated pages fall back to the default locale and
  are served `noindex, follow` with a canonical to the default URL.
* Menus stay shared in v1; per-locale menu labels and term names come last.

**Data model.** `content_translations(id, content_id, locale, title, slug,
meta JSON, body JSON, created_at, updated_at, UNIQUE(content_id, locale))`, added
to both `setup.php` and `migrate_registry()`. A `locales` setting holds the
ordered enabled codes; the first is the default and must agree with
`site_language`.

**Phases.**
1. Foundation: table + migration, `locales` setting, `current_locale()`, router
   prefix stripping, `<html lang>`. Default-locale HTML must stay unchanged.
2. Locale-aware loading and URLs (`content_url()`, `content_page_url()`), theme
   link updates, translation cache invalidation, the `noindex` fallback.
3. SEO: `hreflang` alternates, canonical, `og:locale`, sitemap alternates.
4. Admin editing: locale tabs, settings UI, save path, completeness count.
5. Theme UI strings: `theme/lang/<locale>.php` plus a front-end `t()` helper.
6. Menus and taxonomy per locale.

**Not yet scoped:** localized media metadata, locale date formatting and locale
number formatting. Decide at phase-start whether they belong in phase 3 or a
phase 7.

---

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
  down is a deliberate status change, not a timer (see phase 10).
* **2FA, per-resource ACLs, GDPR/consent suite** — effort out of proportion to
  the threat model of a small brochure site; the existing roles and audit log are
  the bar.
* **S3/CDN/file-storage adapters, offsite/scheduled backups, local backup
  retention** — hosting choices, not CMS features, and a backup is a download you
  keep rather than a pile on the server (phase 24 dropped its retention half).
* **Publish webhooks and outbound integrations** — nothing should call out when
  content is published; the static export stays a command the operator runs
  (phase 22 dropped).
* **RSS/Atom feeds** — a blog-era distribution channel with no audience for a
  brochure site, and the sitemap already covers crawler discovery (phase 23
  dropped).
* **Marketing/CRM integrations, maps, oEmbed, mega menus, custom taxonomies** —
  raw header/footer scripts are the integration point; the rest waits for a
  concrete requirement.
* **Download-and-restore backup button** — deliberately absent; a wrong database
  bricks the site. Restore stays a documented manual step.

---

# Open decisions

Settle each at the start of its phase, not now.

* **Phase 5:** which checklist rules block publishing and which only warn?
* **Phase 6:** how far the CSP goes given vendored Quill and raw snippet
  settings.
* **Phase 9:** custom CSS — a raw setting, or explicitly out (theme owns design)?
* **Phase 19:** the pass shipped, so what remains is the no-JS public nav — the
  mobile collapse and the submenu reveal are still script-only (arrow keys and
  Enter-to-open cover the keyboard with script) — and the public form's no-JS
  path, since `form-submit.php` answers JSON to any POST. One small item each, or
  one "public front end without JavaScript" item?
* **Phase 20:** settled as dual-backend. FTS5 is used when a runtime probe finds
  it and `LIKE` answers otherwise; no schema and no migration assumes either
  build.
* **Phase 27:** hand-rolled SMTP client or documented host relay?
* **Track D:** when to schedule, and whether per-locale menu labels are needed in v1.

# Notes

* Query-driven views and the page cache: the cache key is the path, so every
  cache decision point must know about query parameters (`index.php` firebreak,
  `checkCache()`, the write path). Pagination needed two guards, not one.
* Two kinds of image, two owners: **images** are theme files under
  `theme/assets/img/` (developer-owned, `img()`), while **media** are editor
  uploads in the `media` table (editor-owned, addressed by id). They do not share
  a filename space, so a media id handed to `img()` 404s; `render_image()` and
  `resolve_image_value()` accept either and pick the right pipeline.
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

# Things that might be changed later

 * There is no need for the theme to have placeholders in assets/img. Generic fallback or placeholder images can be provided by the CMS, or a css skeleton can be used instead when media is missing.
 * Theme components should probably come with some sort of preview image, that way the CMS user will know what they look like when they add them in the content editor.
 * The content editor should have a button to add a new component, which brings up the component list in a modal, preferrably with preview and info. It can go beneath the current components, as a ghost/outline area.