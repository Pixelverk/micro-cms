# Micro CMS

A lightweight PHP CMS, using components for modular design and page editor for content management.

Ideal for small websites, demos, or projects where speed, simplicity, and maintainability matter.

The goal is to have a good user experience, for both developers and editors, with very few moving parts.

Developers set up a theme with design and structure, cms users add and edit content (not design!).

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
* File write permissions for `storage/`
* Apache for `.htaccess` rules and rewriting requests to `index.php`.

---

## Local Development

Download the repo, have php installed, setup apache or use something like XAMPP.

All requests should go through `index.php` and the .htaccess for proper handling.

Make sure there are sufficient permissions to write files in `storage`.

Demo account:

* Username: `demo`
* Password: `demo`

The demo data and storage folder is created automatically if no data.sqlite file exists.

Access the editor at `/admin/` to manage content and users.

---

## Features & Roadmap

### Implemented

* Content CRUD
* User CRUD
* Menu CRUD
* Basic login / authentication
* Content editor, add/remove/copy components, nesting, clone
* Multiple content types, e.g. blog, services and other stuff
* Component system with theme components and core components
* Site settings (select which page to use as homepage, etc.)
* Sitemap generator (XML)
* .html cache and minification
* Contact forms with email sendout
* Image uploads / media manager
* Performance measurements
* Proper drag and drop of components
* Content categories and tags
* Utility page with 1-button tools
* Page nesting
* Scheduled publishing of content
* Image optimization and webp conversion with jpg/png fallback
* Image placeholders, LQIP stored as base64 in db

### Planned

* Fancier default site
* Fancier admin area
* CMS user documentation (in-app)
* Theme dev documentation (wiki)
* Component input - pick from media manager 
* Drafts or edit history with restore functionality
* Lazy loading components and images
* Built-in analytics
* Bulk-edit actions

### Maybe

* Live preview in content editor
* SEO metadata (Meta, OpenGraph, etc.)
* Activity history, not just 'last login date'
* Multi-language front-end support
* Multi-language admin area
* Front-end search

---

## Contributing

Feel free to open issues, submit pull requests, or create new components to enhance the CMS.

---

## License

Do what you want