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
        Timer::attach($this->backend);
        Links::attach($this->backend);
        LocalTime::attach($this->backend);
        Clipboard::attach($this->backend);
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

    /**
     * The app's images by group, e.g. `['images' => Images::class]`: classes of string constants
     * holding project-relative paths. Templates show them as `$assets->images->logo`; the build
     * checks the files exist and embeds them.
     *
     * @return array<string, string>
     */
    protected function assets(): array
    {
        return [];
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

    /**
     * A link with one of the app's schemes (project.yml `ragekit: { links: [...] }`) was opened: the
     * app was started by it, or another launch handed it over. The main window has been brought to
     * the front already. Runs on the UI thread, after start(), like a click.
     */
    protected function linkOpened(string $url): void
    {
    }

    /**
     * Creates the store, opens the main window, calls start(), then runs until quit(). An app that
     * declares links runs once: a second launch hands its link to the first and returns at once.
     */
    public function run(): void
    {
        if ($this->backend->links() !== [] && !$this->backend->claimInstance()) {
            return;
        }
        Events::on('@links', 0, 'open', function (string $url): void {
            $this->linkReceived($url);
        });
        Events::on('@links', 0, 'focus', function (string $data): void {
            $this->linkReceived('');
        });
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

    /**
     * @internal Another launch handed over a link ('' = only "come to the front"), or this launch was
     * started by one. (Public: the event closures call it, and TypePHP checks private access by the
     * object's own class.)
     */
    public function linkReceived(string $url): void
    {
        if ($this->main !== null) {
            if ($this->main->visible()) {
                $this->main->raise();
            } else {
                $this->main->show(); // hidden in the tray: back on screen (show() raises it too)
            }
        }
        if ($url !== '') {
            $this->linkOpened($url);
        }
    }

    /** @internal The backend caught an exception from a handler or a module event. */
    public function exceptionThrown(\Throwable $e): void
    {
        $this->uncaughtException($e);
    }

    /** A folder for the app's cache files (created), e.g. ~/.cache/<app> on Linux. Safe to delete. */
    public function cacheDir(): string
    {
        return $this->backend->cacheDir();
    }

    /** The active backend, for backend-specific APIs. */
    public function backend(): Backend
    {
        return $this->backend;
    }
}
