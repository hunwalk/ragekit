# Moving between screens

A window shows one screen at a time, but it can hold a **stack** of them, like React Navigation or
Android's back stack. A handler pushes the next screen; the window switches to it in place (same
window, same position and size) and the screen underneath keeps its state until you come back.

```php
final class AlbumsScreen extends Screen implements GalleryContext
{
    public string $last = 'none yet';

    public static function template(): string { return 'templates/Albums.rk'; }
    public static function window(): string { return AppWindow::class; }
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
throws ("no window yet").

## From the app class

The navigator belongs to the window, so code outside a screen reaches it through the window:
`$this->main()->navigator()` in the app class (the main window), or the `Window` that
`$this->app->open()` returned. That's how a store event becomes navigation, without the store
knowing about screens:

```php
protected function start(): void
{
    AppStore::get()->session->on('signedOut', function (): void {
        $this->main()->navigator()->reset(new LoginScreen());
    });
}
```

See [state.md](state.md#events-stores-and-navigation-stay-apart).

## Back

- A window has `back()` and `canGoBack()` too, so a back button in its title bar is one line:

  ```
  @if (canGoBack())
      <Button text="←" variant="ghost" onClick="back" />
  @endif
  <Label text="{{ $context->title() }}" grow="true" />
  ```

  The window's `$context` is always the screen on top, so the title follows it.
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

- **Screens in one window share its window class.** Every screen pushed into a window must name the
  same `window()` as its first screen (or leave it out, like it). Pushing another one is an error
  ("SettingsScreen uses window SettingsWindow; this window is AppWindow"). Open such a screen in its
  own window with `$this->app->open()`.
- A covered screen is a plain PHP object: its properties stay as they were. What the window showed
  of it (scroll position, focus) is rebuilt when it comes back. Two screens of the same class in a
  row share their page, so that part carries over from one to the other.
- Toasts belong to the window: one fired just before `push()`, `back()` or `reset()` stays up on
  the screen shown next.
- `ragekit dev` keeps the whole stack across PHP restarts: the screens are created again without
  constructor arguments and get their public properties back. A screen whose constructor needs
  arguments ends the restore there (the screens under it come back).

## How it works

All screens that use the same window class (and all that use `Window` itself) compile into one
Slint window component, each screen a page shown while the window's `rk-page` is its index, like
`<Tabs>`. Only the page shown exists in Slint; the others are only PHP objects, so a deep stack
costs nothing in the UI. See `demo/navigator` (sign in → albums → photos → one photo, with a dialog, a toast and
Sign out in the title bar).
