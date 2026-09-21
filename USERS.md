# User guide

For the people who run the site: editors, authors and administrators. It covers
signing in, writing and publishing content, media, menus, forms, taxonomies,
settings and the utilities — the same material as the in-app **Editor guide**
(`/admin/docs`), with the operator pages added. For building a theme, see
`THEME_DEVELOPERS.md`.

---

## 1. Signing in

Open `/admin/`. An administrator creates your account under **Users** and tells
you the username and password; change the password from your account menu the
first time.

* Forgot your password? **Forgot password** emails a one-time reset link. The
  link expires, works once, and a limited number of requests per address is
  allowed.
* Repeated failed logins lock the account for a short while, so guessing is
  slow.
* A fresh install ships one demo account per role — `admin`/`admin`,
  `editor`/`editor`, `author`/`author`. Change those passwords on a real site.

### Roles at a glance

| Role | Can do |
| --- | --- |
| **Administrator** | Everything: content, media, taxonomies, menus, forms, users, settings, activity. |
| **Editor** | All content including publishing and deletion, plus media, taxonomies, menus and the forms inbox. |
| **Author** | Their own content: write, save drafts and preview. Cannot publish, delete, or manage media. |

The last remaining administrator cannot be demoted or deleted.

---

## 2. Getting around

The sidebar is the map of the admin: **Welcome**, **Content**, **Collections**
(your taxonomies — Categories and Tags by default), **Forms**, **Site** (media,
menus, redirects), **Reports** (analytics, activity, health), **System**
(settings, users, utilities) and **Help** (documentation). Anything your role
cannot use is hidden, and a group disappears when it has nothing to show.

The top bar names the page you are on. On the right: a link to the live site,
the help button (per-page notes), the light/dark switch, and your account. On a
narrow screen the sidebar becomes a drawer.

---

## 3. Creating and editing content

Open **Content**, choose the type (Page, Blog Post, Portfolio Item), then
**Add**. The editor has the content in the middle and the details on the right.

* **Title and slug.** The slug is the URL. It is suggested from the title and is
  editable.
* **Components.** *Add component* opens the library; each tile says what the
  component is for and what it looks like. Drag a component by its title bar to
  reorder it, or use its toolbar to duplicate or remove it. Some components
  accept children — drop them into the parent's inner area.
* **Written types.** Blog Posts and Portfolio Items are written as one rich-text
  field, not assembled from components. Pages are assembled from components.
* **Parent.** Nest a page under another to build a URL such as
  `/services/consulting/`.
* **Images.** A Blog Post has a Featured image; a Portfolio Item also has a
  Gallery. Pick from the media library or paste a full URL. *Clear* empties the
  slot; a section image falls back to the placeholder box. A gallery keeps the
  order you add images in.
* **Details.** Depending on the type, extra fields such as an excerpt or a
  project link appear in the Details card.

Save at any time. Nothing is public until the status is **Published**.

---

## 4. Taxonomies

Taxonomies are the site's classifications — **Categories** and **Tags** by
default, and a theme may declare more (Topics, Formats, whatever it needs).
Manage them from **Collections**: one link per taxonomy, each with its own term
list. Which content types use a taxonomy is shown on its page ("Used by …").

* A taxonomy either allows **one term per item** (Category) or **many** (Tags);
  the editor shows the matching control.
* Terms are **shared**: a term can be used by every content type that offers its
  taxonomy.
* A term has a name, a slug (its archive URL) and an optional description.
* The archive page for a term lives at the taxonomy's URL prefix, e.g.
  `/category/news/`.
* Renaming a term's slug keeps the old archive URL working with a redirect.
* Deleting a term never deletes content: the items are kept and simply lose the
  term.

---

## 5. Statuses, scheduling and preview

| Status | Who sees it |
| --- | --- |
| **Draft** | Only signed-in editors, and anyone with a preview link. |
| **Scheduled** | Hidden until the date and time you choose, then published automatically. |
| **Published** | Live on the site. |
| **Archived** | Kept in the admin, hidden from visitors. |

* **Preview** opens the real page as it will look, including unpublished
  changes. Preview pages are never cached and never indexed, so the link is
  safe to share with a colleague.
* While you edit, the CMS **autosaves** a draft about once a minute. If the tab
  closes, the next visit offers that draft back into the editor; only a normal
  save updates the page.
* Publishing is **blocked** while a required component field is empty: the save
  is kept as a draft and the **Pre-publish checklist** says what is missing.
  Missing image descriptions, dead links and a missing meta description only
  warn.
* An **Author** can write and preview but cannot publish or delete.

---

## 6. Versions and undo

Every meaningful save stores the previous version. **History** in the editor
shows what changed, compares any two versions (or a version with the current
state) as a line-by-line diff, and restores one. A restore is itself undoable:
the state you replace is saved to history first.

