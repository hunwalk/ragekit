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

```blade
<VStack>
    <Label text="Count: {{ $count }}" />
    <Button text="+" onClick="increment" />
</VStack>
```

```php
<?php

namespace Demo;

use RageKit\App;
use RageKit\Screen;

final class CounterApp extends App
{
    protected function firstScreen(): Screen
    {
        return new CounterScreen();
    }
}
```

```php
// main.php
function main(): void
{
    (new Demo\CounterApp())->run();
}
```

Clicking `+` calls `increment()`. After every event the window reads all the values it shows
again, so a handler only changes properties; there is no `setState()` or `invalidate()`.

Templates are XML elements plus **Rageblade**, a Blade-flavoured syntax: `{{ expressions }}`,
`@foreach`, `@if` and `@style`. It looks like PHP, but it is compiled ahead of time into the UI
(Slint), so it runs natively and follows your state automatically. It is not PHP that runs at
runtime: loops and conditions become the UI's own `for` and `if`.

## Screens and components

- **`RageKit\App`** is the app itself: you extend it, `firstScreen()` names the screen the main
  window starts with, `title()`, `width()` and `height()` the main window's title and size, and
  `createStore()` its [state](state.md). `main()` runs it: `(new MyApp())->run()`.
- **`RageKit\Screen`** fills a window. A window can hold a stack of screens
  ([navigation](navigation.md)).
