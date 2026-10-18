# Kits

A kit decides what your templates look like. It maps RageKit's elements (`Button`, `Slider`,
`Tabs`, …) to components written in [Slint](https://slint.dev), the toolkit that draws the UI, and
it can add elements of its own.

Templates don't change when you switch kits: every kit accepts the same attributes for the core
elements, so the same `.rk` file builds with any of them. You only need Slint if you write a kit.

See also: [templates](templates.md), [getting started](getting-started.md),
[dev mode](dev-mode.md).

## The kits RageKit ships

| Kit | Look | Third-party code |
|---|---|---|
| `material` (default) | Slint's Material 3 library: Material buttons, cards and inputs | Slint's Material library (MIT), downloaded on the first build |
| `shadcn` | Dark zinc palette, 8 px radius, 1 px borders, in the style of shadcn/ui | none |

Material also acts as the fallback: if the active kit lacks a core element, or has no window
component, Material's is used. That's why its library is downloaded even when you use another kit.

Known rough edge: Material's slider can only be dragged by its thumb.

## Choosing a kit

In `project.yml`:

```yaml
ragekit:
  backend: slint
  kit: shadcn
```

Or for one build, without editing the file:

```sh
vendor/bin/ragekit build --kit=material
vendor/bin/ragekit dev --kit=material
```

A kit name that isn't installed is an error listing the installed kits.

## The core element contract

Every kit that provides a core element accepts exactly these attributes for it:

| Element | Attributes |
|---|---|
| `Label` | `text`, `size` (`body`, `title`, `display`) |
| `Button` | `text`, `variant` (`default`, `secondary`, `outline`, `ghost`, `destructive`), event `onClick` |
| `Entry` | `value` (two-way string) |
| `Checkbox` | `text`, `checked` (two-way bool) |
| `Slider` | `value` (two-way float), `min`, `max` (default 0 to 1) |
| `Select` | `value` (two-way string), `options` (list) |
| `Image` | `source` (image) |
| `Progress` | `value` (float, 0 to 1) |
| `Card` | `title`, contains other elements |
| `Separator` | (none) |
| `TabBar` | `titles` (list), `selected` (two-way int): used by `<Tabs>` in templates |

A kit may give a core element extra attributes. A template that uses them then builds only with
that kit.

## Elements a kit adds

A kit can provide elements that aren't in the core set. Templates use them with an XML namespace
whose value is the kit's name:

```xml
<VStack xmlns:ui="icons">
    <ui:ImageButton image="assets/play.svg" text="Play" onClick="play" />
</VStack>
```

## Writing a kit

A kit is a folder with a `kit.yml` and one or more `.slint` files. This is a complete kit that
adds one element, an icon button:

```
kits/icons/
  kit.yml
  icons.slint
```

```yaml
# kits/icons/kit.yml
name: icons
entry: icons.slint
elements:
  ImageButton:
    component: ImageButton
    props:
      image: image
      text: string
    events:
      onClick: clicked
```

```slint
// kits/icons/icons.slint
export component ImageButton inherits Rectangle {
    in property <image> image;
    in property <string> text;
    callback clicked;

    min-width: 72px;
    min-height: 84px;
    border-radius: 16px;
    background: touch.pressed ? #4a5175 : touch.has-hover ? #3a405e : #2b2f45;
    animate background { duration: 120ms; }

    VerticalLayout {
        padding: 12px;
        spacing: 6px;
        alignment: center;
        Image {
            source: root.image;
            height: 36px;
            image-fit: contain;
        }
        if root.text != "" : Text {
            text: root.text;
            horizontal-alignment: center;
        }
    }

    touch := TouchArea {
        clicked => { root.clicked(); }
    }
}
```

### kit.yml

| Key | Meaning |
|---|---|
| `name` | The kit's name: what `ragekit: kit:` and template namespaces refer to. Required. |
| `entry` | The `.slint` file that exports the components, relative to the kit's folder. Required. |
| `elements` | Template tag → element definition (below). |
| `window` | The component every window inherits. Optional; Material's is used without it. |
| `libraries` | Slint libraries the kit imports: `name: path`, imported in Slint as `"@name"`. |
| `fetch` | Third-party code to download into the kit's folder (below). |

Inside `kit.yml`, `${KIT}` is the kit's folder and `${RAGEKIT}` is RageKit's root. Other
`${NAME}` values are read from the environment; an unset one is an error.

### Elements

```yaml
elements:
  Slider:
    component: ShadSlider          # exported from the entry file
    props:
      value: { type: float, twoWay: value_changed }
      min: { type: float, property: minimum }
      max: { type: float, property: maximum }
  Button:
    component: ShadButton
    props:
      text: string
      variant: { type: string, enum: [default, secondary, outline, ghost, destructive] }
    events:
      onClick: clicked
  Card:
    component: ShadCard
    props:
      title: string
    children: true
```

- **`component`**: the Slint component the element becomes. Required.
- **`props`**: the attributes it accepts. Short form `name: type`, or a map:
  - `type`: `string`, `int`, `float`, `bool`, `list` (a list of strings) or `image`;
  - `property`: the Slint property it sets, when it differs from the attribute's name;
  - `enum`: the allowed literal values (templates can't bind enum attributes);
  - `twoWay`: makes it an input (see below).
- **`events`**: template attribute → Slint callback, e.g. `onClick: clicked`. When the callback
  fires, RageKit calls the template's handler. Arguments the callback passes are ignored; declare
  it without any (`callback clicked;`).
- **`children: true`**: the element can contain other elements. Its component places them with
  Slint's `@children`.

Templates can only use attributes listed under `props` and `events` (plus `grow`, `when` and
`each`, which every element has).

### Inputs: two-way props

```yaml
value: { type: float, twoWay: value_changed }
```

```slint
in-out property <float> value;
callback value_changed(float);
```

The component declares the property as `in-out` and a callback with one argument of the prop's
type. It calls the callback with the new value **when the user changes it**, and must not call it
when the value is set from PHP. RageKit binds the property to the screen's PHP property both ways.

### Image props

`image` props take a file path in the template (relative to the app's project root, or
absolute). The file is checked and embedded into the app when it is built. Binding an image to a
PHP value isn't supported yet.

### The window component

```yaml
window: ShadWindow
```

```slint
export component ShadWindow inherits Window {
    background: Shad.background;
    default-font-size: Shad.font-size;
}
```

Every screen's window inherits it, so it sets what applies to the whole window: background,
default font, colours. It must inherit Slint's `Window` (directly or through another component).
RageKit sets the window's title and its content.

### Layout inside a kit

Elements are placed in vertical and horizontal layouts. Give components sensible preferred and
minimum sizes; the layout stretches an element only when the template says `grow="true"`.

### Third-party Slint code: fetch

A kit that builds on other Slint code declares it under `fetch:`. RageKit downloads it into the
kit's folder on the first build (and again when it doesn't match), so it doesn't need to be in your
repository. This is how the Material kit gets Slint's Material library:

```yaml
fetch:
  - git: https://github.com/slint-ui/slint.git
    ref: v1.18.1
    commit: 372cf0ee5577c3dfec309a45e7b778ba4e81b734   # what the tag must point to
    path: ui-libraries/material/src                    # only this folder is kept
    to: library
libraries:
  material: ${KIT}/library/material.slint
```

A single file works too, checked by its SHA-256:

```yaml
fetch:
  - url: https://example.com/icons.slint
    sha256: <the file's SHA-256>
    to: vendor/icons.slint
```

`to` must be a path inside the kit's folder. Add the fetched folder to your `.gitignore`.
Fetching needs `git` (for `git:` entries) and network access the first time.

Every installed kit's `fetch:` runs on every build (it's a quick check once the files are
present), not only the active kit's.

