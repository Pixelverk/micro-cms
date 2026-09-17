# Admin visual redesign — plan

Status: **complete** — four phases plus three follow-up sweeps (below). Files
changed: `admin/assets/style.css`, 26 icon SVGs, `admin/partials/header.php`,
`admin/partials/help.php`, `admin/partials/sidebar.php`,
`admin/partials/menu-editor-templates.php`, `admin/dashboard.php`,
`admin/analytics.php`, `admin/settings.php`, `admin/utilities.php`,
`admin/health.php`, `admin/redirects.php`, `admin/media/index.php`,
`admin/menu/edit.php`, the report pages whose tables now sit in cards, 17
strings across `admin/lang/{en,sv}.php`, and one test in
`tests/design.test.php`.

## Goal

Give the admin area a quieter, more modern visual language, inspired by
[Basecoat UI](https://basecoatui.com) without adding Basecoat, Tailwind, or any
other dependency.

Basecoat's relevant idea is small: **one class plus a few data attributes**,
semantic HTML, and a component's states (hover, focus, disabled, destructive)
handled in CSS rather than by composing utility classes. We already work that
way — `admin/assets/style.css` is the single stylesheet and the test suite
enforces that every class in admin markup exists in it. The first pass was
**CSS-first**; the follow-up sweep (see "Sweep 2") also changed markup where
that was what the visuals needed.

## Constraints (from the project's own tests and conventions)

* `admin/assets/style.css` stays the only stylesheet. No inline `<style>`, no
  framework, no build step, no CDN.
* Existing class names stay (`btn*`, `card`, `admin-table`, `sidebar*`,
  `field*`, `status-*`, `notice*`…). The design suite asserts both that every
  markup class is defined and that a specific list of selectors exists.
* Conditional state classes (`.notice-*`, `.status-*`, `.field-error`,
  `.empty-state`, `.off-screen`) stay, even when a static grep finds no use.
* `body.sidebar-collapsed`, `body.mobile-nav-open`, `html.dark` and the
  existing `main.js` / `content-editor.js` hooks keep working unchanged.
* Structural markup is not reorganised. Where a real defect is purely markup
  (see A4), it is fixed in place.

## Baseline

Captured with headless Chrome against the local server, at 1440px and 390px,
on 15 pages plus the login screen and the content editor. They live in
`tests/.tmp/redesign/before-*.jpg` (gitignored scratch space) and are the
"before" half of every comparison below.

Observed problems, in the order they are worth fixing:

1. **Buttons fight each other.** `button` gets a teal fill from element rules,
   `.btn-*` links get a different one from class rules, `.btn-small` is
   grey-on-grey, and `a.btn-small` overrides its own colour with
   `color: inherit`. Radii mix 4px and 6px. There is no `:focus-visible` state
   anywhere, and the icon buttons in the header scale their SVG on hover.
2. **Form controls are unstyled.** Inputs are hard `background: white` (broken
   in dark mode), get a hard-coded blue focus border, and `input[type=checkbox]`
   inherits `width: 100%`. The activity-log filter row is a row of mismatched
   select heights.
3. **The surface layer is flat and inconsistent.** Two `.cards` blocks exist.
   Cards, tables, modals, the docs TOC and the media inspector all use slightly
   different borders, shadows and radii. `tr:hover` is declared twice with
   different colours. `th` uses `#f5f5f5` instead of a token.
4. **Structural noise.** The sidebar has `border-right: 1px solid #fff` — a
   white hairline against near-black — and `.actions { justify-content:
   space-between }` splits the editor's action row so the toolbar buttons drift
   to opposite edges (visible in the content editor screenshot). Both are
   one-line fixes rather than redesigns.
5. **Status is inconsistent.** `.badge` is a 10px pill, `.status-*` is a 4px
   tag, `.status-tab` is a 999px pill; the uppercase/lowercase treatment
   differs between them.
6. **Dark mode is half-done.** Tokens flip for surfaces and text, but the
   sidebar, the Quill editor body, `th`, inputs and `.status-tab.active` keep
   light-mode colours.

## Progress

### Phase 1 — Tokens and the surface layer — **done**

`admin/assets/style.css` only; no markup, PHP or JS changed.

* Section 2 rewritten: `--surface-sunken`, `--border-strong`, the semantic
  `--danger`/`--success`/`--warning`/`--info` colours plus `-soft` variants,
  `--ring`, `--sidebar-*` and `--code-bg` colour tokens, and
  `--shadow-sm/md/lg`; radii lifted to 6/8/12px.
* `html.dark` overrides the whole ramp, including the sidebar, code blocks and
  shadows — previously only surfaces and text flipped.
* New section 2.7 gives every interactive element one `:focus-visible` ring.
* De-duplicated the two `.cards` blocks, the second `.btn-preview`/`.btn-muted`
  pair, and the `.actions` rule that was splitting the editor's action row.
* Base elements and the shell moved onto tokens: form controls (including
  `background: white`, the hard-coded blue focus border, and checkboxes
  inheriting `width: 100%`), `button`, `th`/`td`/`tr:hover`, `header`, sidebar
  (white hairline replaced), cards, tables, notices, toasts, modals, the docs
  code block and the login card.
* Fixed `.header-icon:hover`, which scaled the SVG and set `fill`, in favour of
  a colour change; the icons are stroked, so `fill` did nothing.

Verified: `php tests/run.php` → 229 passed, 0 failed. Before/after screenshots
for 17 pages plus dark-mode dashboard/content/settings, the 390px dashboard and
login; a real Tab-through confirmed `:focus-visible` on links and a click-focus
confirmed the ring on inputs (`box-shadow: rgba(0, 121, 107, 0.35) 0 0 0 3px`);
the collapsed sidebar and mobile drawer were clicked and re-screenshotted.

Deferred to later phases: the `--primary-soft` token (phase 2), unified status
shapes and table polish (phase 3), and the remaining responsive checks
(phase 4).

### Phase 2 — Buttons — **done**

`admin/assets/style.css` only; no markup change was needed.

* Section 6.1 rewritten around one base plus intent variants. `.btn` and the
  ten existing `.btn-*` classes now share the base through a repeated selector
  list, because every variant is used standalone in the markup.
* `.btn-small` is now a pure size modifier. It previously redefined its own
  colour, which meant `.btn-small btn-primary` rendered grey-on-grey and the
  `a.btn-small { color: inherit }` hack existed to paper over it.
* Variants: `.btn`/`.btn-primary` filled accent, `.btn-secondary` outlined
  surface, `.btn-muted` ghost, `.btn-preview` tinted soft,
  `.btn-info`/`.btn-warning` solid, `.btn-delete`/`.btn-danger` destructive.
  No new class names were needed — the markup already expressed intent.
* The bare `button` element rule now matches the base (radius, shadow, token
  border, 0.95rem) so the twelve class-less submit buttons keep the primary
  look, and all disabled states share one treatment.
* Fixed `.btn-preview`'s hover, which phase 1 had left as flat grey.

Verified: `php tests/run.php` → 229 passed, 0 failed. Screenshots of the table
action cluster, media upload toolbar, utilities cards, settings save and the
editor toolbars in light and dark; the table now shows a blue soft "Preview",
a neutral "Edit" and a red "Move to trash" instead of three greys.

### Phase 3 — Forms, tables and status — **done**

* Field groups breathe (`margin-bottom` 1.25rem, label 0.4rem, help 0.35rem),
  labels are semibold, and `.help`/`.field-error` share one size and rhythm.
* `.badge` and `.status` now share one base shape; the badge keeps its
  uppercase treatment and the status keeps its state colours, so a row no
  longer mixes a 10px pill with a 4px tag.
* Tables: header at 0.75rem with 0.04em tracking, roomier cells, hover only on
  `tbody`, and `tr:last-child` no longer doubles the bottom border.
* `.content-search input[type="search"]` stopped re-stating base input
  properties; it only resets `font` now.
* Base `table`/`th`/`td`/`tr:hover` element rules removed — every table in the
  admin carries `.content-table` or `.admin-table`, so they were a second,
  diverging definition of the same thing.

### Phase 4 — Responsive and dark-mode pass — **done**

* `main` gets `min-width: 0` (a flex item defaults to `min-width: auto`, which
  was letting wide tables stretch the whole page), and tables scroll inside
  their card at ≤900px.
* `.main-container` padding drops to 1.25rem (≤900px) and 1rem (≤480px).
* The content editor's 350px details column stacks below the component list:
  the shared `.flex-row` wraps and the column takes a full-width flex basis.
  `.component`/`.components-container` `min-width: fit-content` became
  `min-width: 0`, which was what pinned the editor 150px too wide.
* Measured result: no page has horizontal overflow at 390px, 768px or 1440px
  (was 819px of overflow on the content list at 390px before this phase).
* Dark mode: `--danger`/`--info` retuned so white text on the filled variants
  clears AA, and the amber/blue fills take dark text (white was 1.7:1 on
  amber). Measured across all 17 pages: light mode has one remaining sub-4.5:1
  item (a 4.48:1 badge — a rounding-level miss), dark mode has none.

### Cross-cutting fixes found by the audits

* `--text-muted` darkened to `#667085`; it was 4.47:1 on the page background.
* The editor's "Move to trash"-style actions and the menu editor's row buttons
  are template-generated and inherited the filled look of a bare `button`;
  they now share one neutral rule. The editor's icon glyphs were white on the
  light surface before this (1.05:1).
* `admin/partials/layout.php` and the other admin pages already had
  `<meta name="viewport">`; no markup change was needed anywhere.

## Sweep 2 — the visible redesign

The first pass was deliberately conservative (a token and component pass) and
read as too subtle. The follow-up changed markup where that was what the
visuals needed.

### The icon bug

All 23 SVGs in `admin/assets/icons/` hardcoded `stroke="#000000"` on every
path, plus a `color="#000000"` attribute on the root. CSS cannot override a
presentation attribute, so every icon in the admin — header, sidebar, tiles —
rendered black. Light mode hid it; in the dark theme they were black on a dark
bar. Fixed by rewriting the attributes to `stroke="currentColor"` and dropping
`color`, which is also what let the `.sidebar-icon svg path { stroke: #fff }`
special case be deleted.

### Header (`admin/partials/header.php`)

The bar previously repeated the current page name in a breadcrumb while the
page heading below repeated it again, and its right side was six identical
icon-only buttons.

* The page title now lives in the header (`$pageTitle`, which every page
  already set), with the breadcrumb as a secondary line under it.
* The site link became a labelled outline button; the account became an avatar
  disc plus username, linking to the profile.
* The collapse/expand pair was showing on desktop *and* mobile (the class it
  used to hide on mobile did the opposite); it is now hidden on mobile, where
  only the drawer toggle belongs.
* The bar is a single line: the heading truncates before the actions do.

### Dashboard (`admin/dashboard.php`)

Was eight near-identical text cards. Now a tile grid: icon disc, title and a
one-line summary, the whole tile a link with a lift on hover, grouped into
"what you manage" and "insights and system". Added a status strip with real
counts (one `GROUP BY status` query over `content`) and per-tile summaries, so
the landing page answers "what state is my site in" without a click.

### Sidebar navigation

Section headings are proper caps labels with tracking, links are one consistent
row height with a tinted active state and an accent bar in the gutter, and the
collapsed rail drops the headings and the wordmark instead of showing them
squeezed into a 7rem column.

### Mobile fixes

`hide-text-on-mobile` was defeated by `.btn-small`'s own `font-size`, so the
"View Website" label never actually hid and the account block was clipped off
the right edge at 390px. With `!important` on the label rule, a narrower header
gutter and the account name dropped below 480px, every page fits its viewport
at 390px (the editor was 16px over, the content list 29px).

### Contrast follow-through

The dark theme's danger fill was still 3.91:1 with white text (the phase-1
audit had read a stale computed colour from the editor's cloned templates, so
the real value had gone unmeasured). A dark foreground on the light red clears
AA while keeping the red saturated and distinguishable from the background.
Result across all 16 pages: light mode has one 4.48:1 item (a muted badge, a
rounding-level miss), dark mode has none.

