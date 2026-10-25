<?php

declare(strict_types=1);

namespace RageKit\Slint\Dev;

use RageKit\App;
use RageKit\Component;
use RageKit\Navigator;
use RageKit\RootStore;
use RageKit\Screen;
use RageKit\Store;
use RageKit\Window;

/**
 * The dev runtime's state: the channel, the bindings, the open windows and their screens, and
 * the snapshot that carries public properties of screens, windows and stores across a PHP restart.
 */
final class Session
{
    private static ?Channel $channel = null;
    private static ?Bindings $bindings = null;
    private static string $screensFile = '';
    private static string $stateFile = '';
    private static ?App $app = null;
    private static ?Screen $pending = null;
    /** @var \Closure|null */
    private static $dispatcher = null;
    /** @var \Closure|null fn(string $module, int $handle, string $kind, string $data) */
    private static $eventDispatcher = null;
    private static bool $quitting = false;
    /** PHP has finished starting (slint_run sent `run`): snapshots may be written. */
    private static bool $running = false;
    /** @var array<int, array{screen: Screen, title: string, width: int, height: int}> by window id, in open order */
    private static array $windows = [];
    /** @var list<array{window: int, class: string, title: string, width: int, height: int, props: array<string, mixed>, windowProps?: array<string, mixed>, used?: bool}> */
    private static array $restore = [];
    /** @var array<string, array<string, mixed>> saved stores' public properties, by store name (until restored) */
    private static array $restoreStores = [];
    /** @var array<int, array{reset: bool, screens: list<array{class: string, props: array<string, mixed>}>}> saved stacks by the restored first screen (spl_object_id) */
    private static array $stacks = [];
    /** The saved window restore() last gave to a screen: its Window gets that window's properties. */
    private static ?int $restored = null;

    /** Starts over (also used by tests). $screensJson is the file's contents; $restart restores the snapshot. */
    public static function start(Channel $channel, string $screensJson, string $stateFile, bool $restart, string $screensFile = ''): void
    {
        self::$channel = $channel;
        self::$bindings = Bindings::load($screensJson);
        self::$screensFile = $screensFile;
        self::$stateFile = $stateFile;
        self::$app = null;
        self::$pending = null;
        self::$dispatcher = null;
        self::$eventDispatcher = null;
        self::$quitting = false;
        self::$running = false;
        self::$windows = [];
        self::$restore = [];
        self::$restored = null;
        self::$stacks = [];
        self::$restoreStores = [];
        if ($restart && is_file($stateFile)) {
            $saved = json_decode((string) file_get_contents($stateFile), true);
            self::$restore = $saved['windows'] ?? [];
            self::$restoreStores = $saved['stores'] ?? [];
        }
    }

    /** Started from the environment `ragekit dev` sets, on first use. */
    public static function boot(): void
    {
        if (self::$channel !== null) {
            return;
        }
        $screens = (string) getenv('RAGEKIT_DEV_SCREENS');
        self::start(
            Channel::connect(),
            (string) file_get_contents($screens),
            (string) getenv('RAGEKIT_DEV_STATE'),
            getenv('RAGEKIT_DEV_RESTART') === '1',
            $screens,
        );
    }

    public static function channel(): Channel
    {
        self::boot();
        return self::$channel;
    }

    public static function bindings(): Bindings
    {
        self::boot();
        return self::$bindings;
    }

    public static function reloadBindings(): void
    {
        if (self::$screensFile !== '') {
            self::$bindings = Bindings::load((string) file_get_contents(self::$screensFile));
        }
    }

    public static function setDispatcher(callable $dispatcher): void
    {
        self::$dispatcher = \Closure::fromCallable($dispatcher);
    }

    public static function dispatcher(): ?\Closure
    {
        return self::$dispatcher;
    }

    public static function setEventDispatcher(callable $dispatcher): void
    {
        self::$eventDispatcher = \Closure::fromCallable($dispatcher);
    }

    public static function eventDispatcher(): ?\Closure
    {
        return self::$eventDispatcher;
    }

