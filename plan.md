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

| # | Track | Phase | Size | Depends on |
| --- | --- | --- | --- | --- |
| 1 | A/C | Small fixes and tidy-ups | S | — |
| 2 | B/C | Media fallbacks without theme placeholder files | M | — |
| 3 | A/B | Component previews and the Add component dialog | M | — |

Each phase is independent and can be dropped without affecting the others.

---

## 1. Small fixes and tidy-ups (A/C, S)

**Why.** Two leftovers from the shipped phases, each too small for its own
phase.

**Work.**
* `theme/layouts/blog-archive.php` renders its heading as `Blog! <term>` — a
  stray literal. Make it a proper heading, and update the `tests/http.test.php`
  assertion that uses that string to tell the blog archive apart from the generic
  taxonomy layout.
* The auth pages (`admin/auth/login.php`, `forgot-password.php`,
  `reset-password.php`) did not get the skip link and `#main-content` the rest of
  the admin gained in old phase 19. Add them.

**Verify.** `php tests/run.php`; the login page's first Tab stop is the skip
link.

**Reject if** it grows into a subsystem — these are two small edits.

## 2. Media fallbacks without theme placeholder files (B/C, M)

**Why.** `theme/assets/img/` ships eight dummy placeholder PNGs (`40x40.png`
through `1300x700.png`, plus `placeholder.png`) that component schemas and the
demo use as defaults, and `img()` only builds a URL — so a missing theme file is
a 404, and a cleared image prop falls back to a placeholder filename (old phase
11). A theme should not have to carry stand-ins; the CMS can own the fallback.

**Work.**
1. Give `resolve_image_value()` / `render_image()` a **missing** state: a theme
   filename that does not exist under `theme/assets/` resolves to the CMS
   fallback rather than to a URL that 404s.
2. Provide that fallback the CMS's way — a single shipped placeholder image, a
   CSS skeleton with the right aspect ratio, or both (see Open decisions). A
   skeleton costs no request and no file; a shipped image still looks like a
   picture.
3. Remove the placeholder PNGs, and repoint every component-schema `default` and
   the demo content that named them, so a new image field and the demo render the
   fallback.
4. `theme_manifest_problems()` must not report the removed files, and the theme
   developer guide documents the fallback.

**Verify.** `tests/media.test.php` (a missing theme file falls back; a media id
still wins), `tests/theme.test.php` (the demo renders, and no shipped component
default names a file that does not exist), a manual check of an empty image
field.

**Reject if** it turns into an image-generation feature or changes how a media id
resolves.

## 3. Component previews and the Add component dialog (A/B, M)

**Why.** The editor's component palette is a column of draggable labels, so an
editor chooses a component from its name alone and adds one by dragging — awkward
on a phone and impossible by keyboard. Previews and an Add dialog are two halves
of the same gap.

**Work.**
1. Extend the component contract with optional `description` (one line) and
   `preview` (an image). `content_component_definition()` passes them through,
   and `theme_manifest_problems()` reports a missing preview file as a **warn**,
   so a theme without previews still passes.
2. Add an outline "Add component" area beneath the current components in the
   content editor. It opens a dialog listing the components this content type
   offers, with label, description, preview and an Add button; the chosen
   component is appended and the dialog closes. The dialog reuses the shared
   helper from old phase 19 (focus, Tab, Escape, focus restore).
3. Decide whether the existing drag palette stays (for reordering and desktop
   drag-add) or the dialog replaces it.
4. Ship previews for the default theme's components if they can be authored
   without a generation step; otherwise the dialog shows a neutral tile.
5. Strings in both languages, admin CSS, and the theme guide's component
   contract.

**Verify.** `tests/content.test.php` (the definition carries the new keys),
`tests/design.test.php` (the dialog markup and wiring),
`tests/http.test.php` (the editor renders the Add area and the dialog), a manual
keyboard add.

**Reject if** it needs a build step, a screenshot service, or a second component
registry.

---

# Backlog

Confirm each before starting; none is scheduled.

* **Content Security Policy** — deferred from old phase 6. Report-only first, then
  nonces for the admin's inline scripts and the deliberate raw header/footer
  snippets. The whole reason it was deferred is that a useful policy needs one of
  those two.
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

Settle each at the start of its phase, not now.

* **Phase 2:** a CMS placeholder image, a CSS skeleton, or both? Deleting the
  placeholders also changes how the shipped demo looks, so decide whether demo
  parity still matters.
* **Phase 3:** where do previews live — `theme/components/previews/<name>.png`, a
  path declared in the component, or another convention? And does the drag
  palette stay beside the dialog?
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