## Sweep 3 — feedback pass

### Breadcrumb removed

It usually restated the page title directly above it ("Content" over
"Content"). The title now stands alone in the bar, and the URL-to-crumbs
mapping is gone from `header.php`. `tests/design.test.php` asserted the
breadcrumb existed; that assertion was replaced with checks for the page title
and the account block, which is what the bar is for now.

### Header icon sizes

The help control rendered `help-circle` at **26px** while dark-mode, expand
and collapse used **20px** — a real 6px difference, not an optical one.
Measured after the fix: every visible control in the bar is 20×20.

### Analytics spacing

The metrics grid, the sparkline card and the two report tables sat flush
against each other. They are now wrapped in `.analytics-sections`, which
supplies one 1.5rem section gap.

### The dashboard's language, applied across the admin

* **Section rhythm** — `.main-container > .card` gets one consistent 1.5rem
  gap, and trailing cards drop it. This is what fixed the analytics page and
  keeps redirects, health and the report pages even.
* **Report tables in cards** — activity, categories, tags, users, messages,
  redirects (both tables) and content versions now sit inside a `.card`, so
  they read as surfaces rather than floating on the page background. The
  duplicate border and top margin are removed for `.card > .table`.
* **Utility actions** — each of the nine actions on Utilities now has the same
  icon disc as a dashboard tile, with its heading beside it.
