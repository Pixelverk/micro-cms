# Cleanup & simplification plan

Internal cleanup. Remove accidental complexity, duplication and dead weight from
a codebase written before the project rules applied. Every feature stays except
the "blocks" feature, which the owner has explicitly removed from scope.

Baseline: commit `77d71cb`, `php tests/run.php` → `198 passed, 0 failed`.
Sizes: core 7,744 PHP lines · admin 7,542 PHP + 2,953 JS/CSS · theme 2,886 PHP +
11,468 CSS/JS · tests 4,111 PHP.

Line numbers are from the baseline commit and will drift as phases land.

**Status: complete.** Phases 0–7 executed and verified, each as its own
reviewable change. Deviations are noted in the phase they belong to.

---

## Rules for executing this plan

* One phase per change. Run the suite, verify, report, **stop**.
* No new dependencies, no framework, no build step, no autoloader, no classes.
* Prefer deletion over a new abstraction. A phase that grows the codebase needs
  a stated reason.
* Phases 0–3, 5 and 6 are behavior-preserving. Phase 4 contains the approved
  behavior changes and bug fixes.
* Anything the suite cannot see (admin markup, theme CSS/JS) is verified through
  the local server, not assumed.
* Keep every diff reviewable: if a phase turns into two unrelated changes, split
  it.

### How each phase is verified

| Kind of change | Verification |
| --- | --- |
| PHP logic | `php tests/run.php` (must stay green) plus a targeted grep/snippet |
| Deleted symbol or file | `grep -rn '<symbol>' .` returns only the intended remainder |
| Admin markup | Log in as `admin`, `editor` and `author` and exercise the page |
| Theme CSS/JS | Front page + blog post + search + portfolio item; check console and 404s |
| Cache behavior | Second anonymous request returns `X-Cache: HIT`; an edit invalidates it |
| Schema | Fresh install into a scratch storage, then inspect tables/columns |

---

## Resolved decisions

| # | Decision | Answer |
| --- | --- | --- |
| 1 | `admin/metrics.php` stub | Rename to Analytics; implement later. Keep the page, nav and strings. |
| 2 + 6 | Blocks feature | Remove it entirely. Theme components are the only "blocks". |
| 3 | Profile URL | Keep the redirect to `admin/user/edit`; make it work for every role. |
| 4 | Slug algorithms | Unify. |
| 5 | `picture()` / variants / LQIP | Keep. Uploaded images must get their resized variants and LQIP; `picture()` stays available. No theme wiring. |
| 7 | `perf_logging` | Keep, with an explanatory comment. |
| 8 | bootstrap-icons `.woff` | Drop the legacy fallback. |
| 9 | Schema ownership | Keep migrations; consolidate the current schema into `setup.php`. |
| 10 | Test seeding | Seed once unless a suite explicitly needs a fresh database. |
| 11 | Unused admin CSS | Clean up. |
| 12 | `versions.keep` | Add the key to `config.php`. |
| — | `theme/assets/favicon.png` | Keep as an example; do not delete. |

---

## Phase 0 — Make the test runner trustworthy (do this first)

Every later phase leans on the suite, and today the runner can report a crash as
a pass.

* `tests/run.php:54-60` records a non-zero-exit suite in `$failures` but only
  increments `$failed` when the output is empty. Line 68 and the exit code at
  :70 branch on `$failed`, so a suite that prints `PASS` lines and then fatals
  prints `OK` and exits `0`.
* Fix: derive the verdict from `$failures` (`$failed = count($failures)`,
  `exit($failures === [] ? 0 : 1)`), or delete `$failed`.

**Verify:** suite still green; then add a throwaway suite that prints one `PASS`
and triggers a fatal, confirm `run.php` exits non-zero and lists the failure,
then delete the throwaway.

---

## Phase 1 — Remove the blocks feature (approved feature removal)

Theme components remain the only reuse mechanism. This removes a whole admin
area, its helper file, its schema, its tests and its wiring.

**Delete**

* `admin/block/index.php`, `admin/block/json.php` (the directory).
* `core/helpers/blocks.php` (376 lines). Its `component_exists()` is used only
  inside that file, so it goes too — this also removes the need for the
  `component_path()` consolidation previously planned for `render.php`.
* `tests/blocks.test.php`.
* The `blocks` table from `core/helpers/setup.php:62-75`.
* The `2026_09_17_000010_blocks` entry from `core/helpers/migrate.php:153-...`.
* `'features' => ['blocks' => true]` from `config.php:47-49`.

**Unwire**

