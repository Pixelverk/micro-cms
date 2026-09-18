# Continuing the Micro CMS — three working tracks

**Status:** plan for review. Nothing here is implemented.
**Written:** after inspecting the repo at commit `80c96aa` ("slight admin redesign").

This plan covers the three ways the project is actually used:

* **A — the CMS itself** (core PHP, admin, data model).
* **B — building themes** (the developer side of the CMS).
* **C — running a site** (the editor side: managing a website with it).

Association with this file's siblings:

| File | Role |
| --- | --- |
| `plan.md` | The item-by-item backlog. Stays as the backlog; this plan does not replace it. |
| `multilanguage-plan.md` | Agreed design for the deferred multi-language front end. |
| `continuing-plan.md` | This file: the phased programme across the three tracks. |

---

## Where the project stands

Healthy and unusually coherent for its size.

* `php tests/run.php` → **229 passed, 0 failed** (verified today, PHP 8.5.10,
  `pdo_sqlite` + `imagick` present, `zip` absent so exports use the Phar fallback).
* The local server renders: `/` → 200, `/robots.txt` → 200, `/sitemap.xml` → 200,
  `/admin/` → 302 to login. Demo content is seeded (10 pages, 3 posts, 2 portfolio items).
* Single active theme hardcoded to `theme/` (`theme()` in `core/helpers/common.php`
  builds `CMS_PATH . '/theme'`; nothing else resolves a theme path).
* 24 theme components, 8 layouts, one component contract documented in
  `core/components/sample-component.php` and the in-app developer guide
  (`admin/partials/docs-content.php`).

The real gaps are not "missing WordPress features". They are places where the
three tracks above are thinner than the rest of the code:

1. **Theme integrity is unverified until a page blows up.** `render_layout()`
   throws `RuntimeException` for a missing layout; `component()` merely warns and
   prints "component not found" into the middle of a live page. Nothing checks a
   manifest up front.
2. **There is no minimal theme to start from.** `core/components/sample-component.php`
   documents *a component*; nothing documents or ships a minimal *theme*.
3. **Theme asset `?v=` counters are manual** (`theme.php` has `style.css?v=5`,
   `main.js?v=4`). Admin assets are already stamped automatically by `admin_asset()`.
4. **Archives render everything.** Blog, portfolio and taxonomy archives have no
   pagination (`plan.md` item 8) — fine for demo data, not for a real site.
5. **Content cannot be duplicated.** Components can be cloned in the editor; a whole
   page or post cannot. Reusing a structure is a normal editor task.
6. **No maintenance mode** (`plan.md` item 11), which a real launch wants.

## Non-goals

Same boundaries as `plan.md`: no comments, no membership, no visual builder, no
REST/headless, no commerce, no dependency, no build step, no CMS classes. Nothing
here introduces a new concept unless a phase below says why.

---

## Suggested order

Each phase ships alone and ends with the full suite green. Tracks interleave
deliberately: the first item in each track is the smallest one, so all three get
value early.

| # | Track | Phase | Size | Depends on |
| --- | --- | --- | --- | --- |
| 1 | B | Theme integrity check + Health section | S–M | — |
| 2 | C | Archive pagination | M | — |
| 3 | C | Duplicate content | S | — |
| 4 | C | Maintenance mode | S–M | — |
| 5 | B | Starter theme | M | 1 |
| 6 | B | Theme asset auto-versioning | S | — |
| 7 | C | Form submissions CSV export | S | — |
| 8 | C | RSS/Atom feed | S | — |
| 9 | B | Live style switch in preview | S | 6 |
| 10 | C | Publish webhook | S | — |
| 11 | A | Content scheduling visibility | S | — |
| 12 | A | Editor autosave | M | — |

Deferred: the multi-language front end, per `multilanguage-plan.md`.
Later / opportunistic: version diff (`plan.md` 10), media usage before delete
(item 9).

---

## Track A — the CMS itself

The core is in good shape; this track stays small on purpose. It holds work that
is neither purely theme nor purely site-running.

### A1. Content scheduling visibility (S)

**Why.** Scheduled publishing exists (`publish_due_content()`, the publish check
marker, status `scheduled`). What is weak is *seeing* it: an editor has to open
each item to find what is queued for next week.

**Work.**
* On the content list, show the scheduled date for `scheduled` items and make the
  "publish due" utility's effect legible ("3 items published" already exists).
* A filter or sort by publish date on the content list, using the list query in
  `core/helpers/content.php`.

**Verification.** Extend `tests/content.test.php` for the query/filter behaviour;
check the list rendering manually through the local server.

**Reject if** it grows into a calendar UI. This is a date on a row.

### A2. Editor autosave (M)