* **Health summary** — the bare status pill became a summary row with a
  clipboard icon disc and a report table in a card.
* **Empty states** — the media page's one-line "No media uploaded yet." is now
  an icon, a title and an explanatory line (new `media_empty_help` string in
  both languages).
* **Toolbars** — `.page-actions-inline` (the media search + upload forms) now
  aligns on one baseline, the file input is sized as a control rather than a
  full-width field, and the activity Filter button matches its neighbouring
  36px inputs.

### Known limitation

An in-card table at phone width squeezes its columns rather than scrolling.
The scroll container is `.content-table` itself (`display: block;
overflow-x: auto`); giving it a `min-width` so it scrolls made the *page*
wider instead (measured 585px at a 390px viewport), because that minimum
propagates out through the card. A proper fix needs a wrapper element around
each table, which is more markup churn than this pass justified. Every page
still fits its viewport at 390px, 768px and 1440px.

### Sidebar brand when collapsed

The rail showed a bare "M" pushed to the left. Two separate bugs:

* The "M" was not a deliberate mark — it was the wordmark *clipped*. The rule
  that was supposed to hide it used `font-size: 0` on the wrapper, which loses
  to the text element's own `font-size: 1.1rem`, so "Micro CMS" rendered at
  full size inside a 7rem rail and the overflow clipped it down to its first
  letter. `font-size: 0` is the wrong tool for hiding text; `display: none` (or
  real markup) is.
* It was left-aligned because `.sidebar-header` is a flex row with the default
  `justify-content: flex-start`.