* `require_once .../helpers/blocks.php` in `core/helpers/common.php:128` and
  `tests/bootstrap.php:63`.
* The Block library sidebar section in `admin/partials/sidebar.php:89`.
* The saved-block picker and `window.savedBlocks` / `blockEndpoint` /
  `blockSaveEndpoint` in `admin/content/edit.php` (~484, 492, 516-524) and the
  whole block UI plus "Save as block" handler in
  `admin/assets/content-editor.js` (~593-700).
* Block strings in `admin/lang/en.php` and `sv.php` (the `block_*` /
  `saved_blocks` / `save_as_block` group around 277-292), the `docs_editor_help`
  mention of blocks, and the block entries in
  `admin/partials/docs-content.php` and any help panel that links to them.

**Schema note:** existing installs keep an orphaned `blocks` table. No drop
migration is added (non-destructive); fresh installs simply never create it.
Removing the newest registry key is safe because applied keys live in the
`migrations` table — the marker mismatch just triggers a no-op re-run.

**Verify:** suite green after the blocks suite is gone; create/edit/delete
content of every type in the admin with no JS errors and no "Saved blocks" UI;
run Utilities → Run migrations on a scratch install; a fresh install has no
`blocks` table.

---

## Phase 2 — Delete what is provably dead (no behavior change)

### 2a. Dead functions and branches

| Item | Where | Action |
| --- | --- | --- |
| `json_decode_safe()`, `json_encode_safe()` | `core/helpers/common.php:192,197` | Delete; zero callers repo-wide |
| `current_user_can()` | `core/helpers/admin.php:164` | Delete; second name for `admin_can()` |
| `media_alt()` | `core/helpers/common.php:441` | Delete; only `tests/media.test.php` calls it. `picture()` reads `alt_text` directly, so the pipeline is unaffected |
| `search_request_is_uncacheable()` | `core/helpers/search.php:356` | Delete + its assertion (`tests/search.test.php:253`); body is `return true;` |
| `test_php_timed()` | `tests/helpers.php:93` | Delete; duplicate of `test_php()` with a different timeout |
| `log_activity_safe()` | `core/helpers/csrf.php:178` | Delete; `log_activity()` already no-ops on a missing table. Point its two callers (`csrf.php:70`, `admin/content/versions.php:52`) at `log_activity()` |
| `response_is_uncacheable()` | `core/helpers/content.php:153` | Delete; reduces to `can_preview_content()`. Call that at `core/bootstrap/front.php:127` |
| `content_json_for_column()` | `core/helpers/content.php:444` | Delete; its `function_exists` guard is always true and its fallback duplicates `content_version_json()`. Call `content_version_json()` at the four sites |
| `'component'` key fallbacks | `core/render.php:245` | Use `['type']` directly; nothing writes a `component` key |
| `$type` parameter on `validate_throw()` | `core/helpers/validate.php:24` | Drop it; every caller passes or defaults to `'error'` |
| Dead ternary + `$debug` | `core/helpers/migrate.php:352,361` | `echo '<li>' . e($line) . '</li>';` |
| `defaults` config block | `config.php:40-43` | Delete; never read, and `status => published` contradicts the real `draft` default |
| Redundant capability entries | `core/helpers/admin.php:68-69` | Delete `user/add` and `user/edit`; the `user` prefix already matches both. Update the assertion at `tests/admin.test.php:110` |
| Unused `$username` locals | `admin/category/index.php:5`, `admin/content/index.php:5`, `admin/content/edit.php:4`, `admin/category/edit.php:5`, `admin/tag/edit.php:5` | Delete the assignment |
| Unused `$pageTitle` in header | `admin/partials/header.php:9` | Delete; the breadcrumb is built from the URL |
| Commented-out code | `admin/media/save.php:225,229,239,243,255`, `admin/partials/content-editor-templates.php:6`, `admin/assets/content-editor.js:183` | Delete |
| Invalid attribute | `admin/partials/help.php:4` | Remove `type="button"` from the `<span>` |

### 2b. Dead files and assets

| File | Size | Evidence |
| --- | --- | --- |
| `theme/assets/bootstrap.css` | 237 KB | Zero references; `theme.php` loads only `layout.css`, `utilities.css`, `style.css`, bootstrap-icons. Contradicts "not Bootstrap" |
| `theme/assets/vendor/instant-page.min.js` | 3 KB | Referenced only inside a commented-out `scripts` block (`theme/theme.php:177-182`) — delete both |
| 32 unused SVGs + `iconoir.com` in `admin/assets/icons/` | 21 KB | Only 23 of 55 names are ever passed to `icon()` |
| `admin/assets/vendor/quill/quill.js.LICENSE.txt` | 0.2 KB | Duplicate of the 1.6 KB `LICENSE` beside it |