    /**
     * Calls a native module's function in the dev shell (arguments and result as text).
     *
     * @param list<string> $args
     * @throws \RageKit\ModuleUnavailable when the shell couldn't load the module
     */
    public static function call(string $function, array $args): string
    {
        $reply = self::channel()->request('call', $function, ...$args);
        if ($reply[0] === 'error') {
            throw new \RageKit\ModuleUnavailable($reply[1] ?? "$function failed");
        }
        return $reply[1] ?? '';
    }

    public static function quit(): void
    {
        self::$quitting = true;
    }

    public static function quitting(): bool
    {
        return self::$quitting;
    }

    /**
     * Start-up (main() and reopening the windows from before a restart) is over. Until then a
     * snapshot would drop the windows not reopened yet, and an error in one of them would lose
     * them for good.
     */
    public static function markRunning(): void
    {
        self::$running = true;
    }

    public static function app(): ?App
    {
        return self::$app;
    }

    /** The screen a window is about to be opened for (Screens::newWindow() runs just before slint_open()). */
    public static function pending(Screen $screen): void
    {
        self::$pending = $screen;
    }

    /**
     * The Window for a window about to open with $screen. After a restart: $screen gets its saved
     * properties, the stores theirs (once: the app's createStore() has run by now, and no window has
     * synced yet), and the Window its saved state.
     */
    public static function windowFor(Screen $screen): Window
    {
        self::pending($screen);
        self::restore($screen);
        self::restoreStores();
        $window = self::bindings()->newWindow($screen);
        if (self::$restored !== null) {
            self::assignAll($window, self::$restore[self::$restored]['windowProps'] ?? []);
        }
        return $window;
    }

    /** A window synced: remembers the app it belongs to (to reopen windows and resync after a reload). */
    public static function adopt(Window $window): void
    {
        if (self::$app === null) {
            $app = new \ReflectionProperty(Component::class, 'app');
            if ($app->isInitialized($window)) {
                self::$app = $app->getValue($window);
            }
        }
    }

    public static function opened(int $window, string $title, int $width, int $height): void
    {
        if (self::$pending !== null && $window !== 0) {
            self::$windows[$window] = ['screen' => self::$pending, 'title' => $title, 'width' => $width, 'height' => $height];
        }
        self::$pending = null;
    }

    public static function closed(int $window): void
    {
        unset(self::$windows[$window]);
    }

    /** The Slint property behind a value id: 0 is the page (rk-page), others pN. */
    public static function property(int $id): string
    {
        return $id === 0 ? 'rk-page' : "p$id";
    }