Fixed by giving the brand real markup — a `.sidebar-brand-mark` ("MC") and a
`.sidebar-brand-name` — and switching between them at the breakpoint, with
`.sidebar-header { justify-content: center }` plus `margin: auto` on the brand
so it is centred whether or not the flex centring applies. The `::after: "M"`
hack is gone. Verified: collapsed the mark's ink sits 139px from each edge of
the 112px rail (that is 3x scale, so 46px/47px at 1x), and the wordmark still
shows in the expanded sidebar and the mobile drawer.

## Sweep 4 — settings, utilities and menus

These three pages were the least considered: settings rendered one `<fieldset>`
per field down a single column, utilities was nine full-width panels, and the
menu editor was built almost entirely from inline `style=` attributes.

### Settings

* The 20-odd fields are grouped into six sections (Site, Layout, SEO and
  social, Media uploads, Custom code, Content URL prefixes), each with an icon
  disc in its heading. Group membership lives in `$settingGroups` next to the
  field definitions.
* Fields sit in a two-column grid, so short settings pair up instead of each
  taking a full row. `narrow` fields (language, WebP quality, URL prefixes) take
  one track; `wide` ones (site URL, robots, media sizes, the two script boxes)
  span both; checkboxes render inline with their help underneath.
* Labels are now real `<label for>` elements tied to the input, rather than
  wrapping the input. Help text sits under the control it explains.
* URL prefix inputs are monospace and capped at 12rem — they hold a slug, not a
  sentence.
* Six new strings, both languages.

### Utilities

* The nine actions are grouped by what they touch — Maintenance, Content and
  data, Export and system — and laid out as a grid of equal cards rather than
  nine full-width panels, so the page is one screen instead of three.
* The destructive pair (Clear Trash, Reset Analytics) is visibly separated from
  the routine ones.
* Three new strings, both languages.

### Menus

* The inline `style=` attributes are gone (a `style="margin-top:1rem"` on the
  save button, `display:flex` panels with hard-coded borders, an
  `display:inline` delete form). The page now has real classes.
* The menu slug input was a visible, editable text box that the JS derives from
  the label — it is now a hidden field, and the label field carries help text.
* The delete-menu control moved out of the items panel into the page actions
  beside Save.
* Menu item rows are a grid: label and slug get the wider tracks, type and
  target the narrow ones, on a muted surface inside the panel so a row reads as
  an item rather than as another panel. A dotted grip hints at the drag handle.
* The row actions are now icon-only (add child, duplicate, remove), square and
  equal, with the label as both a tooltip and off-screen text. That needed
  three new icons — `corner-down-right`, `copy`, `xmark` — because the existing
  set had nothing that depicted those actions; the previous code used a
  text `×` and `↑`/`↓`/`⚠` glyphs.

### Also removed

`$username` was still assigned in `admin/menu/edit.php` after the heading
stopped using it.

## Sweep 5 — settings geometry, menu structure, utility colours

### Settings: widths now come from the grid alone

The reported symptoms had three separate causes, so the fix is structural rather
than a set of one-off widths:

* **Site language / Site URL looked shorter than the rest.** They were: a
  `.field-narrow`/`.field-wide` pair set `max-width: 24rem`, and because the
  grid track was already the right width, that cap only made those two inputs
  end early. Measured after removing it — Site title, Site language, Homepage,
  Site URL and Contact email are all **539px**.
* **Column count is per group.** Each group now declares `columns`, expressed as
  a `.field-grid-2`/`.field-grid-3` class (no inline style), and a field either
  takes one track or spans the row via `.field-span`. Long values — robots.txt,
  the media size list — span; everything else takes a track.
* Applied: Layout's three selects sit **on one row** (351px each). robots.txt is
  now **1102px**, the same as the other full-width fields. Custom code's two
  script boxes are **539px each, side by side**. Content URL prefixes are
  **three per row** (192px). The media checkboxes pair up — Generate WebP and
  WebP Quality on one row, Strip Metadata and Allow SVG on the next, all in the
  left column.
* `admin_default_language` moved out of "Site" into its own **"Editor account"**
  group: it is a per-editor UI preference, not site configuration, and leaving
  it in Site made that group five fields with an orphan on the second row.

### Menus: the picker follows the WordPress shape

WordPress's edit screen is *select a menu, then edit its name and items*. This
page now reads the same way:

* **Menu Location and Menu sit together** as one "which menu am I editing" row.
  The second select is relabelled "Menu" (was "Assigned Menu", which implied
  something was being assigned) and a hint line explains that a menu is edited
  in the context of a location, so the two selects no longer look like
  unrelated settings.
* **Menu Label has its own panel** with the name and its explanation, instead of
  floating between the picker and the editor.
* Add Items and Menu Items keep the two-column body.

### Utilities: one button system

The nine buttons used warning, secondary, info, primary and danger with no
rule. Now: **teal** = changes content state (Publish Due, Run Migrations);
**blue** = export and system maintenance (Sitemap, Export Static);
**outline** = reversible maintenance (Clear Cache, Warm Cache, Download
Backup); **red** = destructive (Clear Trash, Reset Analytics). Clear Cache lost
its amber, which sat oddly on a reversible action and was the only `btn-warning`
in the admin.

## Sweep 6 — inline styles removed, user editing reworked

### No inline `style=` attributes remain in the admin

