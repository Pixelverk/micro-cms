# Micro CMS

A very small, fast CMS using Web Components for front-end structure and lightweight PHP for editing and saving content. 

Designed to run on cheap Apache hosting while keeping page speed scores high.

## Workflow
1. Build your own Web Components and place them in `_components/`
2. Put global css and assets in `_assets/`
3. Open the editor at `/editor/` to add or edit pages and components
4. Save changes, and your pages update instantly

## To-Do next

### Phase 1
- [x] front controller
- [x] JSON page data
- [x] page CRUD
- [x] add/remove components
- [x] nested components
- [x] login / basic auth
- [ ] user CRUD

## Phase 2
- [ ] fancier demo site
- [ ] site settings
- [ ] config file
- [ ] how-to guide in app
- [ ] menu manager
- [ ] Drag and drop / reorder components
- [ ] Clone/duplicate existing component
- [x] Fancy toast on success/error

## Phase 3
- [ ] live preview
- [ ] live preview size breakpoints
- [ ] blog CRUD
- [ ] blog categories
- [ ] slug editing
- [ ] nicer editor UI/UX

## Phase 4
- [ ] Working contact form posting
- [ ] Image uploads / media manager
- [ ] Sitemap generator, xml?

## Phase 5
- [ ] refactor everything now that it works

### Maybe later
- [ ] Make use of Alpine.js
- [ ] Consider Alpine Ajax for cool stuff
- [ ] Image optimization / scaling / converter
- [ ] Drafts or edit history and restore
- [ ] Lazy loading components and/or images
- [ ] Multi-language support
- [ ] Fancy SEO fields, OpenGraph etc

## Local dev
Run with any PHP server, for example:

```
php -S localhost:8000
```

All requests go to index.php in root and the pages are built from there.

Page data is kept in the /pages/ folder, one JSON each.

There is a demo account with 'demo' as both username and password, it gets created automatically if no other user account exists. There will be user CRUD in the future.