Keep: Quill, Sortable, bootstrap-icons, `theme/assets/favicon.png` (example).
Keep the whole media pipeline: `picture()`, variant generation and LQIP stay, and
the media library keeps rendering from the stored formats.

### 2c. Dead CSS and manifest noise

* `theme/components/{blog-card,blog-preview-section,testimonial-section}.php`
  ship a `css` key containing only a comment — set it to `''`. (Verified: only
  these three; the other components carry real rules.)
* `theme/theme.php:61,65` lists `feature-card` twice — delete the second.
* `theme/assets/utilities.css:66` defines `.p-sm-5` outside a media query and
  `:187` defines it inside one — delete the unqualified copy.
* `theme/assets/style.css:32-34` `.flex-row` is unused — delete.
* `theme/assets/layout.css:6-14` and `theme/assets/style.css:8-17` both declare
  the body/main sticky-footer scaffolding — keep `layout.css` (the file the
  theme README says owns it) and delete the copy in `style.css`.
* `admin/assets/style.css`: unused structural utility classes (`flex-col`,
  `items-start/end`, `justify-between/center/end`, `text-xs`,
  `text-center/right`, `uppercase`, `font-normal/medium/semibold/bold`,
  `mb-xs/sm/md/lg`, `mt-xs/sm/md/lg`, `clickable`). Verified zero uses in admin
  PHP/JS; dynamic `status-*` classes are excluded.
* **Correction (after execution):** state and feedback rules were restored —
  `.field-error`, the `.notice` / `.notice-error` / `.notice-success` /
  `.notice-info` group, and the `.off-screen` accessibility helper. They only
  appear in their matching state, so "zero current uses" does not make them
  dead. See the AGENTS.md convention. `.modal-content` also stayed: the design
  test requires it as shared page furniture.

**Verify:** suite green; each deleted symbol greps to only its intended
remainder; click through every sidebar page; front page, blog post and search
render with no new console errors or 404s.

---

## Phase 3 — One implementation where there are two (behavior-preserving)

| Duplication | Where | Consolidation |
| --- | --- | --- |
| Cache key building | `core/bootstrap/front.php:22-27` vs `core/helpers/cache.php:34-36` | Move `cache_file_for()` into `cache.php`; call it from `invalidate_cache()`. Drop the expiry re-check in `serveCached()` (`front.php:73-77`) — `index.php:111` already validated it |
| JSON-caller detection | `core/helpers/csrf.php:75-77` vs `core/helpers/validate.php:32-34` | Add `request_wants_json(): bool` in `common.php`; both use it |
| Settings write transaction | `core/helpers/settings.php:82-108` vs `admin/settings.php:277-293` | Make the page call `save_settings()` instead of its hand-rolled copy |
| Taxonomy save/remove | `admin/category/save.php` ≈ `admin/tag/save.php`; `admin/{category,tag}/remove.php` | One `save_taxonomy($kind, $post)` + `remove_taxonomy($kind)`; pages keep capability check + call |
| Descendant walk | `admin/content/edit.php:78` and `admin/content/save.php:41` | One `content_descendant_ids()` in `core/helpers/content.php` |
| Media folder delete | `admin/media/remove.php:39-60` vs the inline iterator at `admin/media/save.php:287` | One `delete_media_directory()` |
| Slug auto-fill JS | `admin/category/edit.php:117-141` vs `admin/tag/edit.php:130-154` | One shared snippet |
| Duplicate reads | `admin/content/save.php:17,63`; `admin/content/edit.php:73,90` and `:484,523`; `admin/user/edit.php:6,13` | Reuse `$contentData`; load `list_content()` once; compute `blocks_for_type()`/block data is gone with Phase 1; drop `user_exists()` in favor of the `load_users()` row already fetched |
| Schema owned in three places | `core/db.php:77-86` and `core/form-submit.php:225-232` duplicate `migrate.php:47-65`; `setup.php` creates neither table | Decision 9: add `login_attempts` and `form_rate_limits` to `setup.php`, then delete the `db()` self-heal block and the lazy `CREATE TABLE` in `form_rate_limit_ok()`. Keep the migration registry as the upgrade path for existing installs |

`setup.php` already contains the full current `content` schema (`created_by`,
`updated_by`, `search_text`, indexes), so the only schema gap is those two
tables.