There were twelve, plus a `style="width:…"` on seven `<th>` elements. All are
now classes:

* **Report table columns** — `.col-select` (32px), `.col-actions` (180px),
  `.col-when` / `.col-author` (170px). Applied across content, versions,
  activity, users, categories and tags.
* **`.image-search`** for the picker's search box, **`.messages-filter`** and
  **`.messages-data`** for the submissions view, and
  `.media-inspector form` for the delete form the media JS builds.

Verified with `grep -rn 'style="' admin --include=*.php` → no matches. The
design suite only forbade inline `<style>` *blocks*; this was the other half of
the same rule.

### User editing

The form used the old pre-settings pattern: a `<label>` wrapping its input with
the field name as loose text above it, one field per row, and no grouping.

* **Two sections with icon headings** — User Info and Update Password — on the
  same `.field-grid` as the settings page, so Username sits beside Role, First
  Name beside Last Name, Email beside UI language, and the two password fields
  side by side.
* **Real `<label for>` elements**, the same as settings.
* **The password minimum is stated**: a new `user_password_hint` string reads
  the configured `security.password_min_length` rather than hard-coding it, so
  the hint can't drift from what the save handler enforces.
* **A new "Account" card** shows Created and Last login — the page previously
  showed none of the record's own metadata, and `load_users()` already returned
  it.
* **The heading names the person** instead of repeating the signed-in user: it
  now reads "Mister Administrator" over "Editing user: demo". Before, both the
  top bar and the page heading said the same thing twice and the page never
  named whose account you were editing.
* **A Delete button** for other users' accounts, reusing the existing
  `admin/user/remove` handler. Deleting your own account stays impossible (the
  handler refuses it) and there is no button for it.
* The user list's "Nope" button for your own account is now a disabled Delete
  with a tooltip explaining why, and the empty state is the shared
  `.empty-state` treatment rather than a bare paragraph.

## Sweep 7 — media, redirects, and the menu editor's new layout

### Menu editor rebuilt as the user specified

The right-hand items area is unchanged. Everything else moved into a sidebar:

* **Menu picker** at the top of the sidebar (a plain `<select>` outside the
  save form, navigating on change), with a **New menu** button beneath it.
  `?new=1` selects no menu; the slug is derived from the label on first save.
* **Menu Label** in the sidebar.
* **Menu Location as checkboxes**, WordPress-style.
* **Add Items** (from pages / custom URL) moved into the sidebar; it is an input
  to the menu being edited, so it belongs with the menu's own settings.
* The editor is now a two-column grid (`21rem` sidebar, fluid items column),
  stacking below 900px.

**Location checkboxes needed a data-model change.** `menu_locations` is a
`location => menu-slug` map, so each location holds one menu — the checkboxes
express "which locations does *this* menu fill". `admin/menu/save.php` now takes
`locations[]` and, for every location the theme declares: sets it when checked,
and releases it when unchecked *and* currently held by this menu. Verified
against the database: checking both assigned both; re-saving with only `footer`
left `{"footer":"main"}`.

### Media

* The inspector was a run of unlabelled controls. It is now blocks separated by
  rules: preview, name and meta, a labelled field set with a proper Save action,
  the size/format picker with Copy URL, and the delete action on its own. It is
  built in JS, so its markup lives in `admin/media/index.php`.
* The page header no longer greets the user a second time; it says what the page
  is. Search and upload share one row, with the file input sized as a control and
  Upload promoted to a primary button.

### Redirects

* The add/edit form uses the settings idioms: a `settings-group` with an icon
  heading, a field grid (`From` and `Type` on one row, `To` full width), real
  `<label for>` elements, and a Cancel next to Save when editing. "From" is
  readonly while editing, which now says so.
* The list got a title, a proper empty state, and the status as a badge.
* The 404 table already had a card and an empty state; its "Redirect this" action
  now sits in a `.col-actions` column rather than an unlabelled one.

## Sweep 8 — redirects row, menu compactness, media sizes

### Redirects: one row

`From`, `To`, `Type` and the button now share a single row on a wide screen. The
two text fields split the spare width equally, `Type` is capped at 12rem because
its options are short, and the button is content-sized and aligned to the inputs
rather than to their labels. Built on a new `.field-grid-inline` (a wrapping
flex row, so it degrades to stacked fields rather than overflowing).

Measured at 1440px: From 331px, To 331px, Type 181px, button 102px — all four on
one line.

### Menu: New menu button removed, rows compacted

* The **New menu** button is gone; the picker's own "New menu" option is the way
  to start one. Its label and CSS rule were removed.
* **Menu item rows are one line**: Label and slug share the spare width, Type and
  Target are sized to their options with `field-sizing: content` (with a
  `min-width` fallback for browsers without it, and a 9rem cap so a long option
  cannot spread the row). Verified: four controls on one row, selects ~95px.
* The templates' `.menu-field-*` classes are unchanged, so `menu-editor.js`'s
  contract is untouched.

### Media: variant sizes, full-width actions

* **The size picker now names the file size** of each variant, e.g.
  `WEBP — 16 KB`. The sizes are read server-side with `filesize()` over the
  stored variants (the paths in `formats_json` are relative to the media
  directory) and passed to the JS through a `data-variant-sizes` attribute.
