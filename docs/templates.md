# Templates and components

A RageKit window is made of two halves:

- a **PHP class** that holds the state (public properties) and the behaviour (public methods);
- a **`.rk` template** that says what the window shows and which methods its buttons call.

`ragekit build` compiles every template ahead of time. You never write Slint yourself: the
template is translated into Slint code and the C++ glue that connects it to your PHP. Mistakes
in a template are build errors with a file and line number, not runtime surprises.

See also: [getting started](getting-started.md), [kits](kits.md) (how elements look),
[dev mode](dev-mode.md) (edit templates while the app runs).

## A first screen

```php
<?php

namespace Demo;

use RageKit\Screen;

final class CounterScreen extends Screen
{
    public int $count = 0;

    public static function template(): string
    {
        return 'templates/CounterScreen.rk';
    }

    public function increment(): void
    {
        $this->count++;
    }
}
```

```xml
<VStack>
    <Label text="Count: {count}" />
    <Button text="+" onClick="increment" />
</VStack>
```

```php
<?php

use Demo\CounterScreen;
use RageKit\App;

function main(): void
{
    $app = new App();
    $app->open(new CounterScreen(), title: 'Counter', width: 360, height: 200);
    $app->run();
}
```

Clicking `+` calls `increment()`. After every event the window reads all the values it shows
again, so a handler only changes properties; there is no `setState()` or `invalidate()`.

## Screens and components

- **`RageKit\Screen`** fills a window. `$app->open($screen, title:, width:, height:)` shows it.
  `open()` takes only screens.
