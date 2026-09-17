# Micro CMS — plan

A living backlog for a CMS that stays procedural PHP over SQLite with no build
step and no packages. Each idea is judged on two questions: does it solve a
problem that exists today, and can it be done with what is already here
(PDO/SQLite, Imagick, plain PHP)? The inspiration comes from WordPress, Joomla
and Squarespace, but their weight does not.

Completed work (header/footer scripts, cache warm-up + static export, built-in
analytics, multi-language admin) has been removed. The multi-language front
end stays as a deferred design.

## Next up

### 1. Backup download

**Why:** there is no way to take the content with you; the static export is
pages, not data. Every WordPress and Joomla host offers a backup export, and it
is the cheapest insurance against a bad host move.

**Sketch:** a Utilities button that zips `data.sqlite` (via `VACUUM INTO` for a
consistent copy), `media/`, `sitemap.xml` and the cache, streamed and discarded
like the static export. Exclude `config.php`, which holds the form secret.

**Effort:** S.

**Verify:** build a backup, open it and assert it contains the database and
media; keep the existing `ZipArchive` guard test. Restore stays manual and
documented — an admin-uploaded database can brick a live site.

### 2. Trash (soft delete)

**Why:** deleting is permanent today and takes the version history with it.
WordPress and Squarespace both keep a trash.

**Sketch:** `deleted_at` on `content`; `content_visibility_sql()` and the
listings exclude trashed rows; a Trash tab on the content list with restore and
permanent delete; purge items older than N days from the existing shutdown hook
(`publishing_check()`).

**Effort:** M.

**Verify:** trashed items leave the front end, cache and sitemap; restore brings
them back; permanent delete removes the row and its versions.

### 3. robots.txt

**Why:** there is no route for it, so crawlers never discover the sitemap.
WordPress serves a virtual robots.txt.

**Sketch:** `/robots.txt` with sensible defaults (`Allow: /` and the absolute
`Sitemap:` line) plus a Settings textarea for extra lines, administrators only.

**Effort:** S.

**Verify:** `text/plain`, absolute sitemap URL, custom lines rendered.

## Later

7. **Editor autosave** — a draft version every ~60s through the existing
   `content_versions` store (reason `autosave`), offered back on reload. Reuses
   what is there instead of new storage. (M)
8. **Front-end pagination** — blog, portfolio and taxonomy archives render
   everything today. Add `?page=N` and `rel=prev/next`, and treat paged views
   like search so they are never written to the path-only cache key. (M)
9. **Media usage before delete** — scan content bodies for a media id and show
   where it is used, the way Joomla warns before removing a file. (M)
10. **Version diff** — show what changed between two versions. Plain PHP, no
    diff library. (M)
11. **Maintenance mode** — a Settings toggle and message; visitors get 503 +
    `Retry-After`, while signed-in admins and previews keep working. (S–M)
12. **Publish webhook** — a Settings URL that receives a small JSON POST on
    publish/unpublish, so a static rebuild (the export workflow) can be
    triggered without polling. This is the distribution hook that matters most
    today. (S)
13. **RSS/Atom feed** — `/feed/` for the content types the theme marks as feed
    sources, cached like a page, with a `<link rel="alternate">` in `<head>`.
    Cheap and still consumed by newsletter tools, automation and aggregators,
    but low urgency for a site without a news habit. (S)
14. **Form submissions CSV export** — export the inbox for mailing lists. (S)
15. **Theme asset auto-versioning** — `theme.php`'s `?v=` counters are manual
    and easy to forget; stamp them by modification time like `admin_asset()`. (S)
16. **Dashboard at a glance** — recent content, activity and analytics totals on
    the landing page. (S)

## Deferred

* **Multi-language front end** — design agreed in
  [`multilanguage-plan.md`](multilanguage-plan.md); code deferred until the
  phases are scheduled.

## Considered and not planned

* **Comments** — moderation and spam need either a lot of code or an external
  service; the CMS targets sites that do not need them.
* **Front-end accounts and membership** — a second auth system, roles and
  password flows for a benefit most small sites do not need.
* **Visual page/theme builder** — developers own the theme; the component
  editor already covers content structure.
* **REST/JSON API and headless mode** — a whole public surface to secure and
  version. Revisit only if a headless use case appears.
* **E-commerce, external captcha, offsite/scheduled backups** — each needs a
  payment, spam or storage dependency that breaks the no-dependency rule.

## Notes

* Every test suite must call `test_fresh_database()` (or seed its own database)
  because `tests/admin.test.php` mutates the shared user table.
* New files under `core/` must be readable by the web server user; the health
  check (item 1) exists to make that class of failure visible.