### Overriding the core elements

A kit that provides core elements (`Button`, `Label`, …) under their core names replaces Material's
when it is the active kit. Core elements it doesn't provide still come from Material. Keep to the
attributes in [the contract](#the-core-element-contract) so templates stay portable.

## Where kits come from

RageKit finds kits in three places, in this order:

1. RageKit's own `kits/*/kit.yml` (`material`, `shadcn`);
2. your app's `kits/*/kit.yml`, next to `project.yml`;
3. Composer packages that declare a kit (below).

Two kits with the same `name` from different folders are an error. Name your kits so they can't
clash with RageKit's or with packages you install.

### A kit in your app

Put it in `<project>/kits/<name>/` with its `kit.yml`. It's found automatically; nothing to add to
`project.yml` unless you make it the active kit.

### A kit as a Composer package

Point `extra.ragekit.kit` at the package's `kit.yml`, relative to the package root:

```json
{
    "name": "acme/ragekit-icons",
    "type": "library",
    "extra": {
        "ragekit": {
            "kit": "kit.yml"
        }
    }
}
```

Require the RageKit minor version the kit was written for (`"ragekit/ragekit": "^0.1"`): the kit
format and the core element contract may change between 0.x minor versions (see the CHANGELOG).

After `composer require acme/ragekit-icons`, the app's next build sees the kit. RageKit reads the
app's `vendor/composer/installed.json`, so the package must be installed in the app itself.

## Editing a kit while the app runs

With `ragekit dev`, changes to a kit's `kit.yml` and `.slint` files reload the UI in place, like
template changes. A kit error is reported and the old UI stays. See [dev mode](dev-mode.md).

## Licensing

Slint, which draws every kit, is available under three licenses: a royalty-free license, GPLv3,
and a commercial license. Apps under the royalty-free license must show Slint attribution (for
example an About screen with Slint's "AboutSlint" notice, or a "Made with Slint" badge where the
app is downloaded). Read [slint.dev/licensing](https://slint.dev/licensing) before you ship.

The Material kit uses Slint's Material library, which is MIT-licensed. Kits you write are yours
to license, but third-party Slint code you `fetch:` keeps its own license.
