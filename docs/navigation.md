# Moving between screens

A window shows one screen at a time, but it can hold a **stack** of them, like React Navigation or
Android's back stack. A handler pushes the next screen; the window switches to it in place (same
window, same position and size) and the screen underneath keeps its state until you come back.

```php
final class AlbumsScreen extends Screen implements GalleryContext
{
    public string $last = 'none yet';

    public static function template(): string { return 'templates/Albums.rk'; }
    public static function layout(): string { return AppLayout::class; }
    public function title(): string { return 'Albums'; }

    public function show(string $album): void
    {
        $this->last = $album;                                  // still there after going back
        $this->navigator()->push(new PhotosScreen($album));    // values go in through the constructor
    }
}
```

`$this->navigator()` is the window's stack, in any screen shown in a window:

| Call | Does |
|---|---|
| `push($screen)` | shows `$screen` on top; the current one stays underneath |
| `back()` | drops the top screen and shows the one under it; returns `false` (and does nothing) on the first screen |
| `replace($screen)` | swaps the top screen for `$screen` |
| `reset($screen)` | `$screen` becomes the only screen |
| `canGoBack()` | whether there's a screen to go back to |
| `top()`, `depth()` | the screen shown, how many are on the stack |

The navigator exists once the screen is shown in a window: calling `navigator()` in a constructor
throws ("no navigator yet").

## Back

- A layout has `back()` and `canGoBack()` too, so a back button in the title bar is one line:

  ```
  @if (canGoBack())
      <Button text="←" variant="ghost" onClick="back" />
  @endif
  <Label text="{{ $context->title() }}" grow="true" />
  ```

  The layout's `$context` is always the screen on top, so the title follows it.
- **Alt+Left** goes back too (unless the focused element uses the key itself, e.g. a text field).

## Hooks

`shown()` runs when a screen becomes the one the window shows (also when `back()` returns to it),
`hidden()` when another screen covers it or it's dropped. Both are empty by default; `shown()` is
the place to reload data when the user comes back:

```php
public function shown(): void
{
    $this->photos = Photos::in($this->album);
}
```

## Rules

- **Screens in one window share its layout.** Every screen pushed into a window must name the same
  `layout()` as its first screen (or none, like it). Pushing another one is an error
  ("PhotosScreen uses layout X; this window's layout is Y"). Open such a screen in its own window
  with `$app->open()`.
- A covered screen is a plain PHP object: its properties stay as they were. What the window showed
  of it (scroll position, focus) is rebuilt when it comes back. Two screens of the same class in a
  row share their page, so that part carries over from one to the other.
- Toasts belong to the window: one fired just before `push()`, `back()` or `reset()` stays up on
  the screen shown next.
- `ragekit dev` keeps the whole stack across PHP restarts: the screens are created again without
  constructor arguments and get their public properties back. A screen whose constructor needs
  arguments ends the restore there (the screens under it come back).

## How it works

All screens that use the same layout (and all that use none) compile into one Slint window
component, each screen a page shown while the window's `rk-page` is its index, like `<Tabs>`. Only
the page shown exists in Slint; the others are only PHP objects, so a deep stack costs nothing in
the UI. See `demo/navigator` (albums → photos → one photo, with a dialog and a toast).
