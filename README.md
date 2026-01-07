# Micro CMS

A very small, fast CMS using Web Components for front-end structure and lightweight PHP for editing and saving content. 

Designed to run on cheap Apache hosting while keeping page speed scores high.

## Workflow
1. Build your own Web Components and place them in `_components/`
2. Create page folders (e.g., `/home`, `/services`) with an `index.html`
3. Open the editor at `/editor/` to edit component attributes of any page
4. Save changes, and your pages update instantly

## To-Do next
### Components
- [ ] More components
- [ ] Demonstrate nested components
- [ ] More advanced components and forms
- [ ] Make use of Alpine.js

### Editor UX
- [ ] Drag and drop / reorder components
- [ ] Add/remove components on page
- [ ] Live preview
- [ ] Editor styling
- [ ] Editor page list
- [ ] Editor page create/delete
- [ ] Image uploads / image manager
- [ ] Color pickers
- [ ] Drafts or edit history and restore
- [ ] Slug editing

### Users & Security
- [ ] User CRUD
- [ ] Proper password storage and hashing

### Features
- [ ] Working contact form posting
- [ ] Documentation / how-to guide in app
- [ ] Lazy loading components and/or images
- [ ] Multi-language support
- [ ] Fancy SEO fields, OpenGraph etc
- [ ] Nav and menu manager

### Polish
- [ ] Make it pretty
- [ ] Make it better

## Local dev
Run with any PHP server, for example:

```
php -S localhost:8000
```

Visit http://localhost:8000/editor/ to log in and start editing.

Note: The .htaccess redirect of / → /home/index.html needs Apache. The included index.html in root just makes sure something loads at / during local dev.