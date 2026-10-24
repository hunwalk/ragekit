# Generators: `ragekit make:`

Like Laravel's `php artisan make:`, `ragekit make:` creates the files for a new piece of your app in
the place it belongs. Run it in your app's folder (the one with `project.yml`):

```sh
vendor/bin/ragekit make:screen Login       # src/Screens/LoginScreen.php + templates/Screens/LoginScreen.rk
vendor/bin/ragekit make:window App         # src/Windows/AppWindow.php + templates/Windows/AppWindow.rk
vendor/bin/ragekit make:component UserBadge # src/Components/UserBadge.php + templates/Components/UserBadge.rk
vendor/bin/ragekit make:store Session      # src/Stores/Session.php, added to your AppStore
vendor/bin/ragekit make:service GithubApi  # src/Services/GithubApi.php
vendor/bin/ragekit make:flow LoadUser      # src/Flows/LoadUser.php
vendor/bin/ragekit make:assets Images      # src/Assets/Images.php: register it in your App's assets()
```

## Where things go

```
src/
  MyApp.php            the app class: first screen, store, start()
  Screens/             screens
  Components/          child components (used as <UserBadge /> in other templates)
  Windows/             windows with their own title bar
  Stores/              AppStore (the root store) and its stores
  Flows/               flows: one user action step by step (see state.md)
  Services/            outside calls: API clients, files, native modules
  Assets/              classes listing the app's images ($assets->images->logo in templates)
templates/             .rk templates, in the same folders as their classes
assets/                images and other files the app reads at runtime
```

Stores, flows and services are the split described in [state.md](state.md#fetching-data-store-api-client-flow):
the store holds state, the service talks to the outside world, the flow is the glue.

### Templates find themselves

A screen, window or component that doesn't override `template()` uses the file at the same path
as its class, under `templates/` with `.rk`: `src/Screens/Auth/LoginScreen.php` →
`templates/Screens/Auth/LoginScreen.rk`. "Under `src/`" means under the folder listed in
`sources:` in `project.yml` that holds the class. If the file is missing, the build says which one
to create. Overriding `template()` still works and wins.

## Names

- `make:screen Login` and `make:screen LoginScreen` both create `LoginScreen`; windows get
  `Window` the same way. Components, stores, services and flows keep the name you give.
- `make:screen Auth/Login` creates `src/Screens/Auth/LoginScreen.php` in the namespace
  `App\Screens\Auth`.
- The namespace is the one your `composer.json` maps to `src/` (`"App\\": "src/"` in the
  starter); without one, `App` is used and the command says so.
- Without a name, the command asks for one.
- Names PHP or RageKit can't use are refused: reserved words (`List`, `Match`, `Default`…), RageKit's
  base classes (`Screen`, `Window`, `Component`, `Store`, `RootStore`) and `Box` (TypePHP reserves it).
- Class names must be unique across the app (the build needs that), so a name already used in
  `src/` is refused. Don't name a component like a core element (`Button`, `Avatar`, …): the
  template would use yours instead.

## What the commands do and don't do

- An existing file is never overwritten, unless you add `--force`.
- `make:store` adds the store to your root store: a `public Session $session;` property and
  `$this->session = new Session();` in its constructor. Without a root store it creates
  `src/Stores/AppStore.php`; return it from your App's `createStore()`. If it can't safely edit
  your root store (no constructor, the property is already there), it changes nothing and prints
  the two lines to add.
- Screens and windows aren't wired up for you: the command prints how to show the screen
  (`return new LoginScreen();` from `firstScreen()`, or `$this->navigator()->push(new LoginScreen())`)
  or use the window (`window()` in a screen).

## Changing what make: writes

```sh
vendor/bin/ragekit stub:publish
```

copies the stubs `make:` writes from into your app's `stubs/` folder (`screen.php.stub`,
`screen.rk.stub`, …). Edit them; from then on `make:` uses yours. They use two placeholders:
`{{ namespace }}` and `{{ class }}` (a template stub only `{{ class }}`); you can leave either
out. Rageblade's own `{{ $expression }}` passes through untouched. `--force` copies RageKit's
stubs again over yours.
