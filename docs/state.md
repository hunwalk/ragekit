# App-wide state

A screen's public properties are that screen's state. State that belongs to the whole app (who is
signed in, settings, a cart) lives in **stores**: plain PHP classes that every screen, window and
template can reach. There is no Redux machinery, no container and no reducers: a store is an
object with properties and methods, and the windows show its values like any other.

## The root store and its stores

The app has one **root store**, a class that extends `RageKit\RootStore`. Each store is a typed
public property on it, created in its constructor:

```php
use RageKit\RootStore;
use RageKit\Store;

final class AppStore extends RootStore
{
    public Session $session;
    public Settings $settings;

    public function __construct()
    {
        parent::__construct();
        $this->session = new Session();
        $this->settings = new Settings();
    }
}

final class Session extends Store
{
    /** '' = nobody (templates don't show null). */
    public string $userName = '';

    public function signedIn(): bool
    {
        return $this->userName !== '';
    }

    public function signIn(string $name): void
    {
        $this->userName = $name;
        $this->emit('signedIn');
    }

    public function signOut(): void
    {
        $this->userName = '';
        $this->emit('signedOut');
    }
}
```

Stores that need each other are wired in that constructor, in plain PHP
(`new Cart($this->session)`).

The app class creates the root in `createStore()`; `run()` calls it first, before the first screen
is made, so screens can read the store from their constructors:

```php
final class GalleryApp extends App
{
    protected function createStore(): RootStore
    {
        return new AppStore();
    }

    protected function firstScreen(): Screen
    {
        return AppStore::get()->session->signedIn() ? new AlbumsScreen() : new LoginScreen();
    }
}
```

An app that doesn't override `createStore()` gets an empty `RootStore`.

## Reading and changing it from PHP

`AppStore::get()` returns the app's root as your own class, typed, so your editor and TypePHP know
`->session` is a `Session`:

```php
public function save(): void
{
    AppStore::get()->settings->darkMode = $this->dark;
}
```

It works anywhere in the app: screens, windows, your own classes. A change shows in every window
after the event that made it, like a change to a screen's property.

## In templates

Templates of screens and windows read the stores as `$store->name->member`, the full path:

```blade
@if ($store->session->signedIn())
    <Label text="Signed in as {{ $store->session->userName }}" />
    <Button text="Sign out" variant="ghost" onClick="$store->session->signOut()" />
@else
    <Button text="Sign in" onClick="$store->session->signIn($name)" />
@endif
<Switch text="Dark mode" checked="{{ $store->settings->darkMode }}" />
```

- **Values:** `{{ $store->session->userName }}`, and methods without required parameters,
  `{{ $store->session->signedIn() }}`, in text, `@if`, `@foreach`, `@style` conditions and
  attributes, like a screen's own.
- **Handlers:** `onClick="$store->session->signIn($name)"` calls the store's method; arguments work
  as in any handler.