    /**
     * Writes every open window's first screen (class, title, size, public properties), the screens
     * pushed on top of it (class and properties, bottom first) and its Window's properties, then
     * every store's public properties.
     */
    public static function snapshot(): void
    {
        if (self::$stateFile === '' || self::$quitting || !self::$running) {
            return;
        }
        $windows = [];
        foreach (self::$windows as $id => $window) {
            $saved = [
                'window' => $id,
                'class' => get_class($window['screen']),
                'title' => $window['title'],
                'width' => $window['width'],
                'height' => $window['height'],
                'props' => self::props($window['screen']),
            ];
            // The screens above the one the window opened with; after reset() (or replace() of the
            // first screen) the whole stack, marked 'reset': restoring replaces the reopened screen.
            $host = self::windowOf($window['screen']);
            $screens = $host === null ? [$window['screen']] : $host->navigator()->stack();
            $reset = $screens[0] !== $window['screen'];
            $stack = [];
            foreach ($reset ? $screens : array_slice($screens, 1) as $screen) {
                $stack[] = ['class' => get_class($screen), 'props' => self::props($screen)];
            }
            if ($stack !== []) {
                $saved['stack'] = $stack;
            }
            if ($reset) {
                $saved['reset'] = true;
            }
            if ($host !== null) {
                $props = self::props($host);
                unset($props['id']); // the backend's, given again when it reopens
                $saved['windowProps'] = $props;
            }
            $windows[] = $saved;
        }
        $stores = [];
        $root = RootStore::current();
        foreach ($root === null ? [] : get_object_vars($root) as $name => $store) {
            if ($store instanceof Store) {
                $stores[$name] = self::props($store);
            }
        }
        // Written next to it and renamed: a restart (SIGTERM) mid-write must not leave half a file.
        $temp = self::$stateFile . '.tmp';
        file_put_contents($temp, json_encode(['windows' => $windows] + ($stores === [] ? [] : ['stores' => $stores]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        rename($temp, self::$stateFile);
    }

    /** After a restart: gives $screen the properties of the next saved window of its class. */
    public static function restore(Screen $screen): void
    {
        self::$restored = null;
        foreach (self::$restore as $i => $saved) {
            if (($saved['used'] ?? false) || $saved['class'] !== get_class($screen)) {
                continue;
            }
            self::$restore[$i]['used'] = true;
            self::$restored = $i;
            self::assignAll($screen, $saved['props']);
            if (($saved['stack'] ?? []) !== []) {
                self::$stacks[spl_object_id($screen)] = ['reset' => $saved['reset'] ?? false, 'screens' => $saved['stack']];
            }
            return;
        }
    }

    /**
     * After a restart: pushes the screens that were on top of $n's first screen, each created
     * without constructor arguments and given its saved properties. A screen that can't be created
     * that way (it needs arguments, or its class is gone) ends the restore there. Runs once.
     */
    public static function restoreStack(Navigator $n): void
    {
        $id = spl_object_id($n->stack()[0]);
        $stack = self::$stacks[$id] ?? ['reset' => false, 'screens' => []];
        unset(self::$stacks[$id]);
        foreach ($stack['screens'] as $i => $saved) {
            $class = $saved['class'];
            $constructor = class_exists($class) ? (new \ReflectionClass($class))->getConstructor() : null;
            if (!class_exists($class) || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
                fwrite(STDERR, "ragekit dev: not restoring the screens from $class up (it can't be created without constructor arguments)\n");
                return;
            }
            $screen = new $class();
            self::assignAll($screen, $saved['props']);
            if ($i === 0 && $stack['reset']) {
                $n->reset($screen);
            } else {
                $n->push($screen);
            }
        }
    }

    /** The window showing $screen (null before it's shown in one). */
    private static function windowOf(Screen $screen): ?Window
    {
        $host = (new \ReflectionProperty(Screen::class, 'host'))->getValue($screen);
        return $host instanceof Window && self::hasNavigator($host) ? $host : null;
    }

    private static function hasNavigator(Window $window): bool
    {
        return (new \ReflectionProperty(Window::class, 'navigator'))->getValue($window) !== null;
    }

    /** After a restart: gives the app's stores their saved properties (a store that is gone is skipped). Runs once. */
    private static function restoreStores(): void
    {
        $root = RootStore::current();
        if ($root === null || self::$restoreStores === []) {
            return;
        }
        foreach (self::$restoreStores as $name => $props) {
            $store = property_exists($root, $name) ? $root->{$name} : null;
            if ($store instanceof Store) {
                self::assignAll($store, $props);
            } else {
                fwrite(STDERR, "ragekit dev: not restoring the store \$$name (" . get_class($root) . " no longer has it)\n");
            }
        }
        self::$restoreStores = [];
    }

    /** @param array<string, mixed> $props */
    private static function assignAll(object $object, array $props): void
    {
        foreach ($props as $name => $value) {
            $reason = self::assign($object, $name, $value);
            if ($reason !== null) {
                fwrite(STDERR, "ragekit dev: not restoring \$$name of " . get_class($object) . " ($reason)\n");
            }
        }
    }

    /** @return list<array{class: string, title: string, width: int, height: int}> saved windows nothing reopened yet */
    public static function leftovers(): array
    {
        $left = [];
        foreach (self::$restore as $saved) {
            if (!($saved['used'] ?? false)) {
                $left[] = ['class' => $saved['class'], 'title' => $saved['title'], 'width' => $saved['width'], 'height' => $saved['height']];
            }
        }
        return $left;
    }

    /**
     * Opens the saved windows nothing reopened yet (e.g. About, opened by a handler), via
     * $open(screen, title, width, height). A class that is gone or can't be constructed is
     * skipped: it would otherwise stop every restart.
     *
     * @param callable(object, string, int, int): void $open
     * @return list<string> what couldn't be reopened, and why
     */
    public static function reopenLeftovers(callable $open): array
    {
        $problems = [];
        foreach (self::leftovers() as $window) {
            try {
                $open(new $window['class'](), $window['title'], $window['width'], $window['height']);
            } catch (\Throwable $e) {
                $problems[] = "can't reopen {$window['class']}: " . $e->getMessage();
            }
        }
        return $problems;
    }

    /** @return array<string, mixed> */
    private static function props(object $component): array
    {
        $props = [];
        foreach ((new \ReflectionObject($component))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->isReadOnly() || !$property->isInitialized($component)) {
                continue;
            }
            $value = $property->getValue($component);
            if ($value instanceof \RageKit\Collection) {
                $props[$property->getName()] = self::collection($value);
            } elseif (self::plain($value)) {
                $props[$property->getName()] = $value;
            }
        }
        return $props;
    }

    /**
     * A collection as state.json keeps it: its class and each item's class and plain public
     * properties.
     *
     * @return array{'@collection': string, items: list<array<string, mixed>>}
     */
    private static function collection(\RageKit\Collection $collection): array
    {
        $items = [];
        foreach ($collection->all() as $item) {
            $items[] = ['@class' => get_class($item)] + self::props($item);
        }
        return ['@collection' => get_class($collection), 'items' => $items];
    }

    /** A saved collection rebuilt; an item property that no longer fits keeps its default. */
    private static function rebuild(array $saved): ?\RageKit\Collection
    {
        $class = (string) ($saved['@collection'] ?? '');
        if (!class_exists($class) || !is_subclass_of($class, \RageKit\Collection::class)) {
            return null;
        }
        $collection = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        foreach ((array) ($saved['items'] ?? []) as $data) {
            $itemClass = (string) ($data['@class'] ?? '');
            if (!class_exists($itemClass)) {
                continue;
            }
            // The constructor when it needs no arguments: promoted properties get their defaults.
            $reflection = new \ReflectionClass($itemClass);
            $constructor = $reflection->getConstructor();
            $item = $constructor === null || $constructor->getNumberOfRequiredParameters() === 0 ? $reflection->newInstance() : $reflection->newInstanceWithoutConstructor();
            unset($data['@class']);
            foreach ($data as $name => $value) {
                self::assign($item, (string) $name, $value);
            }
            try {
                $collection->add($item);
            } catch (\InvalidArgumentException) {
                // OF changed: the item no longer belongs here.
            }
        }
        return $collection;
    }

    private static function plain(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::plain($item)) {
                    return false;
                }
            }
            return true;
        }
        return $value === null || is_scalar($value);
    }

    /** @return string|null why the value can't be restored */
    private static function assign(object $component, string $name, mixed $value): ?string
    {
        $class = new \ReflectionObject($component);
        if (!$class->hasProperty($name)) {
            return 'no longer declared';
        }
        $property = $class->getProperty($name);
        if (!$property->isPublic() || $property->isStatic() || $property->isReadOnly()) {
            return 'no longer a public property';
        }
        $type = $property->getType();
        if (is_array($value) && isset($value['@collection'])) {
            $collection = self::rebuild($value);
            if ($collection === null || !($type instanceof \ReflectionNamedType) || !is_a($collection, $type->getName())) {
                return 'its type changed';
            }
            $property->setValue($component, $collection);
            return null;
        }
        if ($type instanceof \ReflectionNamedType && !($value === null && $type->allowsNull())) {
            $ok = match ($type->getName()) {
                'int' => is_int($value),
                'float' => is_int($value) || is_float($value),
                'string' => is_string($value),
                'bool' => is_bool($value),
                'array' => is_array($value),
                'mixed' => true,
                default => false,
            };
            if (!$ok) {
                return 'its type changed';
            }
            if ($type->getName() === 'float') {
                $value = (float) $value;
            }
        }
        $property->setValue($component, $value);
        return null;
    }
}
