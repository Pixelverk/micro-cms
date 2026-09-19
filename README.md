# Micro CMS

A lightweight PHP CMS, using components for modular design and page editor for content management.

Ideal for small websites, demos, or projects where speed, simplicity, and maintainability matter.

The goal is to have a good user experience, for both developers and editors, with very few moving parts.

Developers set up a theme with design and structure, cms users add and edit content (not design!).

---

## Coding style

This CMS is built in a procedural style, without any classes or objects.

Effort has been made to avoid the complexity of the modern web ecosystems.

There is no build-step, no asset bundling, no ORM, no framework, not even composer packages. 

We want to present the user with html whenever they visit an url, it's not rocket surgery.

---

## Project Structure

* `theme/components/` – Custom PHP components for your pages
* `theme/assets/` – Global CSS, JavaScript, and images
* `storage/` – Cache, logs, media uploads, SQLlite file, sitemap.xml.
* `admin/` – Admin panel for creating/editing content and managing users
* `core/` – All the things that make it go

---

## Workflow

1. Build your components and place them in `theme/components/`.
2. Add global CSS and assets in `theme/assets/`.
3. Open the editor at `/admin/` to add or edit content and components.
4. Save changes and pages should update instantly.

**Example:** create a `hero-section` component, then add it to the homepage in the editor.

---

## Components

A component is a single file with a render function and specific css, js and editable fields.

These parts files will be parsed and combined when a page renders.

The CSS ends up in a style tag within the page head.

The JS ends up in a script tag that runs after DOMContentLoaded.

The componentName.php basically decides:
* how the component html is rendered
* which attributes are editable in the CMS
* which child elements are allowed (if any)
* CSS and JS to load only if component is on page

---

## Requirements

* PHP 8.0 or higher
* PDO module (for using SQLite)
* Imagick module (for image conversions)
* Zip support for the static export and backups: the `zip` extension (preferred) or `phar`
* File write permissions for `storage/`
* Apache for `.htaccess` rules and rewriting requests to `index.php`.

---

## Local Development

Download the repo, have php installed, setup apache or use something like XAMPP.

All requests should go through `index.php` and the .htaccess for proper handling.

Make sure there are sufficient permissions to write files in `storage`.

The demo data and storage folder will be created automatically if no `storage/data.sqlite` file exists.

Check the config.php file to make sure `setup_completed` is set to `false` for the first visit.

Access the editor at `/admin/` to manage content and users.

Demo account:

* Username: `demo`
* Password: `demo`

---

## Features & Roadmap

### Implemented

* Content CRUD for pages, blog posts and portfolio items
* Categories and tags, with archive layouts
* Nested pages
* Component editor: drag to reorder, nest, duplicate, clone
* Section components for assembled pages, rich text for written content
* User CRUD with three roles (administrator, editor, author) and capability checks
* Login/logout, password reset by emailed link, login throttling, CSRF protection on every admin POST
* Menu CRUD with drag-and-drop items
* Media manager: resized variants, WebP with JPEG/PNG fallback, LQIP placeholders, alt text
* Theme image rendering (`render_image()`): media ids become responsive `<picture>` elements, while theme filenames and URLs stay plain images
* Featured image and ordered gallery fields on the content types that render them, plus a media picker for the social image
* Site settings (homepage, per-type URL prefixes, timezone, date format, site description, logo, favicon, custom CSS, image quality, admin language)
* Header and footer script fields and custom CSS (raw, administrators only)
* Maintenance mode: a 503 for visitors while the admin keeps working
* Draft, scheduled, published and archived statuses
* Token-based preview of unpublished content
* Content version history with restore
* Editor autosave every minute, with an unsaved-changes warning
* Pre-publish checklist: required component fields block, accessibility and SEO gaps warn
* Security headers on every response (nosniff, frame policy, referrer policy, HSTS)
* Trash: deleting keeps content recoverable, with restore and permanent delete
* Activity log (audit trail) with configurable retention
* Built-in analytics: page views, unique visitors, top pages and referrers, cache-hit ratio (no IPs stored)
* Front-end search over titles and body text
* SEO metadata: description, canonical, Open Graph, Twitter, JSON-LD
* XML sitemap generator
* HTML page cache, minified in production
* Contact and newsletter forms with typed validation, select/radio fields, several notification addresses and a searchable submissions inbox
* Bulk actions on the content list
* Utilities page: clear or warm the cache, reset analytics, regenerate sitemap, publish due content, run migrations
* Static-site export: download the cached pages, theme assets and media as a zip
* Backup download: the database, media and sitemap as a zip
* In-app documentation for editors and theme developers
* Admin interface in English and Swedish

### Planned

The phased plan of record is [`plan.md`](plan.md): Wave 1 is nearly shipped —
expiry is deliberately not a feature, and Wave 2 adds depth
and Wave 3 is opportunistic. The multi-language front end is a deferred track in
the same file.

### Maybe

Smaller opportunities are tracked in Wave 3 of [`plan.md`](plan.md).

---

## Contributing

Feel free to open issues, submit pull requests, or create new components to enhance the CMS.

---

## License

Do what you want