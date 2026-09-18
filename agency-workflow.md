# A one-man agency on Micro CMS

How a single developer/designer uses this CMS to deliver client websites built
from client content and design templates bought on Envato Elements.

**Status:** working practice, not a plan. It describes a repeatable process using
the CMS as it is today, and names the few gaps it exposes. Read with
[`README.md`](README.md) (product), [`plan.md`](plan.md) (backlog) and
[`continuing-plan.md`](continuing-plan.md) (phased programme).

---

## 1. The operating model

### One client, one install

The CMS resolves exactly one theme, at `theme/` (`theme()` in
`core/helpers/common.php`). There is no theme selector, no multisite, no shared
database. That is not a limitation to work around — it is the delivery model:

* **One install per client site.** `theme/` is that client's design. `storage/`
  is that client's content.
* **A private `agency-kit` repo** holds what is reused across clients: the
  sanitised upstream CMS as a subtree or vendored copy, a starter theme, your
  component recipes, deployment notes. Each client install is a clone of it plus
  a bespoke theme.
* **Never build a shared "master" install and branch per client.** It is the road
  to a home-grown multisite layer, and this project's value is having none.

### Why this works commercially

| Agency pain | What the CMS removes |
| --- | --- |
| Plugin/update sprawl and breakage | No plugins. Updating the CMS is `git pull` plus a test run. |
| Slow, fragile themes | No build step, no bundler, no npm audit treadmill. |
| Client breaks the design | Editors change content only; structure and design live in `theme/`. |
| Hosting cost and ops | Any cheap Apache + PHP 8 + SQLite host. One folder, one database file. |
| Handover takes a day | The client gets a login and an in-app guide, not a manual. |

### Hosting and delivery choices

Decide these at the start of every project, because they are hard to change later.

| Model | Use when | Consequences |
| --- | --- | --- |
| **PHP host + SQLite** (default) | Client edits content, forms must work | Full CMS: editor, forms, search, analytics, preview. Needs Apache (`.htaccess`) and a writable `storage/`. |
| **Static export** (Utilities → Export Static Site) | Brochure site, content frozen, cheapest hosting | Exports warmed published **pages** plus `theme/assets/` and `media/`, with relative URLs. Loses the admin, working forms, search, taxonomy archives, `sitemap.xml` and `robots.txt`. |
| **PHP host + export both** | Most common | The PHP site is the source of truth; the export is a staged copy or a client's cheap mirror. |

Practical host requirements to verify per client: PHP **8.0+**, `pdo_sqlite`,
`imagick`, `zip` **or** `phar`, and a writable `storage/`. Run Admin → Health on
the client's host immediately after upload; it reports every one of these.

---

## 2. The delivery pipeline

Seven stages. Each ends with a check, not a feeling.

```
inventory  →  content map  →  theme build  →  content load  →  launch  →  handover  →  care
   1             2                3               4             5          6           7
```

| # | Stage | Output | Gate |
| --- | --- | --- | --- |
| 1 | Template inventory | A written list of what the Envato template actually contains | Every section accounted for |
| 2 | Content map | Content types, pages, menus, per-section components | Matches the sitemap agreed with the client |
| 3 | Theme build | A working `theme/` on local seed data | `php tests/run.php` passes; every page renders |
| 4 | Content load | Real client content in `storage/` | Client-approved content, no placeholder text |
| 5 | Launch | Live site on the client's host | Launch checklist below, all green |
| 6 | Handover | Login, short walkthrough, runbook | Client can edit a page unaided |
| 7 | Care | Retainer | Backups and update discipline in place |

---

## 3. Stage 1 — Template inventory

Envato Elements gives you a **static HTML template**: many pages, a CSS
framework, jQuery plugins, stock images and often a page builder's leftovers. It
is raw material, not something to wire up as-is.

Work through the template and write down, for each page:

* **Sections** — hero, feature grid, testimonial, pricing, FAQ, CTA, team, blog
  preview, portfolio grid, contact. These become components.
