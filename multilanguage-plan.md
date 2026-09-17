# Multi-language front end — intended path

**Status:** design agreed, code deferred. This is the plan of record for
`plan.md` item 4; nothing here is implemented yet.

The goal is to serve the same content in several locales without changing the
shape of the CMS: procedural PHP, SQLite, no new dependencies, no build step.

---

## Decisions

* **Default locale keeps its current URLs.** Only non-default locales get a
  prefix (`/sv/about/`), so no existing URL changes and no redirect layer is
  needed.
* **`content` stays the default-locale record** and owns everything that is not
  translated: status, layout, header/footer, parent nesting, taxonomy and
  authorship. A new `content_translations` table holds the translatable fields.
* **Slugs are localized.** A translation carries its own slug, so `/about/` can
  be `/sv/om-oss/`.
* **Untranslated pages fall back to the default locale**, but are served
  `noindex, follow` with a canonical pointing at the default URL. A
  half-translated site keeps working and search engines consolidate the pages
  until a translation exists.
* **Menus stay shared** in v1; per-locale menu labels are phase 6.

## Data model

New table, added to `core/helpers/setup.php` (fresh installs) and
`migrate_registry()` (upgrades, same change in both):

```sql
CREATE TABLE content_translations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    content_id INTEGER NOT NULL,
    locale TEXT NOT NULL,
    title TEXT NOT NULL,
    slug TEXT NOT NULL,
    meta JSON NULL,
    body JSON NOT NULL,
    created_at INTEGER NOT NULL,
    updated_at INTEGER NOT NULL,
    UNIQUE(content_id, locale)
);

CREATE INDEX idx_content_translations_locale ON content_translations (locale, slug);
```

Settings:

* `settings['locales']` — ordered list of enabled codes, e.g. `['en', 'sv']`.
  The first entry is the default locale.
* `site_language` stays for compatibility and must equal `locales[0]`; the
  settings page keeps the two in sync. Fresh installs and the migration seed
  `locales = [site_language]`.
* Validation reuses `validate_language_code()`; duplicates are rejected.

## Locale resolution

* `current_locale()` / `set_current_locale()` helpers, memoised the way
  `load_settings()` is.
* `route_request()` inspects the first path segment: if it is an enabled
  locale other than the default, set the locale and strip the segment (an empty
  remainder is that locale's home page). The default locale's own prefix
  (`/en/about/`) is handled explicitly rather than treated as content (see
  Open questions). Any other segment is content, exactly as today.
* Locale must be known before `load_content_by_slug()`, before any URL is
  built, and before the cache write.

## Loading content

`load_content_by_slug()` keeps walking the parent chain, but resolves each
segment against the current locale's translation slug first, then the default
slug (one `LEFT JOIN content_translations`). The returned page gains:

* `locale` — the active locale;
* translated `title`, `meta` and `body` when a translation exists;
* `translated` — bool, which drives the `noindex` fallback;
* `path` — the locale-specific path, without the prefix.

Taxonomy archives get the same prefix treatment (`/sv/category/news/`). Term
names are not translated in v1.

## URLs

Content links are currently built ad hoc in the theme, e.g.
`url('blog/' . $post['slug'])` or `url($item['slug'])`. Two helpers keep that
honest:

* `content_url(string $path)` — prefixes the active locale (nothing for the
  default) and delegates to `url()`.
* `content_page_url(array $page)` — builds from a page's `path` and `locale`.

Theme components and layouts switch content links to `content_url()`.
`url()` keeps serving assets, admin, `search`, `form-submit` and the sitemap.
Menu items store default-locale relative slugs, so `menu_item_url()` resolves
the target per locale; giving items a `content_id` is the cleaner long-term
shape.

## Cache

`cache_file_for()` keys on the request path, and the locale prefix is part of
that path, so `/about/` and `/sv/about/` already land in different files — no
key change needed.

Invalidation is the gap: `save_content()` clears one path with
`invalidate_cache($slug, $type)`. Saving a translation must clear the localized
path too, or simply clear every cached page.

## SEO

* `<html lang='{current_locale}'>` (`render.php` currently reads
  `site_language`).
* Canonical is the locale-specific absolute URL.
* `hreflang` alternates: one `<link rel="alternate" hreflang="…">` per locale
  that has the translation, plus `x-default` for the default locale;
  `og:locale` and `og:locale:alternate` follow.
* Fallback pages are `noindex, follow` with a canonical to the default URL.
* Sitemap: one `<url>` per locale per item, with `xhtml:link` alternates when
  we add the namespace.

## Admin

* Settings: a **Locales** field (ordered comma-separated codes, first is the
  default). Changing the default changes existing URLs, so it asks first.
* Content editor: locale tabs once more than one locale is enabled. The default
  tab writes `content`; the others write `content_translations` rows, with a
  "copy from default" action on empty ones.
* Content list: a translation-completeness count per item.
* `admin/content/save.php` writes the default locale as it does today plus one
  row per translated locale. Capability checks are unchanged
  (`content.edit.*`).
* Version history is per `content` today; translation history is a follow-up.

## Theme UI strings

Chrome strings in components ("Read more", placeholders, aria labels) are
hardcoded. Add `theme/lang/<locale>.php` returning `key => string`, plus a
front-end `t()` helper that falls back to the default locale and then to the
key. Component schema defaults stay content values, because `body` is
translated per locale.

## Phases

Each phase ships on its own.

1. **Foundation, no behaviour change.** Table + migration + `locales` setting +
   `current_locale()` + router prefix stripping + `<html lang>`. Default-locale
   HTML must stay byte-identical.
2. **Localized loading and URLs.** Locale-aware `load_content_by_slug()`,
   `content_url()`, theme link updates, translation cache invalidation, the
   noindex fallback.
3. **SEO.** hreflang, canonical, `og:locale`, sitemap alternates.
4. **Admin editing.** Locale tabs, settings UI, save path, completeness count.
5. **Theme strings.** `theme/lang/*` and `t()`.
6. **Menus and taxonomy.** Per-locale menu labels and term names.

## Open questions

* Per-locale publish status: v1 inherits the default item's status from
  `content`, so a translation cannot go live on its own.
* Default-locale prefixes (`/en/about/`): redirect to the unprefixed URL
  (recommended) vs 404.
* Do taxonomy term names and menu labels need to arrive earlier than phase 6?

## Verification when the code lands

* Phase 1: render a page before and after and diff it; existing URLs and cache
  keys must be unchanged.
* Per phase, extend `tests/`: locale stripping, translation fallback and
  `noindex`, `content_url()` output, hreflang/canonical, sitemap alternates,
  cache separation, invalidation on translation save, and an admin save
  round-trip.
* Manual: browse every locale and confirm menu and listing links stay inside
  it; validate hreflang with a checker.