**Why.** `content_versions` already stores revisions with a `reason`; a crashed
tab loses work that the storage layer can already hold. This was `plan.md` item 7.

**Work.**
* A draft version every ~60s through the existing versions helper, reason `autosave`.
* On reload, offer the most recent autosave back (reuse the existing restore path).
* Do **not** add new tables or a separate autosave store.

**Verification.** Extend `tests/versions.test.php` for the autosave reason and
retention interaction (`versions.keep`); manual round-trip in the editor.

**Reject if** it needs a new AJAX endpoint that bypasses `save_content()`'s
validation, capability checks or cache invalidation.

---

## Track B — building themes

### B1. Theme integrity check + Health section (S–M)

**Why.** Today a broken manifest, a typo in `available_components`, or a missing
layout file surfaces as a thrown exception or an inline "component not found"
in front of visitors. The pieces already exist; only the check is missing.

**Work.**
* One helper (for example `theme_validate()` in a new `core/helpers/theme.php`,
  or beside `theme_config()`) returning a list of problems:
  * every layout, header and footer named in `theme.php` has a file under
    `theme/layouts/` or `theme/components/` (component lookup must respect the
    `core/components/` fallback);
  * every name in every `available_components` resolves to a theme or core file;
  * every `allowed_children` entry is itself a known component;
  * every `styles` / `scripts` entry resolves to a file under `theme/assets/`
    (strip the `?v=` suffix before checking);
  * `default_layout` / `default_header` / `default_footer` in settings still
    resolve in the active theme.
* Surface it in `admin/health.php`, in the existing checks style. Health is
  already the home for "this will bite you at runtime" checks and needs no new
  admin page, capability or navigation entry.

**Verification.** Extend `tests/theme.test.php`: validate the shipped theme clean,
then validate a deliberately broken fixture manifest. Manual check of the Health
page through the local server.

**Reject if** it becomes a theme linter with rules the CMS does not depend on.

### B2. Starter theme (M)

**Why.** `README.md` says "Download the repo … build your components"; the
`docs-content.php` developer guide is good, but there is no smallest working theme
to copy. The shipped theme is a full business template — a hard starting point.

**Work.**
* A `theme-starter/` folder (or `examples/theme-starter/`): manifest, one layout,
  one header, one footer, one component, one small stylesheet, a README.
* It should be a working theme when its contents replace `theme/`, and it should
  be excluded from the runtime and from the static export.
* Point the developer guide at it; keep the existing theme as the demo.

**Verification.** Copy the starter over a scratch theme and load the site and
admin locally; add a `tests/theme.test.php` case that the starter passes
`theme_validate()` and follows the component contract.

**Reject if** it needs runtime selection code. Theme switching is deliberately
not planned; see below.

### B3. Theme asset auto-versioning (S)

**Why.** `theme.php`'s `?v=5` is a manual counter that is easy to forget, so CSS
edits silently keep serving from browser cache. Admin assets already solve this
with `admin_asset()`.

**Work.**
* Stamp theme stylesheet/script URLs with the file modification time where styles
  and scripts are emitted (`core/render.php`), the way `admin_asset()` does.
* Keep the manifest's `?v=` accepted for compatibility, then drop the counters
  from `theme.php` once stamping works.

**Verification.** Extend `tests/theme.test.php` (URL contains a version derived
from the file); manual check that editing `style.css` changes the URL in the head.

**Reject if** it touches the query-free asset serving path or the export layout.

### B4. Live style switch in preview (S)

**Why.** The theme has tokens (`--theme-primary` etc.) and a preview mechanism.
Letting a developer flip a token, or a stylesheet, and see it in the preview makes
theme work faster without touching the visual-builder line.

**Work.**
* Only inside the existing token-based preview (`?preview=<token>` +
  `cms_preview` cookie) — never for visitors, never cached.
* The smallest useful version: an admin-side field that injects one override
  stylesheet or token set into previewed pages.
* Do not add per-visitor theming, a style editor, or anything stored per page yet.

**Verification.** Extend `tests/http.test.php`: the override appears in preview
responses and never in a cached anonymous response.

**Reject if** it leaks outside preview or adds a second styling system.

---

## Track C — running a site with the CMS

### C1. Archive pagination (M, `plan.md` 8)

**Why.** Blog, portfolio and taxonomy archives render every item. This is the
first thing that breaks on a real site.

**Work.**
* `?page=N` for blog, portfolio and taxonomy archives, with `rel=prev`/`rel=next`.
* Treat paged views like search: mark the page `no_cache` so the write path skips
  them, and make the read path refuse to serve them (`core/bootstrap/front.php`
  already has both hooks for query-driven views).