* **Save Changes is full width**, matching Copy URL and Delete below it.
* The size picker and Copy URL are stacked rather than side by side: in a 340px
  panel they left the selected label truncated at ~9 characters.

### A responsive bug this exposed

`admin/media` rendered **528px wide at a 390px viewport** — the grid/inspector
row was never made to stack, so the fixed 340px panel plus the grid's 140px
minimum could not fit. The inspector now stacks below the grid under 900px.
Every page in the admin now fits at 390, 768 and 1440.

## Sweep 9 — documentation brought back in line

The in-app documentation described the admin as it was before the redesign, so
several statements were now wrong. Content lives in
`admin/partials/docs-content.php` as plain arrays.

### Corrections (the docs were stating things that are no longer true)

* **"The top bar has … a search field"** — it never did after the redesign; the
  top bar carries the page title, the site link, help, the theme switch,
  full-screen and the account. Rewritten, including what happens to the bar at
  narrow widths.
* **The sidebar group list** named a "Reports" group that does not exist.
  Replaced with the real groups: Welcome, Content, Collections, Forms, More,
  System.
* **"Utilities → Reset Analytics"** etc. now match the new groupings
  (Maintenance / Content and data / Export and system), and say plainly which
  two actions are irreversible.
* **Media** gained the parts that were missing: the inspector, the size/format
  picker with pixel dimensions, Copy URL, and the fact that there is no usage
  report yet — the old text said "never delete a file another page still uses"
  as if the admin checked for you.
* **Redirects** now documents the one-row form, the 404 list's "Redirect this",
  the automatic 301 when a *published* page's slug changes (verified in
  `core/helpers/content.php`), and the reserved paths (read from
  `redirect_reserved_paths()`).
* **"Search and listings"** became "Finding things" and covers the content-list
  search and status tabs, the media search, the activity filters and the docs
  filter.
* **Activity log** pointed at "Reports"; it is under More.

### Additions

* **Menus** — the sidebar layout, choosing a menu from the picker, ticking
  locations (including that a location holds one menu, so ticking moves it),
  adding and nesting items.
* **Settings** — the section grouping, why Site URL matters, the risk in
  changing a URL prefix, and that custom code is written raw into every page.
* **SEO and sharing** — split into the per-item panel and the site-wide
  defaults under Settings, so the inheritance is explicit.
* **"The admin UI"** in the developer guide — the design system as it now
  stands: one stylesheet, tokens with their dark overrides, button intent, the
  `.field-grid` column system, the conditional-state classes that must not be
  pruned, and icons using `stroke="currentColor"`.

### Incidental

`admin/docs.php` was the one page that never set `$pageTitle`, so its top bar
read "Micro CMS Editor" while every other page named itself. It now says
"Documentation".

## Sweep 10 — documentation typography

The docs page was hard to read, and the reason was structural rather than
aesthetic: the prose had **no styling at all**. Measured before the change:

* paragraphs: `line-height: normal`, `margin: 0`, so a second paragraph began
  immediately under the previous line — the measured gap between them was **0px**
* list items: no spacing between them, and the browser's default margin left
  them tight against the paragraph above
* the reading column was **896px** wide, roughly 110 characters per line

`.docs-body` now carries a reading rhythm, with spacing applied only to *direct
children* of a section so the same elements nested inside a table cell or a
callout keep their own spacing:

* column capped at `44rem` (~704px, 80–90 characters) with `font-size: 0.95rem`
  and `line-height: 1.7`
* headings: `h2` at 1.3rem with 1.1rem below it; `h3` at 1.05rem
* 1.15rem between consecutive blocks (paragraph→paragraph, paragraph→list,
  paragraph→sub-heading), 0.5rem between list items, 1.35rem list indent
* sections separated by 3rem plus a 2rem rule; the filter sits 2rem above
* tables and code blocks got a top margin and a 1.6 line height
* the table of contents uses the `--header-height` token for its sticky offset
  instead of a hardcoded `1rem`

Along the way: at 390px the stacked table of contents filled the **entire first
screen** of a twelve-section page. It is now hidden below 900px, where the
filter already does the navigating and the text should start immediately.

## Sweep 11 — menu drag-and-drop

**The content editor was not broken; the menu editor never had it.** Established
rather than assumed:

* `admin/assets/content-editor.js` calls `new Sortable(container, …)` and the
  content page loads `admin/assets/vendor/sortable/Sortable.min.js` through
  `$pageScripts`. In the browser the instance is on the container
  (`Object.keys(el)` contains `Sortable1789677430748`), and both the library and
  the module are among the loaded scripts.
* `admin/assets/menu-editor.js` had no Sortable call at all, and
  `admin/menu/edit.php` never loaded the library. In the browser on that page
  `typeof window.Sortable` was `"undefined"` and the container carried no
  Sortable instance. `git show 195ffaf:admin/assets/menu-editor.js` — the first
  commit — contains no Sortable either, so this was a gap from the start, not a
  regression from the redesign.
* Reordering stopgaps existed instead: per-item move up / move down buttons and
  an "add child" button.