* **Repeated patterns** — the same card markup with different content is *one*
  component with schema, not three components.
* **Global chrome** — header (with its dropdown/nav behaviour) and footer. These
  become components plus menu locations.
* **Assets** — fonts, icons, images, the framework CSS, the JS plugins.
* **Interactive behaviour** — sliders, accordions, tabs, lightboxes. Decide per
  item: keep if it earns its weight, otherwise replace with a few lines of plain
  JS or drop it.

Decisions to make here, not later:

* **Fonts** — self-host them under `theme/assets/`. There is no CDN and the test
  suite fails on CDN references (`tests/theme.test.php`). If the template uses a
  Google Font, download the woff2 files or substitute a system stack.
* **Framework CSS** — the theme already ships `utilities.css`, a hand-written
  Bootstrap-compatible subset. Keep the client's Bootstrap if their markup needs
  it, or trim to the utilities layer; do not ship both.
* **Icons** — `bootstrap-icons` is already vendored (`.woff2` only). Prefer it
  over adding a second icon font.
* **The template's own designer assets** — sliders and page-builder CSS/JS are
  usually the biggest single cause of slow sites. Audit before keeping.

## 4. Stage 2 — Content map

This is the stage that makes a site maintainable, and the one most likely to be
skipped. Write it down and get the client to agree.

### Map template sections onto the built-in shapes

The shipped theme demonstrates the pattern. In the client's `theme.php`:

* **`page`** — a **marketing page assembled from components**. This is where the
  template's section library goes in `available_components`.
* **`blog_post`** — a **written** item, assembled from `quill-editor` alone.
* **`portfolio_item`** — a **work/case-study** item. Useful well beyond
  portfolios: projects, properties, treatments, dishes, team roles. It is the
  closest thing to a second "structured" type, so reach for it before inventing a
  new one.

### Know how listing images actually work

This bites every new build, so decide it in the content map rather than
discovering it at content load.

The blog and portfolio layouts and the preview sections read presentation values
out of the item's `meta`: `thumbnail`, `gallery`, `author`, `author_image`,
`excerpt`, `project_url`. **None of these are editor fields** — the SEO panel
only writes the keys listed in `seo_editable_fields()`. Present keys such as
`thumbnail` are set by the theme at render time or by seed data, and
`theme/layouts/blog.php` resolves a non-URL thumbnail through `img()`, i.e. a
filename inside `theme/assets/img/`.

So today there are two workable approaches for featured images, and one trap:

* **Per-client theme images** (what the shipped theme does): drop the client's
  images into `theme/assets/img/` and set `thumbnail` per item. The demo seed data
  shows the shape; you would set it by extending the theme to expose it, or by
  seeding it.
* **Curated, developer-managed imagery**: keep a small set of approved images in
  `theme/assets/img/` and expose a `select` schema field on a listing component
  so the editor picks from them.
* **Trap:** uploading a new image through the Media screen and expecting it to
  work as a post thumbnail. Media-library URLs are built differently
  (`media_url()` / `picture()`); a bare media filename in `meta.thumbnail` is
  resolved as a theme asset and will 404.

If the client must choose per-post images from the media library, that is a
deliberate theme change: have the layout read the field with `picture()` or
`media_url()` instead of `img()`. Decide it once, per project, and keep it
consistent across the post layout, the archive layouts and the preview sections.
* **`page` with nested parents** — About → About/Team, Services → individual
  services, and other hierarchy the sitemap already has.
* **Categories and tags** — sectioning for blog and portfolio, with archive
  layouts.
* **Forms** — `contact` and `newsletter` are declared in `form_types`; add fields
  there if the template's form has more (company, budget, file upload is not
  supported).

### Decide components by reuse, not by page

A component earns its place when it appears more than once, or when the client
will want to reorder/remove it. A one-off "Our Story" block with fixed prose is
better as a `quill-editor` inside a `policy-section`-style wrapper than as a
bespoke component with five fields nobody will touch.

Write the map as a table before writing PHP:

