# AGENTS.md

Project-specific guidance for this repo. The general instructions still apply;
this file only records what is true *here*.

## What this is

A procedural PHP CMS. No classes, no composer, no framework, no build step, no
bundler, no CDN, no ORM. Apache routes everything through `index.php`; the
front end renders database content through theme components and layouts, and
`admin/` is a separate server-rendered admin area.

Editors manage content. Design and structure live in `theme/`.

## Plan of record

`plan.md` is the single plan of record: scope, phase order, the verification bar
and the non-goals. Keep it the only plan document — extend it rather than adding
another `*plan*.md`, and update it when a phase ships.

## Commands

```bash
# Whole suite (each test file runs in its own PHP process)
php tests/run.php

# One or more suites (matches test filenames)
php tests/run.php content
php tests/run.php csrf

# Local site, using the built-in server (php -S ignores .htaccess)
CMS_CONFIG_FILE="$PWD/tests/config.server.php" \
  php -S 127.0.0.1:8080 tests/router.php
```

There is no linter, formatter, or asset build. Do not introduce one.

Target runtime is PHP 8.0+ on Apache shared hosting. Local dev currently runs
PHP 8.5 with `pdo_sqlite` and `imagick`; `zip` is **not** installed.
`pdo_sqlite` and `imagick` are real requirements — image variants and LQIP
generation depend on Imagick.

First run: `config.php` with `setup_completed => false` seeds the schema and
demo content into `storage/` on the next request. Demo logins are `admin` / `admin`
(administrator), `editor` / `editor` and `author` / `author`.

## Where things are

| Path | Role |
| --- | --- |
| `index.php` | Single front controller: installer check, then media / admin / front |
| `core/helpers/*.php` | All shared functions, loaded once by `bootstrap_core()` |
| `core/render.php` | Layout + component rendering, `<head>` assembly, minify in production |
| `core/router.php` | Front-end routing, admin dispatch, redirect helpers |
| `core/bootstrap/{front,admin,media}.php` | Per-entry-point bootstraps |
| `core/components/` | Fallback components; `sample-component.php` documents the contract |
| `admin/` | One file per page, or `<dir>/index.php`; wrapped by `admin/partials/layout.php` |
| `theme/theme.php` | Manifest: layouts, headers/footers, content types, form types, styles, scripts |
| `theme/components/`, `theme/layouts/` | The active theme |
| `tests/` | Dependency-free harness; `tests/README.md` explains isolation |
| `storage/` | SQLite DB, cache, logs, sessions, media, sitemap (gitignored) |

## Request flow

1. `index.php` defines `CMS_PATH`, `CORE_PATH`, `STORAGE_PATH` and loads config
   (`CMS_CONFIG_FILE` env var can point elsewhere).
2. First-run installer runs when `setup_completed` is false or the DB is
   unusable.
3. `/media`, `/admin`, and front-end requests take their own bootstrap.
4. The front path boots the session, runs `migrate_before_read()`, checks the
   HTML cache, then renders fresh.

Cache rules (`core/bootstrap/front.php`): only anonymous, non-preview GETs of
published 200 pages are written to `storage/cache/`; cached HTML is never served
to a signed-in user, and search/taxonomy views are never cached.

Every admin POST is CSRF-checked centrally in `core/bootstrap/admin.php`. The
login form is the only exception.

## Conventions the test suite enforces

These are checked by `tests/design.test.php`; a change that breaks one fails the suite:

* Every `admin_trans()` key exists in **both** `admin/lang/en.php` and
  `admin/lang/sv.php`. Keys are `area_element` (for example `nav_dashboard`,
  `trash_move`, `settings_site_title_help`), never a sentence; the value holds
  the text that is shown.
* No inline `<style>` blocks in the admin UI.
* Every CSS class used in admin markup exists in `admin/assets/style.css`.
* Every admin page that includes `admin/partials/layout.php` sets `$pageHelp`.

Beyond what tests check:

* Escape all output with `e()`. Raw HTML is only ever deliberate (component and
  layout render bodies).
* All functions are global; there is no namespace or autoloader. Give new
  helpers a distinct, prefixed name.
* Match the surrounding file's style. Most files start with
  `declare(strict_types=1);`.
* Keep conditional state and feedback CSS — error/success/info/warning colours,
  `.status-*` labels, empty states, `.field-error`, the `.notice*` callouts and
  the `.off-screen` accessibility helper. These only render in their matching
  state, so a static grep finding no uses is not evidence that they are dead.
  Only prune structural utilities that duplicate another rule.
* `theme/assets/img/icon-192.png` and `icon-512.png` are the placeholder app
  icons the web manifest falls back to (see `icons.app` in the manifest); keep
  them square PNGs. The theme's `favicon.ico` is a browser favicon only — an
  `.ico` is not a usable manifest or apple-touch icon.

## Common changes

### Add a theme component