**Verify:** suite green (main gate). Additionally: cache hit/miss via the
`X-Cache` header; create/edit/remove a category and a tag; reorder a menu; upload
a JPG and a PNG and confirm `formats_json` gains the webp plus resized variants
and `lqip_base64` is populated, then delete a media folder; edit another user;
and a fresh install into scratch storage creates both rate-limit tables (check
via `sqlite3`/`PRAGMA table_info`).

**Risk:** taxonomy and schema touch write paths with no dedicated suite. Do them
one at a time and exercise each through the admin UI.

---

## Phase 4 — Approved behavior changes and bug fixes

### 4a. Unify slugs (Decision 4)

`sanitize_slug()` (`core/helpers/common.php:243`) drops non-ASCII while
`slugify()` (`core/helpers/menus.php:145`) transliterates, so the same title
yields different slugs per path. Keep one function with the transliterating
rules (regex `\pL`, `iconv//TRANSLIT`, lowercase, collapse dashes) and route
content, menus and taxonomy through it. Keep a sane empty fallback.

**Verify:** suite green; create a page, a category and a menu item with a
non-ASCII title (e.g. `Smörgås & Kaffe`) and confirm all three produce the same
transliterated slug.

### 4b. Profile works for every role (Decision 3)

`admin/profile.php` redirects to `admin/user/edit`, which is gated behind
`users.manage`, so editor and author hit a 403 despite the sidebar and header
linking them there. Keep the redirect and make self-edit real:

* Allow `user/edit` / `user/save` when the target is the current user, via a
  documented exception in `admin_guard()` / page capabilities.
* `admin/user/edit.php`: hide the role field (and other admin-only fields) when
  the viewer lacks `users.manage`.
* `admin/user/save.php`: when a user saves their own account without
  `users.manage`, force the stored role and ignore any posted `role` value.
  This is the security-critical part — an author must never be able to promote
  themselves.

**Verify:** as `author` and `editor`, open Profile (via the redirect), change
name/email/password, confirm the role is unchanged in the DB; confirm they still
cannot open `admin/user` or edit another user; as `admin`, role editing still
works.

### 4c. Small bug fixes

| Bug | Evidence | Fix |
| --- | --- | --- |
| Dashboard "Manage menus" link dead | `admin/dashboard.php:34` targets `admin/menu`; only `admin/menu/edit.php` exists, so the router bounces with "That admin page does not exist" | Point at `admin/menu/edit` |
| Menu delete unreachable | `admin/menu/edit.php`: a `<form>` at :108 nests inside the form at :60-119, so browsers drop the inner tag and Delete submits *save*; `admin/menu/remove.php` has no other caller | Move the remove form outside `menu-save` (or use the `form=` attribute) |
| WebP quality setting ignored | Settings writes `quality_webp` (`admin/settings.php:115`, seeded at `core/helpers/setup.php:273`); the encoder reads `image_quality` (`admin/media/save.php:45`), which exists nowhere else | Read `quality_webp` |
| Duplicate `class` attributes dropped | `admin/content/index.php:293,296`, `admin/user/index.php:52,55`, `admin/category/index.php:82,85` — the second `class` is ignored, so `.inline-form`/`.inline-form-block` never apply; `admin/tag/index.php:142` works around it with `style="display:inline"` | Merge into one attribute; replace the inline style with the class |

**Verify:** suite green; click Manage menus from the dashboard; delete a menu
item and confirm the row goes; change WebP quality, upload an image and confirm
the stored value is used; check the three list-page confirm forms render inline
in the browser.

---

## Phase 5 — Theme and asset weight

* `admin/content/edit.php:250-251`: Quill (~228 KB) is appended for every
  content type, but only `portfolio_item` lists `quill-editor`
  (`theme/theme.php:99`). Load Quill (and Sortable, if only needed there) when
  the type allows the component. **Verify:** the portfolio editor still works; a
  page editor no longer requests the files.
* Drop `bootstrap-icons.woff` (120 KB) and its `src` line, keeping the `.woff2`
  (Decision 8). **Verify:** every icon still renders in the admin and front end.
* Two names for one flex helper: `.flex-col` (`style.css:28`, used 3×) and
  `.flex-column` (`utilities.css:35`, used 2×). Pick one spelling and update the
  call sites.
* `theme/assets/README.md` describes a layer contract the CSS breaks; align the
  doc with what Phase 2 leaves.

**Verify:** no visual regression on front page, blog post, search, portfolio and
both archive layouts; `theme.test.php` green.

---

## Phase 6 — Test suite

Decision 10: seed once unless a suite explicitly needs a fresh database.