---

## 7. Deleting and restoring

Deleting moves an item to the **trash** instead of removing it. It leaves the
site and the Content list but keeps its version history and can be brought back
from the **Trash** tab. Trashing a page also trashes the pages nested under it.

* Trashed items are purged automatically after `trash.retention_days` (30 days
  by default).
* **Delete permanently** in the Trash tab removes one item now; **Utilities →
  Clear Trash** empties the whole trash. Neither can be undone.
* **Utilities → Media library** and **Broken links** are read-only scans: they
  report files with no row, rows with no file, and internal links that no longer
  resolve. Only the media scan offers a repair, and it keeps a folder that any
  content, setting or menu still references.

---

## 8. Media

Upload images, PDFs or video in **Media**. Give each image **alt text** so it is
accessible and searchable, and an optional description.

* Uploads are resized into several widths and converted to WebP where possible,
  with a JPEG or PNG fallback; the original is kept.
* Selecting a file opens an inspector with the preview, name, size, upload date,
  alt text, description and an optional replacement file.
* **Choose size/format** lists every stored variant with its pixel dimensions,
  and **Copy URL** puts the chosen one on your clipboard.
* Deleting a file that content still uses would break that content, so the
  confirmation names what uses it — content, settings and menus — first.
* Media has a search box (name, alt text or description).
* **Authors** may pick from the library but cannot upload or delete.

---

## 9. Bulk actions

Tick the boxes on the left of a list to reveal a toolbar.

* **Content:** publish, draft, archive, delete (to trash), clear cache, or add
  or remove a taxonomy term across the whole selection.
* **Media:** delete several files at once (permanent, so it confirms first).
* **Taxonomies:** delete several terms at once; content that used them is kept.
* Authors can only bulk-edit their own items; anything else is skipped and
  reported.

---

## 10. Finding things

The public **site search** covers titles and body text. In the admin, the
content list has its own search box, and the status tabs filter by draft,
scheduled, published, archived or trashed. Media has a search box; the activity
log filters by action, object, author, period and free text; the redirect list
searches both the old and the new path; and Documentation has a filter that
hides sections which do not match.

---

## 11. Menus

A menu is a list of links — your content, a taxonomy archive, or a custom URL —
that the theme prints in a location such as the main navigation or the footer.

* Open **Menus** and pick a menu from the dropdown, or choose "New menu" and
  name it in the Menu Label field.
* Tick the **locations** the menu should fill. A location holds one menu at a
  time: ticking a location another menu holds moves it here, unticking releases
  it.
* **Add items** from the sidebar: choose a kind (pages, blog posts, portfolio
  items, taxonomy archives) then the item, or type a custom URL. Each row has a
  label, a target (same tab or new tab) and an eye button that hides it and its
  children from the site. Drag a row by anything except its fields and buttons
  to reorder, and use the child action to nest one level deep.
* A content link follows a rename, so moving a page does not break its menu
  link. A link whose page was deleted, trashed or unpublished is marked broken
  and renders as plain text rather than a dead link.
* Deleting a menu leaves its locations empty until another menu is assigned.

---

## 12. Form submissions

Contact and newsletter forms are configured in the theme; their submissions
appear under **Forms** in the sidebar (one entry per form type).

* Filter by form, by status and by free text. Click a row to read the submitted
  answers.
* Each submission has a workflow **status**: `new`, `waiting`, `handled` or
  `spam`. Change it from the row's select, or apply one status to several ticked
  rows at once.
* **Export CSV** downloads every submission, or just the current filtered view.
* Deleting submissions is permanent and can be done in bulk.
* Notifications go to the address(es) set in **Settings** for that form.

---

## 13. SEO and sharing

The **SEO & Social** panel on each item controls the browser title, the
description search engines show, the canonical URL, and the image and text used
when the page is shared. Leave a field blank to inherit a sensible default. The
panel previews itself — a search result and a social card — and follows the
fields as you type.

* **Author** is the writer shown on a post and in the article metadata.
* **Twitter/X creator** is the writer's own handle, when it differs from the
  site account.
* Site-wide defaults (title suffix, social image, handle) live under
  **Settings → SEO and social**; anything set on an item overrides them.

---

## 14. Redirects

Redirects keep old URLs working when a page moves.

* Add one row: the old path, the new path, and whether it is permanent (301) or
  temporary (302).
* The **recent 404s** list shows paths visitors asked for that do not exist;
  "Redirect this" fills the form for you.
* Renaming the slug of a **published** page creates its 301 automatically.
  Renaming a page back removes the entry that would now hide it, and publishing
  a page on a redirected path clears that redirect, so a live path is never
  shadowed.
* A redirect is refused when it would take a working URL away — a path the CMS
  serves (admin, media, search, the form endpoints, `sitemap.xml`,
  `robots.txt`), a live page or archive, a path that already redirects, or one
  that would make a loop.
