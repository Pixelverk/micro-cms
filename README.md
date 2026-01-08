# Micro CMS

A very small, fast CMS using Web Components for front-end structure and lightweight PHP for editing and saving content. 

Designed to run on cheap Apache hosting while keeping page speed scores high.

## Workflow
1. Build your own Web Components and place them in `_components/`
2. Create JSON-files for each page like `pages/fancypage.json` that use your components
3. Put global css and assets in `_assets/`
3. Open the editor at `/editor/` to edit component attributes of any page
4. Save changes, and your pages update instantly

The future goal is to just make it 1) Build components, 2) Add global styles, 3) Use the editor UI.

## To-Do next
### Components
- [ ] More components
- [ ] Demonstrate nested components
- [ ] More advanced components and forms
- [ ] Make use of Alpine.js

### Editor UX
- [ ] Drag and drop / reorder components
- [ ] Add/remove components on page
- [ ] Clone/duplicate existing component
- [ ] Live preview
- [ ] Live preview size breakpoints
- [ ] Editor styling
- [ ] Editor page list
- [ ] Editor page create/delete
- [ ] Image uploads / image manager
- [ ] Image optimization / scaling / converter
- [ ] Color pickers
- [ ] Drafts or edit history and restore
- [ ] Slug editing
- [ ] Fancy toast on success/error

### Users & Security
- [ ] User CRUD
- [ ] Proper password storage and hashing
- [ ] Auto-create demo/demo if no user exists

### Features
- [ ] Working contact form posting
- [ ] Documentation / how-to guide in app
- [ ] Lazy loading components and/or images
- [ ] Multi-language support
- [ ] Fancy SEO fields, OpenGraph etc
- [ ] Nav and menu manager
- [ ] Sitemap generator, xml?
- [x] include instant.page for preloading?
- [ ] blog index and articles + categories

### Polish
- [ ] Make it pretty
- [ ] Make it better

## Local dev
Run with any PHP server, for example:

```
php -S localhost:8000
```

The index.php in root should act like a router to serve the right files.

There is a demo account with 'demo' as both username and password, it gets created automatically if no other user account exists. There will be user CRUD in the future.