Fixed:

* `admin/menu/edit.php` now declares the vendored Sortable in `$pageScripts`,
  the same way the content editor does.
* `menu-editor.js` gained `bindSortableList()`, called for the root list and for
  every `.children-container` a row brings with it — so a cloned row's children
  are sortable as soon as they are appended. Every list is its own instance in
  one shared group, so an item can be dragged into or out of a nested position.
* The drag handle is the row title (`.menu-item-title`), which already had
  `cursor: move`, and the existing `.sortable-ghost` class marks the drop.
* `onEnd` renumbers the inputs, so the posted `items[n][children][m][…]` order
  follows the new arrangement.

Verified in the browser: `window.Sortable` is a function on the menu page, all
**12 of 12** lists (root plus 11 nested) carry a Sortable instance, adding a
child produces a bound nested list whose inputs are named
`items[0][children][0][…]`, and there are no console errors on any page.
Renumbering was checked end to end by posting two items in reverse order — the
stored order came back `Second, First`.

Not verified: the drag gesture itself. Headless Chrome will not produce native
HTML5 drag events from synthetic input, so this needs a human hand to confirm.

## Sweep 12 — menu row buttons were dead to a mouse

**The cause was the SVG icons.** Every row button is icon-only, so a real click
lands on the `<path>` inside the button, not on the button itself. The delegated
handler in `menu-editor.js` tested the *event target's* class:

```js
if (e.target.classList.contains('duplicate')) { … }
```

`Element.closest()` walks through inline SVG ancestors; `classList.contains()`
only ever looks at the target. So with a mouse the target is `path`, the test is
false, and **duplicate, add-child and remove did nothing**. The `e.target.closest('.menu-item')`
guard at the top of the handler found the row correctly, which is why the
symptom looked like "a nested row is broken" rather than "all icon buttons are
broken".

This also explains why my earlier testing passed: `element.click()` dispatches on
the button itself, so those tests exercised a path a mouse never takes. Fixing
the test to dispatch real mouse events at the element's coordinates reproduced it
immediately.

Fixed by matching with `closest()`:

```js
const duplicateBtn = e.target.closest('.duplicate');
…
if (duplicateBtn) { … }
```

and, so the same trap cannot be stepped into again, the icons no longer receive
pointer events at all:

```css
.menu-actions button svg,
.menu-actions button svg * { pointer-events: none; }
```

Verified with real mouse clicks at each button's coordinates: nested duplicate
and add-child both change the tree, and nested remove opens the confirm dialog
and removes the row. Renumbering follows
(`items[5][children][0][children][0][label]` for a grandchild).

### Checked at the same time

* The **content editor does not share the bug.** It uses the same
  `classList.contains` pattern, but its row buttons render text glyphs rather
  than SVG, and its JS-generated picker button is text-only, so the event target
  is always the button. Worth revisiting only if those buttons ever get icons.
* **`.move-up` and `.move-down` handlers were dead code and are now gone.** They
  were wired in `menu-editor.js` but no such button exists in the template — and
  `git show 195ffaf` confirms the first commit's template had only add-child,
  duplicate and remove. Not a regression; reordering is drag-and-drop now.
* **Duplicating a row does copy its children.** An earlier revision of this
  document claimed otherwise; that was wrong. `extractMenuItemData()` sets
  `children`, `createMenuItem()` reads it, and a measured run confirms it: a
  child with one grandchild duplicated to two.

## Sweep 13 — menu reordering by drag only

With the up/down approach abandoned, two things followed.

* The dead `.move-up` / `.move-down` branches were removed from
  `menu-editor.js`. The move buttons in the **content editor** are a different
  feature and stay — its handlers and the `.actions-right .move-up/.move-down`
  rules in the stylesheet are untouched.
* **An empty children list collapsed to zero height**, so nine of the eleven
  child lists on a typical menu had nothing to drop onto and nesting by drag
  was a matter of luck. Those lists now keep a small visible drop area with a
  hint, and every candidate list is highlighted while a drag is in progress
  (`.menu-editor.is-dragging`), with the list under the pointer emphasised.
  The hint is a real `<span class="menu-drop-hint">` rather than generated
  content, so it cannot enter the accessibility tree as a stray string; the
  editor toggles `.is-empty` since the hint means the list is never literally
  `:empty`.

## Sweep 14 — drag reorders, the child button nests

Drag-to-nest is gone. The reason is a real usability one: while an item is
dragged over another the list is reflowing underneath the pointer, so aiming at
"into this row" rather than "between these rows" is unreliable. Nesting is the
child button's job.

* Each children list is now its own Sortable group:
  `group: { name: SORTABLE_GROUP, pull: false, put: false }`. `pull: false`
  blocks dragging a row out of its list and `put: false` blocks dropping one in,
  so a drag can only reorder rows among their own siblings.
* The empty-list drop targets, the drop hint, the `.is-empty` marker, the
  `is-dragging` highlight and the `sortable-over` emphasis are all removed —
  they existed only to make nesting by drag aimable. Empty child lists collapse
  to nothing again, as they did before.
* `menu_drop_here` was deleted from both language files.