* **Check redirects** finds chains, loops, self-targets and entries that cannot
  work, and can remove them together. Saving a redirect clears its cached page,
  so it applies immediately.

---

## 15. Users and your account

Administrators manage accounts under **Users**: add a user with a username,
email, role and password; edit details, role or password; or remove a user. The
last administrator is protected.

Your own account is the **account menu → Profile**, which opens your user page:
change your name, email, password and admin language there. A password change
signs out any session opened with the old password.

---

## 16. Settings

**Settings** is grouped by what you are changing: Site, Editor account, Layout,
SEO and social, Media uploads, Custom code, Maintenance, and the URL prefix for
each content type. Related fields sit side by side, with the Save button at the
top right. Saving settings clears the page cache.

* **Site URL** matters most: set it to the site's real address so canonical
  URLs, the sitemap and social sharing are correct. Leave it blank and the CMS
  works it out from the request.
* **URL prefixes** — change one only on a site that is not yet public, or with
  redirects ready: links to the old paths will otherwise break.
* **Custom code and custom CSS** are written into every public page exactly as
  typed, so treat them as trusted-administrator-only input.
* **Maintenance mode** closes the public site (a 503 for visitors) while
  signed-in editors keep working, so the site can be finished or fixed while it
  is down.

---

## 17. Utilities

Utilities groups its actions by what they touch. Red buttons act on data that
cannot be brought back.

**Maintenance** (reversible)

* *Clear Cache* — removes all cached pages; they rebuild on the next visit.
* *Warm Cache* — renders every published page into the cache ahead of visitors.
* *Regenerate Sitemap* — rebuilds `sitemap.xml` (including taxonomy archives).

**Content and data**

* *Publish Due Content* — publishes anything past its scheduled time (the site
  also does this automatically from time to time).
* *Clear Trash* — permanently deletes everything in the trash. **Not
  reversible.**
* *Reset Analytics* — deletes all recorded page views. **Not reversible.**
* *Media library*, *Broken links* — read-only scans (§7).
* *Rebuild Search Index* — reindexes search text, useful after an upgrade.

**Export and system**

* *Export Static Site* — downloads the cached pages, theme assets and media as a
  zip, for static hosting.
* *Download Full Backup* — downloads the whole site (code, database, media
  library, sitemap) as a zip, for moving to another server.
* *Run Migrations* — applies schema updates after upgrading the code.
* *Content package → Export/Import* — moves content between sites: a
  `content.json` (pages, posts, portfolio items, taxonomy terms, menus) and a
  `settings.json` (the settings that describe the content). Import previews
  exactly what would change before you confirm, and can load the theme's demo
  content.

Export and system actions need the PHP `zip` extension; without it the CMS
falls back to Phar, and if neither is available those buttons are disabled.

### Moving the site to another server

Unzip the backup into the new host's web root, make `storage/` writable by the
web server user, then in `config.php` set `url` to the site's address (empty for
a domain root), `env` to `production`, and leave `setup_completed` true. The
archive's `BACKUP-README.txt` repeats these steps. There is deliberately no
upload-and-restore button: a wrong database would brick the site, so restore is
a step you take yourself. To move content rather than an installation, use the
content package.

---

## 18. Reports

* **Analytics** shows page views, unique visitors and the share served from the
  HTML cache, each compared with the period before it. Switch the range between
  30 days, 6 months and 1 year; the chart, top pages and top referrers follow.
  Bot traffic is not counted, and no IP addresses are stored — visitors are
  counted through a hash that changes every day.
* **Activity log** shows who changed what, when. Entries are written
  automatically and cannot be edited.
* **Health** checks PHP and its extensions, that storage is writable, that the
  schema is current, that production settings are safe, and that the theme
  manifest resolves. It only reads; fix what it flags before it becomes a blank
  page or a silently uncached site.

---

## 19. Documentation

**Help → Documentation** is the in-app guide, split into an **Editor guide**, a
**Theme developer guide** and a **Reference** of statuses, roles and
configuration. The filter at the top hides sections that do not match. The same
material, written for reading offline, is in `THEME_DEVELOPERS.md` (building a
theme) and this file.

---

## 20. Things worth knowing

* **Nothing is public until it is Published** (or Scheduled and due).
* **Delete means trash**, not gone — until you empty the trash.
* **Renaming a slug** keeps the old URL working with a redirect, once the page
  has been public.
* **Changing a URL prefix** in Settings breaks existing links unless redirects
  are ready.
* **An Author cannot publish**; ask an Editor or Administrator.
* **Required component fields block publishing**; the checklist says which.
* **Preview links are safe to share** — they are not cached or indexed — but
  they need a signed-in editor with the matching token.
