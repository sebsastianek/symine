# Themes

A theme changes how Symine looks and, when it needs to, how pages are built. Themes are directories under
`themes/`; nothing has to be compiled or published.

| Theme | What it is |
|---|---|
| **Default** | Redmine's stock interface. Not a directory: it is what you get when no theme is selected. |
| **Classic** | Ships with Redmine, unchanged. |
| **Alternate** | Ships with Redmine. Its colours, tables and buttons are Redmine's; the layout rules were redone for the current page structure (header across the window, menu bar attached to it, even space around content and sidebar). |
| **Strata** | The modern Symine interface: side navigation, light and dark modes, personal options. |

## Choosing and customising

*Administration → Themes* lists the installed themes.

- **Use this theme** makes it the theme of the site (Redmine's `ui_theme` setting, also available in
  *Settings → Display*).
- **Preview** shows a theme to you only, on every page, until you stop the preview or adopt the theme.
- **Customize** opens the options the theme declares (colours, layout, logo, ...) and a *Custom CSS* field.
  The page is displayed with the theme being edited and applies every change as you make it; nothing is
  saved until you press *Save*.
- **What users can change** decides whether people may pick a *personal theme* and set the *personal options*
  of the theme they use. Both are allowed unless you turn them off here.

People choose their theme and its options in *Appearance* (in the account menu, `/my/appearance`); themes with
a switcher in the top menu (Strata) also offer the theme and the options marked `quick` there. The site theme
is what everybody else, and anyone who has not chosen, sees. Visitors who are not logged in keep their choice in a
cookie.

Nothing is added to the database schema: site values live in the `ui_theme_config` setting, personal ones
in the user preferences (`theme`, `theme_options`), uploaded pictures in `files/themes/<id>/`.

## Installing a theme

Copy its directory into `themes/`. It appears in *Administration → Themes* at once.

**Redmine themes work as they are.** A directory with a `stylesheets/application.css` and no `theme.yaml`
is handled the way Redmine does it:

- its `application.css` (and any other stylesheet named like a core one, e.g. `responsive.css`) replaces
  the core stylesheet of the same name;
- `javascripts/theme.js` is loaded on every page;
- the first file of `favicon/` becomes the favicon;
- `images/icons.svg` overrides the icons it defines;
- root-relative URLs the Rails asset pipeline used to resolve (`@import url(/application.css)`,
  `url(/themes/<id>/logo.png)`) keep working;
- `<body>` gets the class `theme-<Name>`.

Plugins can ship themes in their own `themes/` directory, and `symine.theme_paths` (a parameter of
`config/services.yaml`) adds more directories to scan.

A theme with an error is never loaded half-way: it is left out, the site keeps working, and the problem is
shown in *Administration → Themes* and by `bin/console symine:themes:lint`.

## Writing a theme

```
bin/console symine:themes:create acme --parent=strata
```

creates `themes/acme/` with a manifest and an empty stylesheet. A theme is:

```
themes/acme/
  theme.yaml               manifest (below)
  stylesheets/*.css        loaded after the core stylesheets, in the order of the manifest
  javascripts/*.js         loaded after the core scripts
  templates/               Twig templates that replace the application's
  images/ fonts/ favicon/  anything the stylesheets and templates refer to
```

Files are served from `/themes/<id>/<path>`, so stylesheets can use relative URLs (`url(../images/x.svg)`).
`theme.yaml`, `templates/` and `src/` are never served.

### theme.yaml

```yaml
name: Acme
description: Acme's house style.
version: 1.0.0
author: Acme Tools
parent: strata              # optional: start from another theme
replaces_core: false        # true: do not load Redmine's application.css and responsive.css
stylesheets:                # default: stylesheets/application.css when it exists
  - stylesheets/application.css
javascripts:                # default: javascripts/theme.js when it exists
  - javascripts/theme.js
body_classes: [acme]
swatch: ['#101820', '#f2aa4c', '#ffffff']   # colours of the card in Administration → Themes
preview: images/preview.png                 # or a screenshot

tokens:                     # CSS custom properties, written without the leading "--"
  acme-gap: 12px

color_schemes:
  light:
    label: {en: Light, pl: Jasny}
    tokens: {acme-paper: '#ffffff'}
  dark:
    label: Dark
    dark: true
    tokens: {acme-paper: '#14171a'}
default_color_scheme: auto  # a scheme id, or "auto" to follow the operating system

options:
  accent:
    type: color
    label: {en: Accent color, pl: Kolor akcentu}
    token: acme-accent
    default: orange
    user: true              # each user may set it
    presets:
      orange: '#f2aa4c'
      teal: {label: Teal, value: '#0e7566', schemes: {dark: '#5fd4bd'}}
  density:
    type: choice
    default: comfortable
    user: true
    quick: true             # also offered in the switcher of the top menu
    choices:
      comfortable: Comfortable
      compact: {label: Compact, tokens: {acme-gap: 6px}}
```

