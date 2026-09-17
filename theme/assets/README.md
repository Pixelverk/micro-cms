# Theme assets

No build step, no bundler, no CDN. Everything the theme needs ships in this
folder.

## Stylesheet layers (order matters)

`theme/theme.php` loads them in this order:

| Order | File | Owns |
| --- | --- | --- |
| 1 | `layout.css` | Page scaffolding (sticky footer, full-height body) |
| 2 | `utilities.css` | The shared class layer |
| 3 | `style.css` | Theme tokens and theme-wide element rules |
| 4 | `vendor/bootstrap-icons/bootstrap-icons.css` | Icon font |
| 5 | *(injected)* | Per-component CSS, collected by `core/render.php` |

Component CSS is injected after the stylesheets, so a component can always
override the shared layer on equal specificity.

### `utilities.css` — the shared class layer

A hand-written, Bootstrap-compatible subset: `.container`, `.row`,
`.col-lg-*`, `.gx-*`, spacing (`.py-5`, `.mb-3`, …), flex helpers, `.card`,
`.btn`, `.form-control`, `.navbar`, `.dropdown`, `.accordion`, `.badge`.

**These class names are a public API.** Components reference them directly, so
renaming or removing one is a breaking change. Add new helpers here only when
more than one component needs them.

### `style.css` — the theme surface

Design tokens (`:root` custom properties), the base reset, typography and
element defaults. Keep it free of layout utilities (those go in
`utilities.css`) and component-specific selectors (those go in the component).

### Component CSS

Each component declares its own styles in the `'css'` heredoc of its
`theme/components/<name>.php` file:

```php
'css' => <<<CSS
.my-section .my-element { ... }
CSS,
```

It is only shipped on pages that actually render the component. The same
applies to `'js'`, which runs inside a `DOMContentLoaded` handler.

Layouts can contribute CSS too, via `collect_css()`:

```php
collect_css($collectedCss, 'layout:my-layout', <<<'CSS'
.my-layout { ... }
CSS);
```

`collect_css()` de-duplicates by key, so a shared partial can be included from
several layouts. See `theme/partials/taxonomy-archive.css.php`.

## Images

`picture($mediaId, $attrs)` renders a responsive `<picture>` with a WebP
source, `srcset`/`sizes`, a LQIP background and `alt` text from the media
record. `media_url($id, $width, $format)` returns a plain URL and works for
non-image media (PDF, MP4) too.

Images smaller than the configured target widths simply have fewer variants;
`picture()` renders whatever exists.

## Vendored third-party code

Only the admin editor needs external libraries, and they are committed under
`admin/assets/vendor/`:

* `quill/` — Quill 2.0.3 (rich text), MIT
* `sortable/` — Sortable 1.15.0 (drag and drop), MIT

Each keeps its upstream `LICENSE`. To upgrade, replace the files and update
the version note above; there is no package manager involved.
