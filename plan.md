# Micro CMS — plan

A living backlog for a CMS that stays procedural PHP over SQLite with no build
step and no packages. See [`continuing-plan.md`](continuing-plan.md) for the
phased programme across the CMS, theme building and running a site; this file
remains the item-by-item backlog. Each idea is judged on two questions: does it
solve a problem that exists today, and can it be done with what is already here
(PDO/SQLite, Imagick, plain PHP)? The inspiration comes from WordPress, Joomla
and Squarespace, but their weight does not.

Completed work (header/footer scripts, cache warm-up + static export, built-in
analytics, multi-language admin, redirects, trash, site health, virtual
robots.txt) has been removed. The multi-language front end stays as a deferred
design.

## Next up

Nothing is queued here. The Later list below is the backlog, roughly in value
order; the publish webhook (12) is the one most likely to matter next.

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
