# Micro CMS

A lightweight PHP CMS, built with components for front-end design and page editor for content management.

Ideal for small websites, demos, or projects where speed, simplicity, and maintainability matter.

The goal is to have a good user experience, both for developers and content-editors, with as few moving parts as possible.

---

## Project Structure

* `_components/` – Custom PHP components for your pages
* `_assets/` – Global CSS, JavaScript, and images
* `_data/` – Settings and user data in JSON storage
* `_data/pages/` – JSON page data (one file per page)
* `editor/` – Admin panel for creating/editing pages and managing users

---

## Workflow

1. Build your components and place them in `_components/`.
2. Add global CSS and assets in `_assets/`.
3. Open the editor at `/editor/` to add or edit pages and components.
4. Save changes—pages update instantly.

**Example:** create a `hero-section` component, then add it to the homepage in the editor.

---

## Components

A component is a folder with three files: body.php, style.css and script.js.

These component files will be parsed and combined when a page renders.

The CSS ends up in a style tag within the page head.

The JS ends up in a script tag that runs after DOMContentLoaded.

The body.php decides:
* how the component html is rendered
* which attributes are editable in the CMS
* which child elements are allowed (if any)

---

## Local Development

Run a local PHP server:

```bash
php -S localhost:8000
```

All requests go through `index.php` in the root. Pages are dynamically built from JSON files in `_data/pages/`.

Demo account:

* Username: `demo`
* Password: `demo`

The demo account is created automatically if no other users exist.

Access the editor at `/editor/` to manage pages and users.

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
* Blog CRUD and categories
* Menu manager / use in component ✅ 
* Editable page slugs ✅
* Improved editor UI/UX/Responsiveness
* Write help for all pages

### Phase 4 – Media & SEO

* Working contact form
* Image uploads / media manager
* Sitemap generator (XML)
* Optional SEO enhancements (meta, OpenGraph, etc.)

### Phase 5 – Refactor & Optimization

* Refactor codebase for maintainability and performance

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