| Page | Sections (in order) | Component | Editor-editable fields |
| --- | --- | --- | --- |
| Home | Hero | `hero-section` | heading, sub, CTA label/url, image |
| Home | 3 services | `features-section` + `feature-card` | icon, title, text |
| Home | Testimonials | `testimonial-section` | quote, name, role, image |
| About | Team | `team-section` + `team-member` | photo, name, role, bio |
| … | … | … | … |

### Menus and global settings

* `menu_locations` in `theme.php` defines the slots (`main`, `footer`, plus
  `legal`, `social`, …); Settings assigns a menu to each slot.
* Decide the URL prefixes per content type (`blog`, `portfolio`, or the client's
  `news`, `work`) before content is loaded — changing them later changes URLs.
* Decide the homepage (`homepage_id`) and the default layout/header/footer.

## 5. Stage 3 — Theme build

A client theme is small: a manifest, a handful of layouts, the client's chrome and
sections as components, and their CSS/JS as assets.

```
theme/
  theme.php          manifest: layouts, content types, form types, assets
  layouts/           one per page shape (default, blog, portfolio, landing, …)
  components/        one file per section + site-header + site-footer
  partials/          shared includes (e.g. archive CSS)
  assets/
    layout.css       page scaffolding
    utilities.css    the shared class layer (treat class names as public API)
    style.css        design tokens (--theme-primary, fonts, spacing) + element rules
    main.js
    img/ fonts/ vendor/
```

Rules that keep it sane:

1. **Design tokens first.** Put the template's palette, fonts and spacing into
   `style.css` `:root`, then convert the template's CSS to use them. This is what
   makes section styles, and future client changes, cheap.
2. **One component per section, CSS in the component.** Component CSS is injected
   only on pages that use it and after the theme stylesheets, so it can override
   cleanly. Keep shared rules in `utilities.css`, component rules in the
   component.
3. **Strip inline `<style>` blocks and inline event handlers** from the template
   markup during conversion. The admin suite already forbids inline styles in
   admin; hold the front end to the same bar.
4. **Conform to the component contract** documented in
   `core/components/sample-component.php` and Admin → Docs → Theme developer
   guide: `label`, `schema`, `children`, `allowed_children`, `css`, `js`,
   `render`. The suite checks it (`tests/theme.test.php`).
5. **Never edit `core/`.** If a client need appears to require core changes, that
   is a product decision for `plan.md`, not a project hack. Keep the upstream CMS
   clean so `git pull` stays possible.
6. **Always `e()` output.** Read optional props defensively (`$props['x'] ?? ''`).

### Build order that avoids rework

1. **Header and footer components** + one layout rendering them, so every page has
   chrome from day one.
2. **`style.css` tokens** distilled from the template.
3. **Hero, features, CTA** — the components every page reuses.
4. **The remaining sections**, one at a time, checking each in the editor.
5. **Archive layouts** (blog, portfolio, taxonomy) and the blog/portfolio layouts.
6. **Forms** — fields in `form_types`, markup in a `contact-section` component.
7. **404 and search layouts** last; they are usually template pages you can drop.

### Verification during the build

* `php tests/run.php` after each batch — the fastest signal, and it enforces the
  component contract, no-CDN rule and asset ownership split.
* Local site for the visual check:
  `CMS_CONFIG_FILE="$PWD/tests/config.server.php" php -S 127.0.0.1:8080 tests/router.php`
* Check in the editor (not just the front end) that every component field is
  editable and every allowed child appears in the palette. A component that is
  not listed in `available_components` cannot be added by the client.

## 6. Stage 4 — Content load

### Start the install clean

On a fresh install the CMS seeds demo content, a `demo`/`demo` user and a sitemap.
Before loading client content:

* Change or delete the `demo` user; create the client's own account with the role
  they need (`administrator`, `editor`, `author`).
* Delete or repurpose the seeded demo pages, posts, portfolio items, menus and
  media. Leaving them is the single most common launch embarrassment.