* Share one paging helper between the archive layouts; do not add a pagination
  abstraction beyond what blog, portfolio and taxonomy share.

**Verification.** Extend `tests/content.test.php` for the offset/limit and
`tests/http.test.php` for paged responses never being cached; render `/blog/?page=2`
locally.

**Reject if** page numbers change any existing URL or cache key for page 1.

### C2. Duplicate content (S)

**Why.** Reusing a page structure is a normal editor task. The editor can clone a
component; the content list cannot copy an item.

**Work.**
* A "duplicate" action on the content list, gated by the existing
  `content.create` capability, producing a new **draft**.
* Copy components, layout, header, footer, taxonomy and parent; the new item gets
  its own slug and a title marked as a copy.
* Reuse `save_content()` so validation, versions, activity log and
  `invalidate_cache()` all apply. No new storage.

**Verification.** Extend `tests/content.test.php` / `tests/admin.test.php` for the
copy result and its capability gate; manual round-trip in the admin.

**Reject if** it copies published status, lets a user without `content.create`
duplicate, or bypasses `save_content()`.

### C3. Maintenance mode (S–M, `plan.md` 11)

**Why.** A site launch or a migration needs a way to take the public site down
without taking the admin down.

**Work.**
* A Settings toggle and message.
* Visitors receive `503` with `Retry-After`; signed-in admins and token previews
  keep working.
* Never cache the maintenance response.

**Verification.** Extend `tests/http.test.php` (anonymous 503, signed-in/admin
unaffected, nothing cached); manual check through the local server.

**Reject if** it becomes a scheduling or "coming soon" page feature.

### C4. Form submissions CSV export (S, `plan.md` 14)

**Why.** Submissions are already stored (`store_submission`). Getting them into a
spreadsheet or a mailing list is the missing half.

**Work.**
* A CSV download on `admin/messages.php`, gated by the existing `forms.view`
  capability, honouring the current filter.
* Fputcsv plus correct headers; no library.

**Verification.** Extend an existing admin/HTTP suite that the download requires
the capability and that the CSV parses back into the same rows.

### C5. RSS/Atom feed (S, `plan.md` 13)

**Why.** Cheap distribution for blog-driven sites, consumed by newsletter tools
and aggregators.

**Work.**
* `/feed/` for the content types the theme marks as feed sources (a new small
  manifest key; `blog_post` opts in).
* Cached like a page, with `<link rel="alternate">` in the head.
* Reuse the sitemap helper's XML style.

**Verification.** Extend `tests/seo.test.php` or add a small feed suite: valid XML,
only published items, correct absolute URLs.

**Reject if** it hardcodes blog-only logic the manifest should own.

### C6. Publish webhook (S, `plan.md` 12)

**Why.** It is the distribution hook that matters most today: publishing anything
should be able to trigger the existing static export rather than polling.

**Work.**
* A Settings URL receiving a small JSON POST on publish/unpublish.
* Reuse the existing HTTP/settings patterns; keep it fire-and-forget and never let
  a failed webhook block a save.

**Verification.** Extend `tests/settings.test.php` for URL validation/round-trip and
assert a save still succeeds when the webhook target is unreachable.

---

## Verification bar for every phase

1. `php tests/run.php` passes (currently 229 tests). Extend the **existing**
   suite nearest the change; no new test framework or harness.
2. Anything the suite cannot see (theme CSS/JS, admin layout, rendered markup)
   is checked through the local server:
   `CMS_CONFIG_FILE="$PWD/tests/config.server.php" php -S 127.0.0.1:8080 tests/router.php`.
3. Each phase states what it changed and what was verified, then stops. Later
   phases are not started speculatively.
4. Any write path that changes public output calls `invalidate_cache()`; any new
   admin page adds both language keys, `$pageHelp`, a capability and navigation.

## Considered and not planned here

* **Theme switching / multiple installed themes.** One site, one theme is a
  feature: it avoids a selector, a theme registry and per-theme storage, and the
  starter theme plus the integrity check cover the developer need. Revisit only
  if hosting several themes in one install becomes a real requirement.
* **Anything on `plan.md`'s "Considered and not planned" list** — unchanged.

## Open decisions to settle at each phase start

* **C1:** items per page — a fixed constant, or a settings value? Prefer fixed
  until a second value is needed.
* **C2:** copy title convention ("Copy of X") and slug suffix, so the user sees
  exactly what duplication produced.
* **B4:** is a preview-only token override genuinely useful enough to build, or
  is the browser's own element inspector enough? This is the first item to drop.
* **A2:** autosave cadence and whether the offered restore is a version entry or a
  prompt; keep `versions.keep` from filling with autosaves.
