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
| 4 | *(injected)* | Per-component CSS, collected by `core/render.php` |

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

Content images belong to the site, not the theme: an editor uploads them to the
media library, and `render_image($value, $attrs)` draws one.

```php
<?= render_image($meta['thumbnail'] ?? '', ['class' => 'card-img-top', 'alt' => $post['title']]) ?>
```

`$value` is a **media id** or an **absolute URL**:

* a media id renders `picture()` — a responsive `<picture>` with a WebP source,
  `srcset`/`sizes`, a LQIP background and `alt` text from the media record;
* an absolute URL renders a plain `<img>`, exactly the markup the theme used
  before, without picture()'s LQIP wrapper — main.js only un-blurs
  `.image-wrapper picture img`, so a bare `<img>` inside one would stay invisible;
* anything else — a component's `:placeholder` default, a value left over from
  somewhere — renders the CMS's placeholder box rather than a URL that 404s. A
  theme never has to carry a stand-in image.

The placeholder is a block the theme still sizes and rounds: pass `'ratio' => '1'`
(any CSS `aspect-ratio` value, default `3 / 2`) for a slot that is not 3:2. Its
styling ships from core, before the theme's stylesheets, so `.image-placeholder`
can be restyled in the theme.

The theme ships no content images: `theme/assets/icons/` holds the SVG glyphs,
`theme/assets/previews/` the component previews, and `favicon.ico`, `icon-192.png`
and `icon-512.png` at the top of `theme/assets/` are the app icons the manifest
and the apple-touch link fall back to. Reference those with `asset()`; there is
no `img()` helper, because there is no theme image filename to resolve.

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
    'image' => ['type' => 'image', 'label' => 'Section Image', 'required' => false, 'default' => ':placeholder'],
],
'render' => function (array $props, array $page) {
    echo render_image($props['image'] ?? '', ['class' => 'img-fluid']);
},
```

The `image` type gives the field the media-library picker. Its `default` fills a
new component, and it comes back when an editor clears the field; `:placeholder`
is the value that means the CMS's placeholder box, and the `render_image()` call
says what shape that box is. Only image fields are filled this way, so a cleared
text field stays empty; declare no default if an empty image should render
nothing.

## Component previews

The editor's Add component dialog shows every component this content type offers
as a tile. Two optional things make a tile useful:

* `'description' => 'One line'` in the component array, next to `label`, says
  what the component is for;
* a preview image at `theme/assets/previews/<component>.<ext>`, named after the
  component file — the same folder holds previews for a `core/components/`
  component, because it is the theme that shows them. `png`, `jpg`, `jpeg`,
  `webp` and `svg` are looked for in that order.

A component with neither still appears, on a neutral tile, so a theme with no
previews works. The Health page warns about a preview file that matches no
component and about one in a format the picker does not read.

## Icons

The theme owns its icons, the way the admin area owns its own: one SVG file per
icon in `theme/assets/icons/`, and `theme_icon('name')` inlines one where it is
used.

```php
<a href="…">Read more <?= theme_icon('arrow-right') ?></a>
<div class="feature"><?= theme_icon($icon) ?></div>
```

The helper sets `width`/`height` to `1em` and `fill="currentColor"`, so an icon
scales with the text and takes its colour (and any extra class you pass, such as
`.text-primary`). It adds `aria-hidden="true"` because every icon decorates a
label that is already on the page. `theme_icons()` returns the names, sorted.

A file is bare artwork — no `width`, no `height`, no `fill`:

```svg
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16">
  <path d="…"/>
</svg>
```

Drop one in and it is available everywhere: to a component through
`theme_icon()`, and to an editor through the `icon` schema field, which opens an
icon browser built from this folder. A name the theme does not ship renders
nothing rather than a broken box, and the name is the file name: `arrow-right`
draws `arrow-right.svg`, nothing else.

There is no icon font: a webfont costs every visitor a stylesheet listing every
glyph plus the font file, is render-blocking, and fixes the set of icons to
someone else's library. Files in this folder are the set.

## Vendored third-party code

External libraries are committed, never loaded from a CDN:

* `admin/assets/vendor/quill/` — Quill 2.0.3 (rich text), MIT. Shipped only
  when a content type can render a rich-text field.
* `admin/assets/vendor/sortable/` — Sortable 1.15.0 (drag and drop), MIT.
  Shipped to every content editor.

Each keeps its upstream `LICENSE`. To upgrade, replace the files and update
the version note above; there is no package manager involved.
