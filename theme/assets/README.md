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

Heading sizes and weights are Bootstrap's too (`h1` 2.5rem and medium, `h2`
2rem, `line-height: 1.2`). That is deliberate: the demo mirrors the Start
Bootstrap reference page for page, so a heading left to the browser default —
2rem and bold — reads as a theme bug rather than a choice.

**These class names are a public API.** Components reference them directly, so
renaming or removing one is a breaking change. Add new helpers here only when
more than one component needs them.

### `style.css` — the theme surface

Design tokens (`:root` custom properties), the base reset, typography and
element defaults. Keep it free of layout utilities (those go in
`utilities.css`) and component-specific selectors (those go in the component).

The reset zeroes margins and sets `line-height: 1.5`, but deliberately declares
no `font-family`: a rule on `*` applies directly to every element, so it would
beat the stack `body` declares and drop the site to the browser's generic
sans-serif. Set fonts on `body`, and let inheritance do the rest.

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

Render a theme image with `render_image($value, $attrs)`:

```php
<?= render_image($meta['thumbnail'] ?? '', ['class' => 'card-img-top', 'alt' => $post['title']]) ?>
```

`$value` may be a **media id**, a **theme filename**, or an **absolute URL**:

* a media id renders `picture()` — a responsive `<picture>` with a WebP source,
  `srcset`/`sizes`, a LQIP background and `alt` text from the media record;
* a filename (`600x400.png`, resolved through `img()`) or an absolute URL
  renders a plain `<img>`.

Do not call `img()` on a value that may hold a media id — `img()` treats its
argument as a theme filename, so a media id would resolve to a 404. Use
`render_image()` for anything an editor can set, and `img()` only for a file you
know ships with the theme.

`picture($mediaId, $attrs)` and `media_url($id, $width, $format)` are also
available. Use `media_url()` when an image becomes a URL rather than an element
(a CSS `background-image`, an `og:image`); it works for non-image media (PDF,
MP4) too. `resolve_image_value($value, $width)` converts any of the three value
shapes to a URL, and `$width` picks the nearest variant of a media row.

Images smaller than the configured target widths simply have fewer variants;
`picture()` renders whatever exists. An upload that was already WebP has only
WebP variants, which `picture()` uses for the fallback `<img>` too. If a row has
no recorded variants at all, `render_image()` falls back to the original file
rather than rendering nothing.

Declare the images an editor can set on a content type in `theme/theme.php`,
under the type's `images` key:

```php
'images' => [
    'thumbnail' => ['label' => 'Featured image'],
    'gallery'   => ['label' => 'Gallery', 'multiple' => true],
],
```

Each key becomes a field in the editor's Images panel and is stored in the
item's `meta` array, so a layout reads it back as `$page['meta']['thumbnail']`.
Without a declaration the meta value still renders, but only from seeded data:
there is no editor field for it.

For a component prop, use the `image` schema type and `render_image()`:

```php
'schema' => [
    'image' => ['type' => 'image', 'label' => 'Section Image', 'required' => false, 'default' => '600x400.png'],
],
'render' => function (array $props, array $page) {
    echo render_image($props['image'] ?? '', ['class' => 'img-fluid']);
},
```

The `image` type gives the field the media-library picker. Its `default` is the
theme's placeholder image: it fills a new component, and it comes back when an
editor clears the field. Only image fields are filled this way, so a cleared text
field stays empty; declare no default if an empty image should render nothing.

## Vendored third-party code

External libraries are committed, never loaded from a CDN:

* `admin/assets/vendor/quill/` — Quill 2.0.3 (rich text), MIT. Shipped only
  when a content type can render a rich-text field.
* `admin/assets/vendor/sortable/` — Sortable 1.15.0 (drag and drop), MIT.
  Shipped to every content editor.
* `theme/assets/vendor/bootstrap-icons/` — the theme's icon font. Ships
  `.woff2` only; a browser without woff2 support falls back to the system font.

Each keeps its upstream `LICENSE`. To upgrade, replace the files and update
the version note above; there is no package manager involved.