- **Lists of objects:** a store member that is a collection (`public Devices $devices;`, see
  [Lists of objects](templates.md#lists-of-objects-collections)) is looped like a screen's:
  `@foreach ($store->devices->devices as $device)`. Every window showing it follows changes to it
  and to its items.
- **Two-way inputs** may bind a store property: `checked="{{ $store->settings->darkMode }}"`. The
  user's edits go straight into the store.
- **Every window follows it.** Two windows showing `$store->session->userName` both update after
  either one (or any handler) changes it.
- Everything is checked when you build: a store or member that doesn't exist is a build error that
  lists what does.
- `$store` is reserved: a screen property or a loop variable named `store` is a build error.
- **Child components can't read `$store` yet.** Pass the value in as a property:
  `<UserBadge name="{{ $store->session->userName }}" />`.

## Events: stores and navigation stay apart

A store never navigates, and the navigator never writes into a store. A store says *what
happened* with a named event, `$this->emit('signedOut')`, and whoever cares listens with `on()`.
The place that decides what an event means for the windows is the app class's `start()`, which
runs once the main window is open:

```php
final class GalleryApp extends App
{
    protected function start(): void
    {
        $session = AppStore::get()->session;
        $session->on('signedIn', function (): void {
            $this->main()->navigator()->reset(new AlbumsScreen());
        });
        $session->on('signedOut', function (): void {
            $this->main()->navigator()->reset(new LoginScreen());
        });
    }
}
```

Now Sign out works the same from the title bar, from a settings screen or from an HTTP answer
saying the key has expired: each of them only calls `signOut()`. Listeners run right away, in the
order they were added; events carry no arguments (read what you need from the store).

`demo/navigator` is a complete example: a sign-in screen, the user's name and Sign out in the
title bar, and the app class moving between them.

## Fetching data: store, API client, flow

RageKit ships the store and nothing else; the rest is plain PHP. A split that keeps each part
small, shown for "look up a GitHub user":

```php
/** State only: what the UI shows. Registered on the root as `public GithubStore $github`. */
final class GithubStore extends Store
{
    public bool $fetching = false;
    public string $name = '';
    public string $error = '';
}

/** HTTP only: knows GitHub's URLs and JSON, nothing about the UI. */
final class GithubApi
{
    /** @param \Closure $found (string $name) · @param \Closure $failed (string $why) */
    public static function findUser(string $login, \Closure $found, \Closure $failed): void
    {
        Http::get('https://api.github.com/users/' . rawurlencode($login))
            ->header('Accept', 'application/vnd.github+json')
            ->timeout(10)
            ->send(function (Response $r) use ($login, $found, $failed): void {
                $data = $r->ok() ? json_decode($r->body, true) : null;
                if (is_array($data)) {
                    $found((string) ($data['name'] ?? $login));
                } else {
                    $failed($r->error !== '' ? $r->error : 'GitHub answered ' . $r->status);
                }
            });
    }
}

/** The flow: what happens when the user asks, step by step. */
final class LoadUser
{
    public static function run(string $login): void
    {
        $github = AppStore::get()->github;
        $github->fetching = true;
        $github->error = '';
        GithubApi::findUser($login, function (string $name) use ($github): void {
            $github->fetching = false;
            $github->name = $name;
        }, function (string $why) use ($github): void {
            $github->fetching = false;
            $github->error = $why;
        });
    }
}
```

A screen's handler starts the flow (`LoadUser::run($this->login)`), and the template shows the
store:

```blade
@if ($store->github->fetching)
    <Spinner />
@elseif ($store->github->error !== '')
    <Alert variant="destructive" title="Couldn't load the user" text="{{ $store->github->error }}" />
@else
    <Label text="{{ $store->github->name }}" size="title" />
@endif
```

The window never waits: the request runs on a worker thread, and its callback (the "after the
call" a saga would wait for) runs on the UI thread, after which every window shows the new values.
(`Image` takes only file paths for now, so the example shows the user's name, not their picture.)

## Timers: polling and delays

`RageKit\Timer` runs a function later, once or again and again. Like an HTTP callback it runs on
the UI thread, and every window shows the new values after it:

```php
use RageKit\Timer;

final class InboxStore extends Store
{
    public int $unread = 0;

    private ?Timer $poll = null;

    public function startPolling(): void
    {
        $this->poll = Timer::every(5, fn() => RefreshInbox::run());   // every 5 seconds
    }

    public function stopPolling(): void
    {
        $this->poll?->stop();
    }
}

Timer::after(2, fn() => $this->hint = '');   // once, in 2 seconds
```

- `every($seconds, $fn)` takes more than 0 seconds; `after($seconds, $fn)` also takes 0, which
  means "on the event loop's next turn". Fractions work (`0.25`); the native timer counts whole
  milliseconds.
- `stop()` may be called from inside `$fn`, and more than once. `active()` says whether it will
  still run: false after `stop()`, and for `after()` as soon as it fires.
- An exception `$fn` doesn't catch goes to `App::uncaughtException()`, like a click handler's; a
  repeating timer keeps running.
- Timers need the App's backend, so start them in `createStore()`, `start()` or later, not before
  `new MyApp()`. They fire only while the app runs. A tick never interrupts a running handler:
  it waits for the UI thread like a click.
- In `ragekit dev` the dev shell runs the timers. A PHP restart stops them, and the new PHP's
  `start()` starts its own (a Timer in a property isn't kept across restarts).

## Local time

PHP's `date()` in an app uses UTC: the app's PHP has no timezone database. `RageKit\LocalTime` asks
the operating system instead, so a server's time shows as the user's clock does:

```php
use RageKit\LocalTime;

LocalTime::format('H:i', strtotime($message['created_at']));   // date()'s formats, local time
LocalTime::offset(time());                                      // seconds east of UTC, e.g. 7200
```

It follows the computer's timezone setting, daylight saving included (on Linux also `TZ`). The format
letters that describe the timezone itself (`e I O P p T Z U`) aren't supported, since they would
describe UTC; `format()` throws for them (use `offset()`).

## Tests

A root store is a plain object: no App, no window and no backend needed. Creating one replaces the
previous root, so every test starts clean:

```php
public function testSignOutForgetsTheUser(): void
{
    $store = new AppStore();
    $store->session->signIn('ann');
    $events = [];
    $store->session->on('signedOut', function () use (&$events): void { $events[] = 'signedOut'; });

    $store->session->signOut();

    self::assertSame('', AppStore::get()->session->userName);
    self::assertSame(['signedOut'], $events);
}
```

## Dev mode

`ragekit dev` keeps the stores across PHP restarts, like the screens: the public properties of
every store (strings, numbers, bools and lists) are saved after each event and put back into the
new PHP's stores before the first window shows anything. After an edit you are still signed in,
on the same screen. Listeners aren't saved: the new PHP's `start()` adds them again.

## Not yet

Saving stores to disk between runs, more app lifecycle hooks than `start()`, and middleware are
planned; nothing in the API above will need to change for them.
