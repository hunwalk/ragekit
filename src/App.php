<?php

namespace RageKit;

/**
 * The app itself: extend it, name the first screen, and run it.
 *
 *     final class GalleryApp extends App
 *     {
 *         protected function firstScreen(): Screen
 *         {
 *             return new AlbumsScreen();
 *         }
 *     }
 *
 *     function main(): void
 *     {
 *         (new GalleryApp())->run();
 *     }
 *
 * run() creates the root store (createStore()), opens the main window with firstScreen(), calls
 * start() — the place to wire store events to navigation — and runs until the main window
 * closes. Screens and components reach the app as $this->app. The UI toolkit is the backend
 * selected in the app's project.yml (`ragekit: { backend: … }`).
 */
abstract class App
{
    private Backend $backend;

    private ?Window $main = null;

    /** The app's folder, from the first App's backend (see resolve()). */
    private static string $base = '';

    public function __construct()
    {
        $this->backend = \ragekit_backend();
        if (self::$base === '') {
            self::$base = $this->backend->appDir();
        }
    }

    /**
     * A path relative to the app's folder (where `ragekit: assets:` are copied; the project in
     * dev mode). Absolute paths are returned unchanged.
     */
    public static function resolve(string $path): string
    {
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1) {
            return $path;
        }
        $base = self::$base === '' ? (string) getcwd() : self::$base;
        return $base . '/' . $path;
    }

    /** The screen the main window starts with. */
    abstract protected function firstScreen(): Screen;

    /** The app's root store, e.g. `return new AppStore();` (default: an empty one). */
    protected function createStore(): RootStore
    {
        return new RootStore();
    }

    /** Runs once the main window is open: wire store events to navigation here. */
    protected function start(): void
    {
    }

    /** The main window's title. */
    protected function title(): string
    {
        return 'RageKit';
    }

    /** The main window's width. */
    protected function width(): int
    {
        return 480;
    }

    /** The main window's height. */
    protected function height(): int
    {
        return 320;
    }

    /**
     * The main window was closed. By default the app ends (every other window closes too); an app
     * that keeps running in the background would override it.
     */
    protected function mainWindowClosed(): void
    {
        $this->quit();
    }

    /**
     * A handler or a module event threw and nothing caught it. By default it's printed to stderr
     * and the app keeps running (what changed before the throw is still shown); override it to
     * show or log it instead.
     */
    protected function uncaughtException(\Throwable $e): void
    {
        $this->backend->printError('ragekit: uncaught ' . get_class($e) . ': ' . $e->getMessage());
    }

    /** Creates the store, opens the main window, calls start(), then runs until quit(). */
    public function run(): void
    {
        RootStore::register($this->createStore());
        $this->main = $this->open($this->firstScreen(), $this->title(), $this->width(), $this->height());
        $this->start();
        $this->backend->run();
    }

    /** The main window (the one firstScreen() opened in). */
    public function main(): Window
    {
        return $this->main ?? throw new \LogicException('no main window yet: it opens in run()');
    }

    /** Opens another window showing $screen. */
    public function open(Screen $screen, string $title = 'RageKit', int $width = 480, int $height = 320): Window
    {
        return $this->backend->open($this, $screen, $title, $width, $height);
    }

    /** Closes every window and ends run(). */
    public function quit(): void
    {
        $this->backend->quit();
    }

    /** @internal A window is being closed (by the OS or its close()): the main window ends the app. */
    public function closeRequested(Window $window): void
    {
        if ($window === $this->main) {
            $this->mainWindowClosed();
            return;
        }
        $this->backend->close($window);
    }

    /** @internal The backend caught an exception from a handler or a module event. */
    public function exceptionThrown(\Throwable $e): void
    {
        $this->uncaughtException($e);
    }

    /** The active backend, for backend-specific APIs. */
    public function backend(): Backend
    {
        return $this->backend;
    }
}
