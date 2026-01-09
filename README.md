# Micro CMS

A lightweight, fast CMS built with Web Components for flexible front-end design and PHP for simple content management.
Ideal for small websites, demos, or projects where speed, simplicity, and maintainability matter.

---

## Project Structure

* `_components/` – Custom Web Components for your pages
* `_assets/` – Global CSS, JavaScript, and images
* `_pages/` – JSON page data (one file per page)
* `editor/` – Admin panel for creating/editing pages and managing users

---

## Workflow

1. Build your Web Components and place them in `_components/`.
2. Add global CSS and assets in `_assets/`.
3. Open the editor at `/editor/` to add or edit pages and components.
4. Save changes—pages update instantly.

**Example:** create a `hero-section` component, then add it to the homepage in the editor.

---

## Features & Roadmap

### Phase 1 – Core Features ✅

* Front controller (`index.php`) to route requests
* JSON-based page storage
* Page CRUD (create, edit, delete)
* Add/remove components, including nested components
* Basic login / authentication
* User CRUD

### Phase 2 – Editor Enhancements

* Fancier demo site layout
* Site settings & configuration file
* In-app how-to guide
* Drag-and-drop / reorder components
* Clone/duplicate existing components
* layout components vs content components
* Fancy toast notifications for success/error ✅

### Phase 3 – Live Preview & Blog

* Live preview of pages
* Preview at multiple breakpoints
* Blog CRUD and categories
* Editable page slugs
* Improved editor UI/UX

### Phase 4 – Media & SEO

* Working contact form
* Image uploads / media manager
* Sitemap generator (XML)
* Optional SEO enhancements (meta, OpenGraph, etc.)

### Phase 5 – Refactor & Optimization

* Refactor codebase for maintainability and performance

### Future Ideas

* Integrate Alpine.js for reactive UI
* Image optimization and conversion
* Drafts or edit history with restore functionality
* Lazy loading components and images
* Multi-language support

---

## Local Development

Run a local PHP server:

```bash
php -S localhost:8000
```

All requests go through `index.php` in the root. Pages are dynamically built from JSON files in `_pages/`.

Demo account:

* Username: `demo`
* Password: `demo`

The demo account is created automatically if no other users exist.

Access the editor at `/editor/` to manage pages and users.

---

## Contributing

Feel free to open issues, submit pull requests, or create new components to enhance the CMS.

---

## License

Naaah