- **`RageKit\Window`** is one OS window. The plain `Window` has the OS's title bar; a subclass
  with its own template draws its own ([Windows](#windows-a-frame-and-a-custom-title-bar)).
- **`RageKit\Component`** is a child component: a piece of UI another template uses by its
  class name (see [Child components](#child-components)).

Screens, windows and components inherit `$this->app` (the running app), so any handler can open
another window or quit:

```php
public function about(): void
{
    $this->app->open(new AboutScreen(), title: 'About', width: 420, height: 120);
}
```

`open()` returns the new `Window`. Closing the **main window** (the one `firstScreen()` opened in)
ends the app and closes the others; closing another window only closes it. `$this->app->quit()`
ends the app from code. (An app that should keep running without its main window overrides
`mainWindowClosed()`.)

An exception that a handler (or a module event's callback) doesn't catch doesn't end the app: it's
printed to stderr as `ragekit: uncaught RuntimeException: …`, what the handler changed before the
throw is still shown, and the next click works. To show or log it yourself, override
`uncaughtException()` in your app:

```php
protected function uncaughtException(\Throwable $e): void
{
    $this->main()->toast('Something went wrong: ' . $e->getMessage());
}
```

### Where the template lives

Every component overrides the static `template()` method and returns the path of its `.rk`
file, relative to the project root (the folder with `project.yml`) or absolute:

```php
public static function template(): string
{
    return 'templates/CounterScreen.rk';
}
```

Folder and file names are up to you. Without `template()`, a class in a source folder uses the
file at the same path under `templates/`: `src/Screens/LoginScreen.php` →
`templates/Screens/LoginScreen.rk` (see [make.md](make.md#templates-find-themselves)). A template
that doesn't exist fails the build, and the error names the file it expected.

The class must not be abstract. Class short names must be unique across the whole app: two
components both called `Card` in different namespaces are a build error, because the template uses
the short name.

## Expressions: {{ }}

`{{ … }}` holds an expression, written like PHP. Text attributes can mix text and expressions:
`text="Hello, {{ $name }}!"`; other attributes take one expression: `value="{{ $progress / 100 }}"`.

What an expression can use:

| You write | Means |
|---|---|
| `$name` | a public property (or a `@foreach` variable) |
| `$device->name`, `$device->label()` | a member of a `@foreach` item from a collection (see below) |
| `greeting()` or `$this->greeting()` | a public method without required parameters (a computed value) |
| `self::LIMIT` | a class constant |
| `count($items)` | the number of items in a list |
| `'text'`, `12`, `1.5`, `true` | literals |
| `+ - * / %`, `.` | arithmetic, joining text |
| `=== !== == != < <= > >=`, `&& \|\| !`, `? :` | comparisons, logic, a ternary |

Expressions are **strict**, so they never quietly mean something different from PHP:

- Conditions (`@if`, `? :`, `!`, `&&`, `||`) must be `bool`: write `count($items) > 0`, not `$items`.
- `==` behaves like `===`, and both sides must have the same type (whole numbers and decimals may
  mix).
- `/` always gives a decimal (`7 / 2` is `3.5`, `6 / 3` is `2.0`); `%` takes whole numbers.
- `.` joins text and numbers; joining a bool is an error (use `$on ? 'yes' : 'no'`).
- Methods shown in templates can't take arguments (handlers can, see [Events](#events)).
- Not supported: assignments, `??`, `null`, array access (`$x['k']`), other functions.

```php
public string $name = '';

public function greeting(): string
{
    return $this->name === '' ? 'Type your name above' : 'Hello, ' . $this->name . '!';
}
```

```blade
<Label text="{{ greeting() }}" />
<Label text="{{ count($items) }} items, {{ count($items) > 0 ? 'ready' : 'empty' }}" />
```

### Types

Properties and computed methods must use one of these types:

| PHP type | In templates |
|---|---|
| `string` | text |
| `int` | whole number (32-bit in the UI: larger values lose range) |
| `float` | number (32-bit float in the UI: precision is lower than PHP's) |
| `bool` | `true` / `false` |
| `array` | a list of texts (values are shown as strings) |
| a `RageKit\Collection` subclass | a list of objects for `@foreach` (see [Lists of objects](#lists-of-objects-collections)) |

Nullable types (`?string`), untyped properties, other objects and enums can't be shown. Constants can be
strings, numbers, bools, lists of values, or lists of lists of values.

On a screen, properties and methods that no template refers to can have any type. On a child
component, every public property is a prop, so all of them must have one of these types.

A computed method needs a declared return type from the table. Without one, the build stops with
"'x()' has no return type".

## Events

An event attribute calls a public method of the screen. Its arguments are expressions:

```blade
<Button text="Reset" onClick="reset" />
<Button text="Secondary" variant="secondary" onClick="press('secondary')" />
@foreach ($files as $i => $file)
    <Button text="{{ $file }}" onClick="open($file, $i)" />
@endforeach
```

```php
public function open(string $file, int $index): void { … }
```

- Literal arguments (`'secondary'`, `3`) are converted to the parameter's type at build time.
- Other arguments are evaluated in the UI when the event happens, and must fit the parameter:
  `int` takes whole numbers, `float` any number, `bool` a bool, `string` text or numbers.
- Handler parameters must be `string`, `int`, `float` or `bool`. The number of arguments must
  match the method's parameters (optional parameters may be left out).

`onClick` (Button) is the only event the core elements have. Inputs report changes through
two-way bindings instead.

## Two-way bindings (inputs)

Inputs write the user's changes straight back into a property:

```blade
<Entry value="{{ $name }}" />
<Checkbox text="Show history" checked="{{ $showHistory }}" />
<Slider value="{{ $volume }}" min="0" max="100" />
<Select value="{{ $fruit }}" options="{{ self::FRUITS }}" />
```

- The binding must be a single `{{ $property }}` of the **screen** (or, in a window's template,
  of the window), or a store property `{{ $store->settings->darkMode }}` ([state](state.md)), with
  the input's type (`string` for Entry/Select/Textarea/RadioGroup, `bool` for
  Checkbox/Switch/Toggle, `float` for Slider, `int` for Tabs). Any other expression is shown
  one-way: the input displays it, but the user's edits don't reach PHP.
- After the user edits the value, the property is updated and the window re-reads its other
  values, so `{{ greeting() }}` above follows the typing.
- There is no change handler yet (`onChange`). React to changes in computed methods instead, as
  the kitchen-sink demo does with `volumeText()` and `progress()`.
- Two-way inputs can't be inside `@foreach` and can't be in child components.

## Showing and repeating: @if and @foreach

```blade
@if (!available())
    <Label text="Audio unavailable" />
@elseif ($loading)
    <Spinner />
@else
    <Label text="{{ $status }}" />
@endif

@foreach ($entries as $entry)
    <Label text="{{ $entry }}" />
@endforeach

@foreach (self::KEYPAD as $row)
    <HStack>
        @foreach ($row as $key)
            <Button text="{{ $key }}" onClick="press($key)" grow="true" />
        @endforeach
    </HStack>
@endforeach
```

- Directives go at the start of a line; their arguments fit on one line.
- `@foreach ($list as $item)` or `($list as $i => $item)` repeats over a list property, computed
  method or constant (`$i` counts from 0). A constant that is a list of lists gives lists, which
  an inner `@foreach` repeats again.
- Each `@foreach` body and each `@if` branch holds **one element** (or one directive: a filter like
  `@foreach` → `@if` → element works); wrap several elements in a `<View>` or `<VStack>`.
- Inside a `<View>`, an absolutely positioned element may sit in an `@if` or `@foreach` (a badge
  shown only sometimes); all elements of that `@if`/`@foreach` must then be absolute.
- `@if` conditions must be bools.
- Both are live: when the list or the condition changes, the window updates.

### Lists of objects: collections

To loop over objects, keep them in a collection: a class extending `RageKit\Collection` whose
`OF` constant names the item class. Templates then read the items' members, checked when you build:

```php
final class Device
{
    public function __construct(
        public string $name,
        public string $image = '',
        public float $battery = 0.0,
    ) {
    }

    public function label(): string
    {
        return $this->name . ' (' . round($this->battery * 100) . ' %)';
    }
}

final class Devices extends \RageKit\Collection
{
    public const OF = Device::class;
}

final class DevicesScreen extends Screen
{
    public Devices $devices;

    public function __construct()
    {
        $this->devices = new Devices();
    }

    public function scan(): void
    {
        $this->devices->clear();
        foreach (Hardware::found() as $name) {
            $this->devices[] = new Device($name);
        }
    }

    public function pick(string $name): void
    {
    }
}
```

```blade
@foreach ($devices as $i => $device)
    <Card title="{{ $device->label() }}">
        <Image source="{{ $device->image }}" fallback="{{ $assets->images->device }}" width="64" height="64" />
        <Progress value="{{ $device->battery }}" />
        <Button text="Pick" onClick="pick($device->name)" />
    </Card>
@endforeach
<Label text="{{ count($devices) }} devices" />
```

- A collection can be a screen's or window's property, a method's return type, or a store's member
  (`@foreach ($store->devices->devices as $device)`).
- `$device->name` reads a public property of the item class, `$device->label()` a public method
  without parameters. Members shown in templates must be `string`, `int`, `float` or `bool` (not
  nullable). They work everywhere expressions do: text, attributes, `@if`, handler arguments.
- Change a collection with `add($item)` or `$c[] = $item`, `set($i, $item)` or `$c[$i] = $item`,
  `remove($i)` or `unset($c[$i])` (later items move up), and `clear()`; read it with `foreach`,
  `count()`, `get($i)` or `$c[$i]`, `all()` and `isEmpty()`. Adding an object that isn't an `OF`
  (or a subclass of it) throws `InvalidArgumentException`; an index that doesn't exist throws
  `OutOfRangeException`.
- Changing an item's property (`$device->image = $url;` when an API answers) updates its row with
  the next update, like any other value. Only rows that changed are redrawn.
- A misspelled member, an array or nullable member, or a collection without `OF` stops the build
  with the line and what the item class has, e.g.
  `DevicesScreen.rk:3: $device->nmae: Device has no public property or method 'nmae' (it has: name, image, battery, label())`.

## Layout

### VStack and HStack

`<VStack>` places its children top to bottom, `<HStack>` left to right.

```blade
<VStack>
    <Label text="Count: {{ $count }}" />
    <HStack>
        <Button text="−" onClick="decrement" grow="true" />
        <Button text="+" onClick="increment" grow="true" />
        <Button text="Reset" onClick="reset" />
    </HStack>
</VStack>
```

- Children are 8 px apart. `padded="false"` removes the spacing (or set `gap` in a style).
- Children keep their natural size and line up at the start, unless one of them has
  `grow="true"` (or a `flex` style).

### grow

`grow="true"` gives an element a share of the leftover space along its parent's direction:
width in an `HStack`, height in a `VStack`. Every element accepts it. Above, `−` and `+` share
the row's free width and `Reset` keeps its size.

On the template's root element, `grow="true"` makes the content fill the window's height.

The window adds 16 px of padding around a root VStack/HStack (a root `<View>` gets none, see below).

### View: flexbox, like React Native

`<View>` is React Native's basic box: a background/border/corners box whose children are laid out
with **flexbox** (column by default, no wrapping, like React Native). It takes the styles below.

```blade
<View style="card">
    <Label text="Profile" size="title" />
    <View style="tags">
        @foreach ($tags as $tag)
            <Badge text="{{ $tag }}" variant="secondary" />
        @endforeach
    </View>
    <Badge text="new" variant="destructive" style="corner" />
</View>
```

```php
public const STYLES = [
    'card' => ['backgroundColor' => '#18181b', 'borderRadius' => 12, 'padding' => 16, 'gap' => 8],
    'tags' => ['flexDirection' => 'row', 'flexWrap' => 'wrap', 'gap' => 6],
    'corner' => ['position' => 'absolute', 'top' => 12, 'right' => 12],
];
```

- `flexWrap: 'wrap'` moves items that don't fit onto the next line.
- `position: 'absolute'` takes a child out of the flow and places it from `top`/`right`/`bottom`/
  `left` inside its `<View>` (only a `<View>` parent can do that).
- A `<View>` as the template's root fills the window, with no extra padding.

## Styles

Styles work like React Native's `StyleSheet`: named style objects, defined once in the class as
a `STYLES` constant, used by name in the template.

```php
public const STYLES = [
    'row' => ['flexDirection' => 'row', 'justifyContent' => 'space-between', 'padding' => 8,
        'borderRadius' => 8, 'backgroundColor' => 'transparent', 'transition' => 150],
    'selected' => ['backgroundColor' => '#27272a'],
];
```

```blade
@foreach (self::TASKS as $task)
    <View style="row" @style(['selected' => $task === $selectedTask])>
        <Label text="{{ $task }}" />
        <Button text="Select" variant="ghost" onClick="select($task)" />
    </View>
@endforeach
```

- `style="a b"` applies styles left to right; later ones win.
- `@style([...])` (like Laravel's `@class`) lists style names that always apply (`'row'`) and
  ones that apply while a condition is true (`'selected' => $cond`). They switch live.
- Any style property can also be an attribute, which wins over named styles, React Native's
  inline style: `<View style="bar" width="{{ $progress * 200 }}" />`. Numbers can be
  expressions; colours must be literal (switch colours with `@style`).
- Share styles between classes with PHP itself: `public const STYLES = [...Theme::STYLES, …]`.
- A property set only by a conditional style needs a base value too, except backgrounds, borders,
  corners, opacity, padding and gap (they default to none).
- In `ragekit dev`, editing `STYLES` reloads the UI like a template edit.

| Property | Values | Applies to |
|---|---|---|
| `width`, `height`, `minWidth`, `minHeight`, `maxWidth`, `maxHeight` | pixels, or `'50%'` (width/height) | every element |
| `margin`, `marginHorizontal`, `marginVertical`, `marginTop` … `marginLeft` | pixels | every element |
| `flex` (`flexGrow`) | number: share of the free space | every element |
| `alignSelf` | `auto`, `flex-start`, `flex-end`, `center`, `stretch` | every element |
| `opacity` | 0 to 1 | every element |
| `position` + `top`, `right`, `bottom`, `left` | `relative` / `absolute`, pixels | children of a `<View>` |
| `backgroundColor`, `borderColor` | `#rgb`, `#rrggbb`, `#rrggbbaa`, `rgb()`, `rgba()`, `transparent` | `<View>` |
| `borderWidth`, `borderRadius` | pixels | `<View>` |
| `overflow` | `visible`, `hidden` (clip) | `<View>` |
| `shadowColor`, `shadowRadius`, `shadowOffset` (`['width' => x, 'height' => y]`) | colour, pixels | `<View>` (GPU renderers only) |
| `flexDirection` | `column`, `row`, `column-reverse`, `row-reverse` | `<View>` |
| `flexWrap` | `nowrap`, `wrap`, `wrap-reverse` | `<View>` |
| `justifyContent` | `flex-start`, `flex-end`, `center`, `space-between`, `space-around`, `space-evenly` | `<View>`, VStack/HStack |
| `alignItems` | `flex-start`, `flex-end`, `center`, `stretch` | `<View>`, VStack/HStack |
| `alignContent` | `flex-start`, `flex-end`, `center`, `space-between`, `space-around`, `stretch` | `<View>` |
| `gap`, `rowGap`, `columnGap` | pixels | `<View>` (`gap` also VStack/HStack) |
| `padding`, `paddingHorizontal`, `paddingVertical`, `paddingTop` … `paddingLeft` | pixels | `<View>`, VStack/HStack |
| `color`, `fontSize`, `fontWeight` (100–900, `'normal'`, `'bold'`), `textAlign` (`left`, `center`, `right`) | | `<Label>` |
| `transition` | milliseconds: style changes animate | every element |

Not supported (build errors that say why): `flexShrink`, `flexBasis` (Slint layouts don't shrink
items), `alignItems: 'baseline'`, `lineHeight`, named colours like `'red'`.

**Kit elements** (Button, Badge, Entry…) take only the outer-box styles: size, margin, flex,
position and opacity (Label also takes text styles). How a kit draws them is the kit's business: to
change it, copy the kit into your app with `ragekit eject <kit>`.

## Core elements

Every [kit](kits.md) provides these elements with the same attributes, so a template builds with
any kit. A kit may lack one; it then falls back to the Material kit's version.

| Element | Attributes | Notes |
|---|---|---|
| `Label` | `text`, `size` | `size`: `body` (default), `title`, `display` |
| `Text` | `variant`, the text as content | typography, see below |
| `Button` | `text`, `variant`, `onClick` | `variant`: `default`, `secondary`, `outline`, `ghost`, `destructive` |
| `Entry` | `value` | two-way `string` |
| `Checkbox` | `text`, `checked` | `checked`: two-way `bool` |
| `Slider` | `value`, `min`, `max` | `value`: two-way `float`; range defaults to 0 to 1 |
| `Select` | `value`, `options` | `value`: two-way `string`; `options`: a list |
| `Image` | `source` | a file path, `$assets->…` or a bound value (see "Image" below) |
| `Progress` | `value` | `float` from 0 to 1 |
| `Card` | `title` | holds other elements |
| `Separator` | (none) | a thin line |
| `Switch` | `text`, `checked` | `checked`: two-way `bool` |
| `Textarea` | `value`, `placeholder`, `rows` | multi-line; `value`: two-way `string`; `rows`: visible lines (default 3) |
| `RadioGroup` | `value`, `options` | `value`: two-way `string`, the chosen option |
| `Toggle` | `text`, `pressed` | a button that stays pressed; `pressed`: two-way `bool` |
| `Badge` | `text`, `variant` | `variant`: `default`, `secondary`, `outline`, `destructive` |
| `Avatar` | `source`, `text`, `size` | an image, else the `text` (initials); `size`: `sm`, `md` (default), `lg` |
| `Alert` | `title`, `text`, `variant` | `variant`: `default`, `destructive` |
| `Skeleton` | `width`, `height` | a pulsing placeholder while content loads, in pixels |
| `Spinner` | `size` | `size`: `sm`, `md` (default), `lg` |
| `Sidebar` | `title` | a navigation panel; holds other elements |
| `NavList` | `items`, `selected` | a vertical menu; `selected`: two-way `string`, the chosen item |
| `Breadcrumb` | `items`, `onSelect` | `onSelect="goTo"` calls `goTo(string $item)` with the clicked item |
| `Pagination` | `page`, `pages` | `page`: two-way `int` (from 1); `pages`: how many |
| `Accordion` / `Section` | `open` / `title` | see below |
| `Dialog` | `open`, `title` | over the whole window, see below |
| `DropdownMenu` / `MenuItem` | `text`, `variant` / `text`, `onClick`, `variant` | see below |
| `Tabs` / `Tab` | `selected` / `title` | see below |

### Text

```blade
<Text variant="h1">Settings</Text>
<Text variant="muted">Last saved {{ $savedAt }}</Text>
<Text variant="code">composer require ragekit/ragekit</Text>
```

React Native's `<Text>` with shadcn's typography. The text is the element's content, with
`{{ expressions }}` anywhere in it; spaces and line breaks collapse into one space, like HTML, and
long text wraps. `variant` is one of `h1`, `h2`, `h3`, `h4`, `p` (default), `lead`, `large`,
`small`, `muted`, `code`, `blockquote`; each kit draws them in its own type scale. Text styles
(`color`, `fontSize`, `fontWeight`, `textAlign`) override the variant. `<Text>` holds only text: no
other elements inside it (yet), and not both `text="…"` and content.

### Dialog

```blade
<Dialog open="{{ $confirmDelete }}" title="Delete playlist?">
    <Label text="This can't be undone." />
    <HStack>
        <Button text="Cancel" onClick="cancel" />
        <Button text="Delete" variant="destructive" onClick="delete" />
    </HStack>
</Dialog>
```

- `open` is a two-way `bool` property: a handler sets `$this->confirmDelete = true` to show the
  dialog and `false` to close it. A click outside the panel or Esc sets it back to `false`.
- The dialog covers the whole window wherever it's written (inside Tabs, a Card, a window's
  template), and
  the rest of the window is dimmed and can't be clicked. In a window with its own title bar that
  includes the title bar; the edges still resize the window.
- Its elements are laid out as a column; `title` is optional.
- Allowed in screen and window templates, not inside `@if`/`@foreach` (that's what `open` is
  for) and not in child components. If two are open, the later one in the template is on top.
  A dialog written inside a tab that isn't showing still opens when its property is true.

### Toasts

```php
public function save(): void
{
    // …
    $this->toast('Saved');
}
```

`$this->toast()` (in a screen or a window) shows a short message in the window's bottom-right
corner for about 3 seconds. Several stack up. Nothing goes in the template.

### DropdownMenu

```blade
<DropdownMenu text="⋯">
    <MenuItem text="Rename" onClick="rename" />
    <MenuItem text="Delete" variant="destructive" onClick="askDelete" />
</DropdownMenu>
```

A button that opens a list below it; picking an item runs its handler (arguments work as in any
`onClick`) and closes the list, as does a click elsewhere. The button takes Button's variants
(`outline` by default); a `MenuItem`'s `variant` is `default` or `destructive`. The items are fixed:
no `@if`/`@foreach` among them.

### Accordion

```blade
<Accordion open="{{ $open }}">
    <Section title="What is RageKit?">
        <Label text="PHP desktop apps, compiled, drawn by Slint." />
    </Section>
    <Section title="Which kits are there?">
        <Label text="shadcn and Material." />
    </Section>
</Accordion>
```

- One section is open at a time: `open` is a two-way `int` property, the open section's index
  (from 0), or -1 for none. Clicking a header opens it, or closes it if it's open.
- Each `<Section>` holds exactly one element (wrap several in a `<VStack>`); its `title` is fixed text.

Every element also accepts `grow`, `when` and `each`. Any other attribute is a build error that
lists the allowed ones.

Enum attributes (`size`, `variant`) take literal values only, not `{{ expressions }}`.

Elements other than `<Text>` can't contain text: write `<Label text="Hello" />`, not
`<Label>Hello</Label>`. Only
`View`, `Card`, `Dialog`, `VStack`, `HStack`, `Tabs` and `Tab` contain other elements.

Templates are XML, so `&` and `<` in plain attribute text are written `&amp;` and `&lt;` (a bare
`&` in `<Text>` content is fine). Inside `{{ }}` and directive arguments, write plain PHP
(`$a < $b && $c`).

### Image

```xml
<Image source="assets/logo.svg" />
```

`source` (on `Image`, `Avatar`, and any kit element with an `image` prop) takes:

| Written | Shows |
|---|---|
| `source="assets/logo.svg"` | that file, embedded into the app at build time (a missing file is a build error) |
| `source="{{ $assets->images->logo }}"` | an image the app registered (below), embedded |
| `source="{{ $cover }}"`, `{{ cover() }}`, `{{ $store->user->avatar }}` | whatever that string holds, while the app runs |

**Registered images.** List the images your app ships in a class of constants (paths relative to
the project; `ragekit make:assets Images` creates one) and register it in your App:

```php
namespace App\Assets;

final class Images
{
    public const LOGO = 'assets/images/logo.png';
    public const SUN = 'assets/images/weather/sun.svg';
}
```

```php
protected function assets(): array
{
    return ['images' => Images::class];
}
```

Templates show them as `$assets->images->logo` (`APP_LOGO` is written `appLogo`); PHP uses
`Images::LOGO`. The build checks every file exists and embeds them all, so a bound value can switch
between them without reading the disk: `public string $icon = Images::SUN;`.

**A bound source** is a string property, method or `$store` value, read on every update:

- `''` shows nothing;
- a registered image's path (`Images::SUN`) shows the embedded image;
- `http://…` / `https://…` is downloaded in the background and shown when it arrives. Every image
  showing the same URL shares one download, and the file is kept in the user's cache folder
  (`~/.cache/<app>/images` on Linux, `%LOCALAPPDATA%\<app>\images` on Windows,
  `~/Library/Caches/<app>/images` on macOS), so later runs show it straight away. Web images need
  the http module: `ragekit: { modules: [http] }`;
- anything else is a file, relative to the app's folder (next to the executable) unless absolute.

While an image is loading, or if it can't be loaded, it shows nothing, unless you give it a
`fallback` (a file path or `$assets->…`):

```xml
<Image source="{{ $avatarUrl }}" fallback="{{ $assets->images->placeholder }}" />
```

A problem prints one line, e.g. `ragekit: image https://example.com/a.png: HTTP 404`, and that
source isn't tried again until the app restarts.

**Inside `@foreach`**, each row shows its own image, resolved the same way: a string member of a
collection's items (`source="{{ $device->image }}"`, see
[Lists of objects](#lists-of-objects-collections)), or the item of a list of strings
(`@foreach ($photos as $photo) <Image source="{{ $photo }}" />`). `fallback` works per row. A
loop over a constant (`self::PHOTOS`) can't show images; use a property or method.

Not supported yet: bound images in child components (a fixed path or `$assets->…` works there),
expiring the cache, request headers for image downloads.

### Tabs

```blade
<Tabs selected="{{ $tab }}">
    <Tab title="Inputs">
        <VStack>
            <Entry value="{{ $name }}" />
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
- Tab titles are literal text; an expression there is a build error.
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

```blade
<!-- templates/History.rk -->
<VStack padded="false">
    @foreach ($entries as $entry)
        <Label text="{{ $entry }}" />
    @endforeach
</VStack>
```

```blade
<!-- in the screen's template -->
@if ($showHistory)
    <History entries="{{ $history }}" style="panel" />
@endif
```

A child component used with `style` takes the outer-box styles, from the *using* class's `STYLES`.

Current limits of child components:

- They only display what they're given. They can't handle events, contain two-way inputs or
  show computed methods; put interactive elements in the screen's template.
- Your PHP code never gets an instance of the child: the class only declares its props and its
  template.
- A child can't contain other elements (`<History>…</History>`).
- Components may use other components, but not in a cycle.

## Windows: a frame and a custom title bar

Every OS window is a `RageKit\Window`. The plain `Window` keeps the OS's title bar. A subclass of
`Window` with its own template is the frame around the window's screens: a title bar with the
app's own buttons, a sidebar, a footer, with its own state and handlers. A screen names its window
class:

```php
final class HomeScreen extends Screen implements TitleBarContext
{
    public static function template(): string
    {
        return 'templates/HomeScreen.rk';
    }

    public static function window(): string
    {
        return AppWindow::class;
    }

    public function title(): string
    {
        return 'Home';
    }
}
```

```php
final class AppWindow extends Window
{
    public string $query = '';

    public static function template(): string
    {
        return 'templates/AppWindow.rk';
    }

    /** What screens shown in this window must provide. */
    public static function context(): string
    {
        return TitleBarContext::class;
    }

    public function submit(): void
    {
        $this->context->search($this->query); // the screen on top
    }
}
```

```blade
<!-- templates/AppWindow.rk -->
<VStack padded="false">
    <WindowDrag>
        <Label text="{{ $context->title() }}" grow="true" />
        <Entry value="{{ $query }}" />
        <Button text="Search" onClick="submit" />
        <WindowControls />
    </WindowDrag>
    <Content />
</VStack>
```

- **`<Content />`** is where the screen goes. A window's template has exactly one.
- **A window with a template has no OS frame**: the template draws the title bar. The window keeps
  the OS's shadow (and, on Windows 11, rounded corners) and can still be resized from its edges.
- **`<WindowDrag>`** lays out its children like `<HStack>`. Pressing on its background and dragging
  moves the window; double-clicking it maximises or restores. Buttons and inputs inside it still
  work as usual. On Windows the OS does the move, so snapping to screen edges works; on Linux the
  app moves the window itself, without the window manager's snapping.
- **`<WindowControls />`** shows the kit's minimise, maximise/restore and close buttons. Close
  works like the OS's close button (on the main window it ends the app).
- **The context.** `context()` names an interface; every screen shown in the window must implement
  it, or the build fails. The window's template shows its methods as `{{ $context->title() }}`
  and calls them with `onClick="$context->refresh()"`; in PHP, `$this->context` is the screen on
  top. Inputs in a window's template bind the window's own properties (`{{ $query }}` above) or a
  store's; pass values on in a handler. A window's template uses the window's `STYLES`; the screen
  inside `<Content />` uses the screen's.
- **State.** Each OS window gets its own `Window` object, created by RageKit (so a subclass's
  constructor can't require arguments). `public bool $maximized` is kept up to date, also when the
  OS maximised the window. `minimize()`, `toggleMaximize()` and `close()` can be used from your own
  buttons, `back()` and `canGoBack()` from a back button. A screen reaches its window as
  `$this->currentWindow()`.
- `<Content />`, `<WindowDrag>` and `<WindowControls />` are only allowed in a window's template. A
  `Window` subclass without a template is a build error: screens that want the OS's title bar use
  `Window` itself (the default `window()`).
- Screens naming the same window class can be shown in one window, one after the other
  ([navigation](navigation.md)). A screen of another window class opens in its own window, with
  `$this->app->open()`.

**Linux:** the app moves and resizes its own window through X11 (the window manager can't take
the drag over, so there's no edge snapping), so apps with a window template run on XWayland in a
Wayland session (text can look blurry with fractional scaling). Set `RAGEKIT_WAYLAND=1` to stay on
Wayland, where the title bar can't move the window. The corners stay square on Linux.

`demo/titlebar` is a complete example.

## App-wide state: $store

State the whole app shares (who is signed in, settings) lives in stores, read in any screen's or
window's template as `{{ $store->session->userName }}`, called as
`onClick="$store->session->signOut()"` and bound two-way as
`checked="{{ $store->settings->darkMode }}"`. See [state.md](state.md).

## Moving between screens

A window can hold a stack of screens: `$this->navigator()->push(new DetailsScreen($id))` shows the
next one in the same window, `back()` returns to the previous one with its state kept. In a
window's template, `onClick="back"` and `@if (canGoBack())` make a back button; Alt+Left goes back too. See
[navigation.md](navigation.md).

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
| `the condition $x is an int; conditions need a bool` | Compare it: `$x > 0`, `count($items) > 0`, `$name !== ''`. |
| `== compares a string with an int` | Both sides must have the same type. |
| `@foreach must contain one element` | Wrap several elements in a `<View>` or `<VStack>`. |
| `@foreach is never closed with @endforeach` | Every `@foreach`/`@if` needs its `@endforeach`/`@endif`. |
| `style 'x' isn't in X::STYLES` | Add it to the class's `STYLES` constant (the class whose template it is). |
| `unknown property backgroundColour (did you mean backgroundColor?)` | Use React Native's property names (see [Styles](#styles)). |
| `<Button> can't take backgroundColor: kit elements only accept …` | Kit elements take size/margin/flex/position/opacity; to restyle the kit, `ragekit eject <kit>`. |
| `position: absolute needs a <View> parent` | Only a `<View>` can place children absolutely. |
| `property $x has type ?string; templates support string, int, float, bool and array (not nullable)` | Give the property a supported, non-nullable type, or show it through a computed method. |
| `<X> has no attribute 'y' (allowed: …)` | Use one of the listed attributes. |
| `unknown element <X>` | Not a core element or component; kit elements need their prefix. |
| `two-way inputs need a single property` | Bind one `{{ $property }}`, not text, a method or a `@foreach` item. |
| `expected a float property, $x is int` | Change the property's type to what the input edits. |
| `'x' is not a public method of X` | Handlers must be public methods of the screen. |
| `text content isn't supported, use text="…"` | Move the text into an attribute. |
| `components use each other in a cycle` | A component can't (indirectly) contain itself. |
| `X's template needs exactly one <Content />` | A window's template marks where the screen goes, once. |
| `<Content /> is only allowed in a Window's template` | Also `<WindowDrag>`, `<WindowControls>`, `context.x`: move them to the window's template. |
| `X uses W, which needs I; implement it` | The screen must implement the interface the window's `context()` names. |
| `'x' is not declared by I` | `$context->x()` can only call methods of the context interface. |
| `two-way inputs in a window's template must bind the window's own properties` | Bind a property of the window (or a store); pass it to the screen in a handler. |
| `W must be constructible without arguments` | RageKit creates the window; drop the constructor's required parameters. |
| `W extends Window: give it a template, or use Window itself` | A Window subclass draws the frame, so it needs `template()`; for the OS's title bar, leave `window()` out. |
| `X::window() names Y, which isn't a Window of this app` | `window()` returns a `Window` subclass (or isn't overridden). |
| `$store needs the app's RootStore` | Declare a class that extends `RageKit\RootStore` with your stores as typed public properties ([state](state.md)). |
| `$store->x: AppStore has no store 'x' (its stores: …)` | Use one of the listed stores, or add it to your RootStore. |
| `'x' is not a property or method of Session (it has: …)` | Check the spelling against the listed members. |
| `X has a property named $store` | `$store` is reserved for the stores; rename the property (or the loop variable). |
| `child components can't read $store yet` | Pass the value in as a property from the screen's template. |

## Older syntax

Templates written before Rageblade still build. `each=` and `when=` print a hint pointing here.

| Older | Rageblade |
|---|---|
| `text="Count: {count}"` | `text="Count: {{ $count }}"` (`{name}` still works anywhere) |
| `text="{greeting}"` (a method) | `text="{{ greeting() }}"` |
| `<Label each="x in items" text="{x}" />` | `@foreach ($items as $x) <Label text="{{ $x }}" /> @endforeach` |
| `<Label when="!ok" … />` | `@if (!$ok) <Label … /> @endif` |
| `onClick="open(file)"` | `onClick="open($file)"` |
| `{context.title}`, `onClick="context.refresh"` | `{{ $context->title() }}`, `onClick="$context->refresh()"` |

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