- **`RageKit\Component`** is a child component: a piece of UI another template uses by its
  class name (see [Child components](#child-components)).

Both inherit `$this->app` (the running `RageKit\App`), so any handler can open another window
or quit:

```php
public function about(): void
{
    $this->app->open(new AboutScreen(), title: 'About', width: 420, height: 120);
}
```

`run()` returns when the last window closes or `$this->app->quit()` is called.

### Where the template lives

Every component overrides the static `template()` method and returns the path of its `.rk`
file, relative to the project root (the folder with `project.yml`) or absolute:

```php
public static function template(): string
{
    return 'templates/CounterScreen.rk';
}
```

Folder and file names are up to you. A component that doesn't override `template()`, or points to
a file that doesn't exist, fails the build.

The class must not be abstract. Class short names must be unique across the whole app: two
components both called `Card` in different namespaces are a build error, because the template uses
the short name.

## What a template can show

Inside an attribute, `{name}` refers to one of these, looked up in this order:

1. an `each` item (see [Lists](#repeating-elements-each));
2. a **class constant** (any visibility);
3. a **public property**;
4. a **public method without required parameters** (a computed value).

```php
public string $name = '';

public function greeting(): string
{
    return $this->name === '' ? 'Type your name above' : 'Hello, ' . $this->name . '!';
}
```

```xml
<Label text="{greeting}" />
```

Text attributes can mix literal text and references: `text="Count: {count}"`. A `bool` shows as
`true` / `false`.

### Types

Properties and computed methods must use one of these types:

| PHP type | In templates |
|---|---|
| `string` | text |
| `int` | whole number (32-bit in the UI: larger values lose range) |
| `float` | number (32-bit float in the UI: precision is lower than PHP's) |
| `bool` | `true` / `false` |
| `array` | a list of texts (values are shown as strings) |

Nullable types (`?string`), untyped properties, objects and enums can't be shown. Constants can be
strings, numbers, bools, lists of values, or lists of lists of values.

On a screen, properties and methods that no template refers to can have any type. On a child
component, every public property is a prop, so all of them must have one of these types.

A computed method needs a declared return type from the table. Without one, the build stops with
"'x()' has no return type".

## Events

An event attribute names a public method of the screen:

```xml
<Button text="Reset" onClick="reset" />
```

You can pass quoted text, converted to the parameter's type at build time:

```xml
<Button text="Secondary" variant="secondary" onClick="press('secondary')" />
```

```php
public function press(string $variant): void
{
    $this->status = 'Clicked the ' . $variant . ' button';
}
```

Inside `each`, you can pass the current item (one item per call, and it is a string):

```xml
<Button each="file in files" text="{file}" onClick="open(file)" />
```

Handler parameters must be `string`, `int`, `float` or `bool`. The number of arguments must match
the method's parameters (optional parameters may be left out).

`onClick` (Button) is the only event the core elements have. Inputs report changes through
two-way bindings instead.

## Two-way bindings (inputs)

Inputs write the user's changes straight back into a property:

```xml
<Entry value="{name}" />
<Checkbox text="Show history" checked="{showHistory}" />
<Slider value="{volume}" min="0" max="100" />
<Select value="{fruit}" options="{FRUITS}" />
```

- The binding must be a single `{property}` of the **screen** with the input's type (`string` for
  Entry/Select, `bool` for Checkbox, `float` for Slider, `int` for Tabs).
- After the user edits the value, the property is updated and the window re-reads its other
  values, so `{greeting}` above follows the typing.
- There is no change handler yet (`onChange`). React to changes in computed methods instead, as
  the kitchen-sink demo does with `volumeText()` and `progress()`.
- Two-way inputs can't be inside `each` and can't be in child components.

## Layout

### VStack and HStack

`<VStack>` places its children top to bottom, `<HStack>` left to right.

```xml
<VStack>
    <Label text="Count: {count}" />
    <HStack>
        <Button text="−" onClick="decrement" grow="true" />
        <Button text="+" onClick="increment" grow="true" />
        <Button text="Reset" onClick="reset" />
    </HStack>
</VStack>
```

- Children are 8 px apart. `padded="false"` removes the spacing.
- Children keep their natural size and line up at the start, unless one of them has
  `grow="true"`.

### grow

`grow="true"` gives an element a share of the leftover space along its parent's direction:
width in an `HStack`, height in a `VStack`. Every element accepts it. Above, `−` and `+` share
the row's free width and `Reset` keeps its size.

On the template's root element, `grow="true"` makes the content fill the window's height.

The window adds 16 px of padding around the root element.

### Showing an element only sometimes: when

```xml
<Label when="!available" text="Audio unavailable" />
<History when="showHistory" entries="{history}" />
```

`when` takes the name of a `bool` property, computed method or constant, optionally negated with
`!`, without braces (`when="flag"`, not `when="{flag}"`). Only a plain name works; expressions
like `count > 0` don't, so put the logic in a method:

```php
public function hasItems(): bool
{
    return $this->items !== [];
}
```

### Repeating elements: each

```xml
<VStack padded="false">
    <Label each="entry in entries" text="{entry}" />
</VStack>
```

`each="item in list"` repeats the element for every value of a list property, computed method or
constant. The item is available as `{item}` inside that element. A constant that is a list of
lists gives list items, which can be repeated again with an inner `each`.

An element can't have both `each` and `when`; wrap it in a `<VStack>` and put one on each.

## Core elements

Every [kit](kits.md) provides these elements with the same attributes, so a template builds with
any kit. A kit may lack one; it then falls back to the Material kit's version.

| Element | Attributes | Notes |
|---|---|---|
| `Label` | `text`, `size` | `size`: `body` (default), `title`, `display` |
| `Button` | `text`, `variant`, `onClick` | `variant`: `default`, `secondary`, `outline`, `ghost`, `destructive` |
| `Entry` | `value` | two-way `string` |
| `Checkbox` | `text`, `checked` | `checked`: two-way `bool` |
| `Slider` | `value`, `min`, `max` | `value`: two-way `float`; range defaults to 0 to 1 |
| `Select` | `value`, `options` | `value`: two-way `string`; `options`: a list |
| `Image` | `source` | a file path, see below |
| `Progress` | `value` | `float` from 0 to 1 |
| `Card` | `title` | holds other elements |
| `Separator` | (none) | a thin line |
| `Tabs` / `Tab` | `selected` / `title` | see below |

Every element also accepts `grow`, `when` and `each`. Any other attribute is a build error that
lists the allowed ones.

Enum attributes (`size`, `variant`) take literal values only, not `{bindings}`.

Elements can't contain text: write `<Label text="Hello" />`, not `<Label>Hello</Label>`. Only
`Card`, `VStack`, `HStack`, `Tabs` and `Tab` contain other elements.

Templates are XML, so `&` and `<` inside attribute values are written `&amp;` and `&lt;`.

### Image

```xml
<Image source="assets/logo.svg" />
```

`source` is a path relative to the project root, or absolute. The image is embedded into the app
at build time, so it doesn't need to ship next to the executable. A missing file is a build error.
Binding the source to a PHP value (`source="{cover}"`) isn't supported yet.

### Tabs

```xml
<Tabs selected="{tab}">
    <Tab title="Inputs">
        <VStack>
            <Entry value="{name}" />
        </VStack>
    </Tab>
    <Tab title="Display">
        <Label text="Display" size="display" />
    </Tab>
</Tabs>
```

```php
public int $tab = 0;
```

- `selected` binds an `int` property of the screen (two-way): the index of the open tab.
  Setting it from PHP switches tabs.
- `<Tabs>` may only contain `<Tab>` elements, and each `<Tab>` exactly one element. Wrap several
  in a `<VStack>`.
- Tab titles are literal text; a `{binding}` there is a build error.
- Pages keep their state when you switch away and back.

## Child components

A child component is a `RageKit\Component` that other templates use by its short class name. Its
public properties are its inputs (props):

```php
final class History extends Component
{
    /** @var list<string> */
    public array $entries = [];

    public static function template(): string
    {
        return 'templates/History.rk';
    }
}
```

```xml
<!-- templates/History.rk -->
<VStack padded="false">
    <Label each="entry in entries" text="{entry}" />
</VStack>
```

```xml
<!-- in the screen's template -->
<History when="showHistory" entries="{history}" />
```

Current limits of child components:

- They only display what they're given. They can't handle events, contain two-way inputs or
  show computed methods; put interactive elements in the screen's template.
- Your PHP code never gets an instance of the child: the class only declares its props and its
  template.
- A child can't contain other elements (`<History>…</History>`).
- Components may use other components, but not in a cycle.

## Layouts: a window frame and a custom title bar

A layout is the frame around a screen: a title bar with the app's own buttons, a sidebar, a footer.
It's a `RageKit\Layout` class with its own template, its own state and its own handlers. A screen
opts in by naming it:

```php
final class HomeScreen extends Screen implements TitleBarContext
{
    public static function template(): string
    {
        return 'templates/HomeScreen.rk';
    }

    public static function layout(): string
    {
        return AppLayout::class;
    }

    public function title(): string
    {
        return 'Home';
    }
}
```

```php
final class AppLayout extends Layout
{
    public string $query = '';

    public static function template(): string
    {
        return 'templates/AppLayout.rk';
    }

    /** What screens using this layout must provide. */
    public static function context(): string
    {
        return TitleBarContext::class;
    }

    public function submit(): void
    {
        $this->context->search($this->query); // the screen it frames
    }
}
```

```xml
<!-- templates/AppLayout.rk -->
<VStack padded="false">
    <WindowDrag>
        <Label text="{context.title}" grow="true" />
        <Entry value="{query}" />
        <Button text="Search" onClick="submit" />
        <WindowControls />
    </WindowDrag>
    <Content />
</VStack>
```

- **`<Content />`** is where the screen goes. A layout's template has exactly one.
- **A window whose screen has a layout has no OS frame**: the layout draws the title bar. The
  window keeps the OS's shadow (and, on Windows 11, rounded corners) and can still be resized from
  its edges.
- **`<WindowDrag>`** lays out its children like `<HStack>`. Pressing on its background and dragging
  moves the window; double-clicking it maximises or restores. Buttons and inputs inside it still
  work as usual. On Windows the OS does the move, so snapping to screen edges works; on Linux the
  app moves the window itself, without the window manager's snapping.
- **`<WindowControls />`** shows the kit's minimise, maximise/restore and close buttons. Close
  works like the OS's close button.
- **The context.** `context()` names an interface; every screen using the layout must implement
  it, or the build fails. The layout's template shows its methods as `{context.title}` and calls
  them with `onClick="context.refresh"`; in PHP, `$this->context` is the screen. Inputs in a
  layout bind the layout's own properties (`{query}` above); pass values on in a handler.
- **State.** Each window gets its own layout object, created by RageKit (so its constructor can't
  require arguments). `public bool $maximized` is kept up to date, also when the OS maximised the
  window. `minimize()`, `toggleMaximize()` and `close()` can be used from your own buttons. A
  screen reaches its layout as `$this->layout`.
- `<Content />`, `<WindowDrag>` and `<WindowControls />` are only allowed in a layout's template.
  Screens without `layout()` keep the ordinary OS window.

**Linux:** the app moves and resizes its own window through X11 (the window manager can't take
the drag over, so there's no edge snapping), so apps with a layout run on XWayland in a Wayland
session (text can look blurry with fractional scaling). Set `RAGEKIT_WAYLAND=1` to stay on
Wayland, where the title bar can't move the window. The corners stay square on Linux.

**Windows:** written but not tested yet; moving, snapping, resizing and the rounded corners still
need a check on a real Windows machine.

`demo/titlebar` is a complete example.

## Elements from a kit

Kits can add elements beyond the core set. Use them with an XML namespace whose value is the
kit's name:

```xml
<VStack xmlns:ui="icons">
    <ui:ImageButton image="assets/play.svg" text="Play" onClick="play" />
</VStack>
```

Core elements never need a prefix. See [kits](kits.md) for writing your own.

## Build errors

Template errors are reported as `file:line: message` and stop the build. The most common ones:

| Message (shortened) | What to do |
|---|---|
| `X doesn't declare template()` | Override `public static function template(): string` in that class. |
| `X::template() points to …, which doesn't exist` | The path is relative to the folder with `project.yml`. |
| `two components are named X` | Rename one class; short names must be unique. |
| `'x' is not a property, method or constant of X` | Check the spelling; private and protected properties and methods can't be used. |
| `'x()' has no return type` | Declare the computed method's return type (string, int, float, bool or array). |
| `when="…": expressions aren't supported` | Name a `bool` property or method; move `count > 0` into a method. |
| `property $x has type ?string; templates support string, int, float, bool and array (not nullable)` | Give the property a supported, non-nullable type, or show it through a computed method. |
| `<X> has no attribute 'y' (allowed: …)` | Use one of the listed attributes. |
| `unknown element <X>` | Not a core element, layout or component; kit elements need their prefix. |
| `two-way inputs must bind a property of the screen` | Bind a single `{property}`, not text, a method or an `each` item. |
| `expected a float property, $x is int` | Change the property's type to what the input edits. |
| `'x' is not a public method of X` | Handlers must be public methods of the screen. |
| `text content isn't supported, use text="…"` | Move the text into an attribute. |
| `components use each other in a cycle` | A component can't (indirectly) contain itself. |
| `X's template needs exactly one <Content />` | A layout's template marks where the screen goes, once. |
| `<Content /> is only allowed in a Layout's template` | Also `<WindowDrag>`, `<WindowControls>`, `context.x`: move them to the layout. |
| `X uses L, which needs I; implement it` | The screen must implement the interface the layout's `context()` names. |
| `'x' is not declared by I` | `{context.x}` can only use methods of the context interface. |
| `two-way inputs in a layout must bind the layout's own properties` | Bind a layout property; pass it to the screen in a handler. |
| `L must be constructible without arguments` | RageKit creates the layout; drop the constructor's required parameters. |

## TypePHP and your PHP code

Release builds compile your PHP with TypePHP, which supports a subset of PHP. `ragekit dev` runs
plain PHP, so code that works in dev mode may still fail in `ragekit build`; build regularly. The
rules you are most likely to meet:

- Every `case` of a `switch`, including the last `default:`, must end with `break`, `return`,
  `continue` or `throw`.
- A variable keeps one type. Assigning an object of another class to a variable that already
  holds one fails ("Cannot re-assign variable from …").
- `Box` is a reserved name; don't name a class `Box`.
- Abstract static methods compile, but the app prints a warning at startup. Avoid them.
- Nano builds (`ragekit: { nano: true }`) have no `eval`, `include`/`require` at runtime,
  sockets or process execution. `ctype_*` functions currently make Nano apps fail at startup.

Everything that runs in the app (screens, components, your other classes) goes through TypePHP;
only `main.php` and the folders under `sources:` in `project.yml` are compiled. See
[toolchain](toolchain.md) for normal and Nano builds.