* Seed once in `run.php` instead of the 16 `test_fresh_database()` calls, each
  of which rebuilds the shared SQLite database in a subprocess. Keep an explicit
  reseed for suites that mutate users/settings. **Verify:** suite green; compare
  wall-clock before/after.
* One fixture builder: `seed_content` (`content.test.php:16`), `search_seed`
  (`search.test.php:16`), `version_seed_page` (`versions.test.php:16`) and
  `http_seed_content` (`http.test.php:175`) are four copies. Move one
  `seed_content(array $overrides = []): int` into `tests/helpers.php`.
* Remove the flaky timing assertion (`settings.test.php:87-90`) or make it
  deterministic (cache a value, delete the row, assert the cached value still
  returns).
* Drop the redundant role `UPDATE` in `http.test.php:42-44`; the database was
  just reseeded at line 40.
* Move or delete the settings round-trip in `auth.test.php:117-120`; it leaves a
  `test_array` row behind and `settings.test.php:97-104` already covers it.
* Trim the near-vacuous source-text assertions that pass on comments
  (`theme.test.php:24-42,59-68,92`; `design.test.php:95-101,103-117,127-142,153-159`),
  e.g. `assert_contains('/* 10. Media Queries', $css)`. Keep the undefined-class
  check (`design.test.php:58`) and the translation-parity checks (`:161-202`);
  those catch real regressions.

**Executed differently:** every suite still starts from a pristine database
(most of them mutate content, users and settings), so `test_fresh_database()`
builds the seed **once** into `tests/.tmp/storage/seed-template.sqlite` and each
later call is a file copy — 16 installer runs became 1 cold / 0 warm, measured at
4.93 s → 0.03 s of seeding cost. `run.php` is unchanged. The scenario helpers
(`search_seed`, `version_seed_page`, `http_seed_content`) stay as thin wrappers
over the shared `seed_content()`, so there is one INSERT. Only the section-banner
and component-CSS-count assertions were dropped; the page-furniture, button-set
and `main.js` source checks were kept — the furniture check caught a real
over-deletion earlier in this plan.

**Verify:** suite green with the same or better pass count; runtime not worse
than baseline; deliberately break one covered behavior per touched suite to
confirm it still fails.

---

## Phase 7 — Admin tidy, configuration and documentation

* **Rename Metrics → Analytics** (Decision 1): `admin/metrics.php` →
  `admin/analytics.php`, sidebar link and `is_active('analytics')`, docs link.
  While there, fix the duplicate `metrics` array key in both language files
  (`admin/lang/en.php:17` vs `:316`, same in `sv.php` — the later value wins, so
  the label is currently "Reports"), and rename the keys to
  `analytics` / `analytics_help`. Keep the stub content; implementation stays in
  `plan.md`. **Verify:** the page loads at `/admin/analytics`, sidebar label
  reads "Analytics", translation-parity test green.
* **`perf_logging`** (Decision 7): add a comment in `config.php` explaining it is
  a debug switch that writes `storage/logs/perf.log`; no behavior change.
* **`versions.keep`** (Decision 12): add `'versions' => ['keep' => 20]` to
  `config.php`, matching the default in `core/helpers/versions.php:24`, so the
  documented key exists.
* **README**: the `Implemented`/`Planned`/`Maybe` lists are stale — version
  history, activity log, bulk edit, front-end search, SEO, sitemap and in-app
  docs all ship. Rewrite against `plan.md`.
* **Stale "Phase N" comments** contradict the code. Delete or neutralize:
  `core/helpers/admin.php:9-12` (claims every capability is granted, while the
  real matrix is at :117-141), `core/router.php:161`, `core/helpers/csrf.php:174`,
  `core/helpers/content.php:27`, `core/helpers/migrate.php:40,87,114,135,143,153`,
  `tests/{settings,theme,design}.test.php` headers, `plan.md:56`.
* **`AGENTS.md`**: note this cleanup file, replace the fixed "198 assertions"
  figure with "the suite must pass", and confirm the blocks section is gone from
  the common-changes text.
* **`theme/assets/README.md`**: final alignment after Phase 5.

**Verify:** documentation-only; re-read each edited claim against the code and
confirm `grep -rn "Phase [0-9]"` returns only intentional historical notes
(none expected).

---

## Explicitly not in this plan

* No new feature and no rewrite of working behavior beyond Phase 4's approved
  fixes.
* No new dependency, no composer, no framework, no build step.
* No directory restructuring, no renaming spree, no namespace or classes.
* No drop of the orphaned `blocks` table on existing installs; no other
  destructive migration.
* No wiring of `picture()` into a theme component; the upload/variant/LQIP
  pipeline stays as it is.