Unknown keys, missing files, invalid colours and defaults that are not acceptable are errors.

**Tokens** become custom properties of `<html>`. Give Redmine's own variables (`oc-gray-4`,
`color-header-background`, ...) a value and the core stylesheets follow.

**Colour schemes** are sets of tokens. Symine writes them for the selected scheme and, with `auto`, inside
`prefers-color-scheme` media queries, and sets the CSS `color-scheme` property (`dark: true` → `dark`).
That last part means a stylesheet can also write each colour once with
[`light-dark()`](https://developer.mozilla.org/docs/Web/CSS/color_value/light-dark) and leave the scheme
tokens empty, which is what Strata does.

**Options** are what administrators — and users, with `user: true` — can change:

| `type` | Value | Reaches the page as |
|---|---|---|
| `choice` | one of `choices` | `data-theme-<name>="<choice>"` on `<html>`, plus the `tokens` (and per-scheme `schemes`) of the choice |
| `toggle` | on / off | `data-theme-<name>="on\|off"`, plus its `tokens` when on |
| `color` | a colour, or one of `presets` | the custom property `token`; `data-theme-<name>` holds the preset id or `custom` |
| `length` | number + unit (`min`, `max`, `step`, `units`) | the custom property `token` |
| `number` | number (`min`, `max`, `step`) | the custom property `token` |
| `text` | free text | `theme_option()` in templates; the custom property `token` (a CSS string) when set |
| `image` | an uploaded picture (administrators only) | `theme_option_image()` in templates; `token` (a `url()`) when set |

Option names use `_` and become `-` in attributes: `side_notes` → `data-theme-side-notes`. The colour scheme
is the built-in option `color_scheme`, exposed as `data-color-scheme`. `label`, `description` and the labels
of choices are a string or a map of locale to string. `group` gathers options under a heading.

Everything arrives as attributes and custom properties of `<html>`, so stylesheets react without any script:

```css
#content { gap: var(--acme-gap); }
:root[data-theme-density="compact"] table.list td { padding-block: 2px; }
:root[data-color-scheme="dark"] .logo { filter: invert(1); }
```

The generated rules are served as one cacheable stylesheet (`/themes/<id>/generated.css`), the same for
everybody; personal values only change the attributes. Values entered by people are validated against the
option before they are stored and again when they are read, so a theme update cannot leave a stale value in
effect.

### Changing the structure of pages

Stylesheets restyle Redmine's markup; `templates/` changes the markup itself. A file there replaces the
application template of the same name — the layout, a page, or a partial:

```
themes/acme/templates/base.html.twig                      the layout
themes/acme/templates/issues/show.html.twig               one page
themes/acme/templates/common/_project_jump_box.html.twig  one partial
```

The original stays available as `@Symine/<name>`, so an override can extend it and replace only some
regions:

```twig
{# themes/acme/templates/base.html.twig #}
{% extends '@Symine/base.html.twig' %}

{% block layout_footer %}
<div id="footer">Acme Tools — <a href="/help">Help</a></div>
{% endblock %}
```

The layout exposes these blocks: `theme_head_tags`, `layout_flyout_menu`, `layout_top_menu`,
`layout_header` (containing `layout_quick_search`, `layout_page_title` and `page_header`), `main_menu`,
`layout_main`, `sidebar`, `content`, `layout_footer`. Strata's `templates/base.html.twig` rebuilds the header
as a navigation rail this way.

Template helpers:

| Function | Returns |
|---|---|
| `current_theme()` | the active theme (`id`, `name`, ...) |
| `theme_option('name')` | the value in effect of an option |
| `theme_option_image('logo')` | the URL of the picture of an image option, or null |
| `theme_asset('images/mark.svg')` | the URL of a file of the theme or of its parents |
| `stylesheet_path('scm')` | a core stylesheet, or the one a Redmine theme provides instead |

Keep the ids Redmine's scripts and plugins rely on (`#header`, `#main-menu`, `#quick-search`, `#sidebar`,
`#content`) when you restructure. E-mails use the templates of the *site* theme, never the personal theme
of whoever triggered the notification.

### Extending a theme

With `parent: strata`, a theme starts from Strata: the parent's stylesheets and scripts are loaded first,
its tokens, colour schemes and options are inherited, and its templates and files are used wherever the
child does not provide its own. The child overrides tokens and schemes by name, redefines an option by
declaring it again, and removes one with `name: ~`. Redmine themes (no `theme.yaml`) cannot be extended.

### Scripts

The appearance runtime (`/assets/appearance.js`) applies option changes at once and fires an
`appearance:change` event on `document` (`detail: {option, value}`) for scripts that need to react.

## Console

```
bin/console symine:themes:list              installed themes, the one in use, and errors
bin/console symine:themes:lint [id]         check theme manifests (exit code 1 on error)
bin/console symine:themes:create <id>       scaffold a theme (--parent=<id>, --name="...")
bin/console symine:themes:use <id>          set the theme of the site ("default" for Redmine's)
```
