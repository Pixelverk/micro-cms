# Micro CMS

A lightweight PHP CMS, built with components for front-end design and page editor for content management.

Ideal for small websites, demos, or projects where speed, simplicity, and maintainability matter.

The goal is to have a good user experience, for both developers and editors, with very few moving parts.

---

## Project Structure

* `theme/components/` – Custom PHP components for your pages
* `theme/assets/` – Global CSS, JavaScript, and images
* `storage/` – Cache, uploads, and other data in JSON storage
* `storage/content/` – JSON content data (one file per content item)
* `core/admin/` – Admin panel for creating/editing content and managing users
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
* PDO extension (when using SQLite)
* JSON extension (built-in in PHP 5.2+)
* File write permissions for `storage/` (for content, cache, users, uploads)
* Apache with `.htaccess` support or another web server capable of rewriting all requests to `index.php`

---

## Local Development

Run a local PHP server:

```bash
php -S localhost:8000
```

All requests go through `index.php` in the root.

Content items are dynamically built from JSON files in `storage/content/`.

Demo account:

* Username: `demo`
* Password: `demo`

The demo account is created automatically if no other users exist.

Access the editor at `/admin/` to manage content and users.

---

## Features & Roadmap

### Phase 1 – Core Features ✅

* Front controller (`index.php`) to route requests
* JSON-based page storage
* Page CRUD (create, edit, delete)
* Add/remove components, including nested components
* Basic login / authentication
* User CRUD

### Phase 2 – Editor Enhancements ✅

* Site settings (select which page to use as homepage, etc.)
* In-app how-to guide
* Configuration file
* BASE_URL in config to handle subfolder hosting
* Button to reorder components
* Clone/duplicate existing components
* Fancy toast notifications for success/error

### Phase 3 – Live Preview & Blog

* Fancier demo site layout
* Live preview of pages
* Preview at multiple breakpoints
* Multiple content types, e.g. blog ✅
* Menu manager / use in component ✅ 
* Editable page slugs ✅
* Improved editor UI/UX/Responsiveness
* Write help for all pages

### Phase 4 – Media & SEO

* Working contact form ✅
* Image uploads / media manager
* Sitemap generator (XML) ✅
* Optional SEO enhancements (meta, OpenGraph, etc.)
* Caching .html for speedy speeds ✅
* Minify rendered output if 'env' = 'production' ✅

### Phase 5 – Refactor & Optimization

* Refactor codebase for maintainability and performance
* Figure out security and things like CSRF, form honeypot / captcha

### Future Ideas

* Integrate Alpine.js for reactive UI
* Image optimization and webp conversion with png fallback
* Lazy loading components and images
* Image placeholders, LQIP or CSS blurry blob
* Drafts or edit history with restore functionality
* Multi-language support
* Fiddle around with inline global css or maybe a critical.css file to avoid 1 blocking request
* Proper drag and drop of components
* SQLite instead of JSON
* Login history ?

---

## Contributing

Feel free to open issues, submit pull requests, or create new components to enhance the CMS.

---

## License

Naaah