Verified against the library's own gate rather than by eye. `Sortable` turns the
group option into `checkPut`/`checkPull` functions, so the check is a direct
call with a control:

| group | `checkPut` | `checkPull` |
| --- | --- | --- |
| `{name, pull:false, put:false}` | `false` | `false` |
| `{name}` (control) | `true` | `true` |

Reordering within a list is unaffected: the gate only governs moves *between*
lists. The child button still nests (verified with real mouse clicks:
`items[0][children][0][label]`).

**Not verified empirically, and why.** No synthetic drag in this session was
ever really exercising Sortable: instrumenting its `onMove` while attempting a
cross-list drag produced an empty list of candidate targets, which means the
drag never started. Headless Chrome does not generate the native HTML5 drag
events Sortable listens for, so "it moved" readings from those attempts were
meaningless — one such result earlier in this work was a misread. The gate check
above is the substitute, and it is decisive about the permission logic; whether
the gesture *feels* right still needs a hand on a mouse.

## Sweep 15 — the four pages the redesign had missed

An audit against `git status` found four user-facing pages that no sweep had
touched: `category/edit.php`, `tag/edit.php`, `user/add.php` and
`auth/login.php`. The first three were the only forms left on the old
`<label><strong>…</strong><input></label>` shape, which gave them a 400-weight
label against the 600-weight `.field-label` everywhere else. `user/add.php` was
the clearest case: its sibling `user/edit.php` had been swept, so one page had
the new pattern and the other did not.

Converted all three to the shared pattern — `.field` wrappers, real
`<label class="field-label" for>` associations, `.field-input` on every control,
and help text moved into `<small>` where `.field` styles it:

* `category/edit.php`, `tag/edit.php` — plain `.field` stack, deliberately not a
  `field-grid`. These are four-field single-column forms; a grid would add
  columns for no benefit. The `field-grid` layout stayed where the fields are
  genuinely related (user edit, settings groups).
* `user/add.php` — `fieldset class="settings-group"` plus a `.field-grid`, to
  match `user/edit.php` exactly. Field ids are prefixed `new-user-` so they
  cannot collide with the edit page's.

`auth/login.php` keeps its standalone page (it is not wrapped by the admin
layout, so it has no top bar to inherit) and its centred card, but the card now
uses the shared vocabulary:

* the two placeholder-only inputs became labelled `.field` / `.field-input`
  pairs, with `autocomplete="username"` and `autocomplete="current-password"`.
  Placeholders are not labels.
* the submit button is `.btn-primary` and full width, instead of an unclassed
  button
* the error used a bespoke `.login-card .error` rule; it is now the shared
  `.notice notice-error`, and the bespoke rule is deleted
* the header is a brand row — the account icon with the page title at the same
  type scale as the header bar — rather than browser-default bold at ~2em

## Verification summary

* `php tests/run.php` → 229 passed, 0 failed, after every change.
* Headless-Chrome screenshots (desktop 1440, tablet 768, phone 390) of every
  admin page in both themes, plus a click-through of the collapsed sidebar and
  the mobile drawer, all in `tests/.tmp/redesign/` (gitignored).
  `tests/.tmp/redesign/capture.sh` is the harness: it logs in, then screenshots
  each page and reports its horizontal-overflow state.
* A scripted WCAG contrast audit of every text node on all 16 pages in both
  themes, and a scripted overflow measurement at all three widths. Final
  state: **0 sub-4.5:1 items in either theme** (the light-mode muted badge miss
  was closed by darkening `--text-muted`).
* Focus ring confirmed with a real Tab-through and a click-focus on inputs.

One tooling caveat worth recording: `getComputedStyle` reports stale colours
for the buttons cloned out of `<template>` elements by `content-editor.js`, and
in one case a stale `background-color` survived even an `!important` inline
override. The rendered pixels are correct (verified by screenshot), so the
contrast audit can report those buttons as failing when they are not. This
mattered: it is why the dark-theme danger contrast miss survived the first
audit. Trust the pixels, not the computed style, for those controls.


## Remaining approach, if this is picked up again

The four phases above are recorded under Progress. Anything further should
keep the same loop: change one surface, run `php tests/run.php`, capture
before/after screenshots through `tests/.tmp/redesign/capture.sh`, and check
the scripted contrast and overflow measurements.

## What this plan deliberately does not do

* No markup restructuring of the sidebar, header or page shells.
* No new icons, fonts, or colour rebrand.
* No changes to the theme, the front end, or any PHP behaviour.
* No new CSS file, preprocessor, or class-naming convention.

## Risks

* The design suite parses `class="..."` attributes and the stylesheet for
  `.class` selectors; a typo in either direction fails the suite, which is the
  intended safety net.
* Screenshots are the only check on actual appearance; anything the suite
  cannot see is confirmed by comparing before/after images rather than assumed.

## Verification harness

Scratch tooling (gitignored, not part of the project): a headless-Chrome
screenshot script driven against
`CMS_CONFIG_FILE=tests/config.server.php php -S 127.0.0.1:8080 tests/router.php`,
with a `demo`/`demo` session. Output lands in `tests/.tmp/redesign/`.