`theme/components/<name>.php` returns an array with `label`, `schema`,
`children` (`'any'` | `'none'` | `'some'`), `allowed_children`, `css`, `js`,
and `render`. Add the name to `theme.php` under the relevant
`content_types[...]['available_components']`. Schema input types are listed in
`admin/partials/content-editor-templates.php`. `core/components/` is the
fallback when the theme has no file of that name.

Component CSS/JS is collected per request, de-duplicated by component name, and
injected after the theme stylesheets. Theme and admin asset URLs are stamped
with the file's modification time (`asset()`, `admin_asset()`), so editing a
stylesheet or script needs no version bump.

### Content model

`theme/theme.php` decides which components each content type offers.
`blog_post` and `portfolio_item` are *written* with the `quill-editor`
rich-text component; `page` is *assembled* from components, so its palette is
the section library. Rich text is still offered on pages (and `policy-section`
allows `quill-editor` as its only child), so removing a component from a type's
`available_components` can strand existing content — see the note below.

An `available_components` change is a content-affecting change: the editor only
hydrates components it knows, and an unknown type becomes an HTML comment that
is dropped on the next save. Check seeded and existing content for the component
before removing it.

### Add a schema migration

Add a keyed closure to `migrate_registry()` in `core/helpers/migrate.php`,
**and** make the same change in `core/helpers/setup.php`. Fresh installs never
run migrations, so setup.php is the schema source of truth. Migrations must be
idempotent; a marker file (`storage/.migrations`) skips the registry when it is
current.

### Add an admin page

Create `admin/<page>.php` (or `admin/<page>/index.php`) — paths must be
lowercase `[a-z0-9/-]`. If the page needs more than "signed in", add its
capability to `admin_page_capabilities()` in `core/helpers/admin.php` and gate
actions with `require_capability()`. Add navigation in
`admin/partials/sidebar.php`, strings to both language files, and a `$pageHelp`
block before including the layout.

## Gotchas

* **Cache invalidation.** Any write path that changes public output must call
  `invalidate_cache()`. `save_content()`, `save_setting()`, menu saves and the
  utilities page already do.
* **`url()` reads the global `$config`**, not `config()`. `index.php` sets it;
  a new entry point that skips that line will silently ignore `config['url']`
  (subfolder deployments). Prefer `config()` for new config reads.
* **Preview is token-based.** Merely being signed in must not change what a URL
  returns — only `?preview=<token>` with the matching `cms_preview` cookie
  relaxes visibility and disables caching.
* **`.htaccess` is the routing contract**: `core/` and `storage/` are blocked,
  direct files under `admin/`/`theme/` are blocked except `*/assets/`,
  everything else goes through `index.php`. `php -S` needs `tests/router.php`.
* **A new file under the web root must be world-readable (`644`).** Apache runs
  as `www-data`, which is not the developer's user, so a file created `600`
  cannot be read and a request touching it dies with a *blank 500* — no message,
  no stack trace. This has already cost two debugging sessions:
  `admin/content/duplicate.php` (a new admin page) and
  `core/helpers/pagination.php` (required by `bootstrap_core()` on every
  request, so it took the whole site down, not just one page).
  Editing an existing file preserves its mode, so this only bites files that are
  **created**. Check before finishing a change:

  ```bash
  # Empty output means Apache can read everything it serves.
  find admin core theme *.php -type f ! -perm -o=r
  ```

  Fix with `chmod 644 <file>`. Files under `tests/` are never served, so their
  mode does not matter.
* **`storage/` must be writable by the web server user.** SQLite refuses every
  write with "attempt to write a readonly database" otherwise, which surfaces as
  a failed login or a failed save rather than an obvious permissions error.
  Admin → Health reports this class directly; run it before blaming code.
* **HTML minification** only runs when `config('env') === 'production'`;
  component JS is collected and wrapped in a `DOMContentLoaded` handler.
* **The admin UI may assume JavaScript. The public front end may not.** In the
  admin, a control that saves when it changes can use an inline
  `onchange="this.form.submit()"` (the form inbox's status select does) and needs
  no submit-button fallback. Keep the public theme rendering without script:
  navigation, menus and forms there must not depend on JS.
* `config.php` controls `env`, `url`, `perf_logging`, `setup_completed`, session
  timeout, security policy, `cache_lifetime`, activity-log retention, and
  version retention (`versions.keep`).

## Verification bar

Run `php tests/run.php` before calling anything done — it must pass. Add or
extend a `tests/<name>.test.php` suite for new behavior; `tests/README.md`
shows the three-line pattern and `tests/helpers.php` has the assertions.

Tests never touch `storage/`: `tests/bootstrap.php` refuses to run if the test
storage resolves inside it, and every artefact lands in `tests/.tmp/`.

Anything the suite cannot check — theme CSS/JS, admin layout, rendered markup —
verify through the local server above rather than assuming it works.