* Set `site_title`, `site_url`, `homepage_id`, contact email, media sizes and
  prefixes in Settings before bulk-adding content.

### Getting content in

There is no importer, and none is planned. Realistic routes:

* **Copy/paste** into the rich-text editor for prose. Body text is stored as
  HTML; the Quill editor is the paste path.
* **Build the skeleton yourself, then hand over.** Create every page with its
  correct slug, parent and component structure, with placeholder content. The
  client fills in text and uploads images. This is usually the fastest and gives
  the best result.
* **Spreadsheet-driven entry** for many similar items (team, services, listings):
  enter them in the editor; there is no CSV import.

### Content discipline that pays off on retainer

* Agree naming conventions with the client: page titles, category names, media
  alt text. Alt text matters — `picture()` renders it.
* Keep the media library tidy by uploading via the Media screen, not by dropping
  files into `storage/media/`. Variants, WebP and LQIP are generated on upload.
* Put legal and policy pages in a dedicated nested section and use the `policy`
  layout.
* Use **Draft** for work in progress and the token **preview** link for client
  review — never publish a half-built page to a public URL to show it.

## 7. Stage 5 — Launch

Work top to bottom; each line is a real failure mode seen in this stack.

**Code and config**
* [ ] `config.php`: `env => 'production'`, correct `url` (subfolder installs
      must set it, or `url()` builds wrong links).
* [ ] Set a random `security.form_secret` per client. Left `null`, it is derived
      from the install path; a per-site secret is better and takes one edit.
* [ ] Make `config.php` read-only after setup finishes — the installer rewrites
      it via `update_config_value()`, and nothing else needs to.
* [ ] `storage/` writable by the web user; `core/` and `storage/` blocked by
      `.htaccess`; `theme/assets/` reachable; everything else routed to
      `index.php`. On the client's host, confirm `.htaccess` is honoured.

**Correctness**
* [ ] Admin → Health: every check green.
* [ ] `php tests/run.php` on the deploy checkout — all suites passing.
* [ ] No CDN references, no inline `<style>`, no template demo markup left.
* [ ] Demo content and demo user gone; sitemap regenerated.
* [ ] Every form configured with the client's notification address, and a live
      submission test received.
* [ ] `robots.txt` and `sitemap.xml` correct for the live domain; `site_url`
      matches it.
* [ ] Check the export/static path only if it is part of the deliverable.

**Quality**
* [ ] Titles and meta descriptions filled in; canonical and Open Graph checked on
      home, a blog post and a portfolio item.
* [ ] Images: alt text present; WebP generated; the page weight is sane.
* [ ] Mobile pass on the real template breakpoints; the header dropdown and any
      accordions behave.
* [ ] Signed-out read-through of every page, plus 404 and search.
* [ ] Cached page check: load as an anonymous visitor twice and confirm the second
      is served from cache; then edit content and confirm the page updates.

**Handover**
* [ ] Client account created with the right role; password delivered securely.
* [ ] Backups: a fresh backup downloaded and stored where the client can find it
      (or where you will).
* [ ] Retainer terms and response time agreed in writing.

## 8. Stage 6 — Handover

* **Logins and roles.** `administrator` (full), `editor` (all content,
  taxonomies, media, menus, redirects), `author` (own content). Give the client
  the least role that lets them do their job.
* **A one-page runbook** for the client: how to edit a page and its sections, how
  to add a post, how to upload an image and set alt text, how to use the trash,
  where the version history is, and who to call. The in-app guide at Admin → Docs
  covers the mechanics — the runbook should cover *their* site specifically.
* **Do not train on design.** Components define what the client can change. If
  they want a new section, that is a billable change; say so at handover.

## 9. Stage 7 — Care and operations

| Cadence | Task |
| --- | --- |
| On client request | Content changes, new images, new posts |
| Weekly | Review form submissions (Admin → Forms); add CSV export to the routine when it lands |
| Monthly | Download a backup; check Health; check the activity log for unexpected logins |
| Quarterly | Update from upstream CMS: pull, run `php tests/run.php`, deploy |
| Per change request | New component or layout in `theme/`; never a core edit |

