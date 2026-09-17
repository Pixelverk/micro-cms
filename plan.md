### 1. Header/footer scripts in settings

Your `README.md` lists this under "Planned" as *"Header/footer JS input in
settings, for google analytics script"*.

* `settings['header_scripts']` and `settings['footer_scripts']` text areas on the
  Settings page.
* Rendered in `core/render.php` before `</head>` and `</body>`.
* Raw output, so gated behind a capability and documented as trusted-admin-only.
* Should be injected **after** `minify_html()`, not before, so the minifier never
  rewrites injected JavaScript.
* **Security decision needed first.** This adds a supported path for arbitrary
  script injection into every public page. That is the point of the feature, but
  it is a deliberate escalation past the per-item escaping the rest of the CMS
  enforces. Suggested hardening: a dedicated `code.manage` capability rather than
  reusing `settings.manage`, so it can be restricted to administrators only.

### 2. Cache warm-up and static export

Two Utilities buttons.

* **Warm cache for all published pages** — iterate published content, render each
  page through `serveFresh()`/`render_page()`, report counts and failures. Useful
  after a bulk edit so the first visitor does not pay the render cost.
* **Export static site as a zip** — warm the cache, then zip `storage/cache/`
  together with `theme/assets/` and `storage/media/`, rewriting `/media/` and
  asset URLs to relative paths so the result works with no PHP at all.
* Needs a `ZipArchive` extension check and a new documented requirement in the
  README.

### 3. Built-in analytics (foundation only)

`admin/metrics.php` is currently a 21-line stub ("Nothing to see here yet!").

* `page_views` migration: path, content id, viewed_at, referrer hash, UA hash,
  is_bot.
* Counting hook in `serveFresh()` and `serveCached()`, batched into a single
  shutdown write, bot-filtered. **Do not store IPs** — keep a daily hash only.
* `admin/metrics.php` becomes a real dashboard: views over 7/30 days, top pages,
  top referrers, cache-hit ratio parsed from `storage/logs/perf.log`.
* Inline SVG sparklines only — the no-CDN / no-build rule rules out a chart
  library.

### 4. Multi-language front end

The plan says to **document the intended path and defer the code**. The design:
a translations table keyed by `(content_id, locale)` plus `settings['locales']`,
with the router resolving a locale prefix.

### 5. Multi-language admin

Effectively done. `en` and `sv` ship for every admin string added, and `tests/design.test.php` plus the bulk/docs additions keep new strings from going missing. Nothing to do beyond keeping it up.

### 6. Live preview in the content editor

Superseded by Phase 3's preview mode (`?preview=<token>`). The remaining idea is
an editor iframe pointing at that URL with `postMessage` for save-then-refresh.
Documented as a follow-up, not planned work.

---

## Known wart

* **Possible test-order coupling.** `tests/admin.test.php` mutates the seeded
  `demo` user's role and adds users, restoring the role at the end. Every suite
  otherwise shares `tests/.tmp/storage`. If a future suite depends on a pristine
  user table, give it its own seeded database instead of relying on the shared one.