Operational facts that shape the retainer:

* **No in-app updater.** Updating means deploying new core files. Always run the
  suite first. If a client theme diverges from the contract, the suite is what
  catches it.
* **Trash is soft delete** with a retention window (`config.php` → `trash.
  retention_days`, default 30). Deletions are recoverable, which is a selling
  point and a reason not to panic-restore from backup.
* **Cache:** `config.php` → `cache_lifetime`, default 3600s. Every write path
  that matters already calls `invalidate_cache()`. If a change does not appear,
  Utilities → Clear Cache is the first diagnostic.
* **Retention:** `activity.retention_days` (180) and `versions.keep` (20) are
  per-install config; raise version retention for clients who edit carelessly.
* **SQLite** is a single file and fine at this scale. It serialises writers —
  irrelevant for a small brochure site, worth remembering if a client ever
  expects heavy concurrent editing.

## 10. Commercial shape

Indicative effort for a small client site (5–10 pages), to be replaced by your own
measured numbers after two or three builds:

| Stage | Effort |
| --- | --- |
| Template inventory + content map | 0.5–1 day |
| Theme build (converting a template) | 3–6 days |
| Content load | 1–2 days |
| Launch + handover | 0.5–1 day |
| **First site** | **~7–10 days** |
| **Each later site** | **3–5 days**, because the theme manifest, tokens, header/footer, contact section and launch checklist carry over |

The compounding asset is the **agency starter theme**, not the upstream CMS: a
trimmed token set, a header/footer with your menu conventions, a contact/newsletter
section, and two or three generic section components. Every client then starts
from a working site instead of an empty folder. Keep it free of any one client's
branding.

## 11. Where this workflow exposes gaps

Honest list of what the pipeline above has to work around, and what it would take
to fix. Each row names an owning document.

| Gap | Impact on the pipeline | Where it belongs |
| --- | --- | --- |
| No starter theme shipped | Stage 3 starts from a full business template or an empty folder | `continuing-plan.md` B2 — closes this directly |
| No manifest integrity check | A typo in `available_components` or a missing layout reaches a client's live site | `continuing-plan.md` B1 — Health catches it before launch |
| Manual `?v=` asset counters | A CSS fix after launch can silently keep serving from browser cache | `continuing-plan.md` B3 |
| No archive pagination | A client blog with more than a screenful renders everything on one page | `continuing-plan.md` C1 |
| Post/portfolio presentation meta has no editor fields | Featured images are a theme concern per project (see §4) | Not planned; a per-project theme decision, or a `plan.md` item if it recurs |
| No content duplication | Building repeated page structures is slower than it should be | `continuing-plan.md` C2 |
| No maintenance mode | Deploying without visible downtime is awkward | `continuing-plan.md` C3 |
| Static export drops archives, sitemap and robots | An exported brochure site is more limited than expected | Not yet planned; would be a small `plan.md` item if the static deliverable matters |
| No CSV export for submissions | Mail-merge handoff is manual | `plan.md` 14 / `continuing-plan.md` C4 |
| No RSS feed | Newsletter/aggregator clients have no feed | `continuing-plan.md` C5 |

Explicitly **not** wanted, because they would turn this into a different product:
multisite/theme switching, a visual builder for clients, and a WordPress importer.
The manual content route plus the starter theme is the deliberate trade.

## 12. First-project checklist

Ordered for the first client build, so the reusable pieces get created along the
way.

1. Put the upstream CMS in a private `agency-kit` repo; keep `storage/` and
   `config.php` out of it.
2. Do `continuing-plan.md` B1 and B2 before the second client, so every build
   starts validated and from a starter.
3. Take one real Envato template all the way through stages 1–5 on a throwaway
   install, and time each stage.
4. Extract everything generic from that build into the agency starter theme.
5. Write the one-page runbook template while the first handover is fresh.
6. Only then take a paying client build, using the measured timings.
