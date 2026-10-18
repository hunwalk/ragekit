<?php

namespace RageKit;

/**
 *     $app = new App();
 *     $app->open(new CounterScreen(), title: 'Counter');
 *     $app->run(); // returns when the last window closes
 *
 * Screens and components reach it as $this->app, so any of them can open more windows. The actual UI
 * toolkit is the backend selected in the app's project.yml (`ragekit: { backend: … }`).
 */
final class App
{
    private Backend $backend;

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

    /** Opens a window showing $screen; returns its window id. */
    public function open(Screen $screen, string $title = 'RageKit', int $width = 480, int $height = 320): int
    {
        return $this->backend->open($this, $screen, $title, $width, $height);
    }

    /** Closes a window. Closing the last one ends run(). */
    public function close(int $window): void
    {
        $this->backend->close($window);
    }

    /** Ends run() even if windows are still open. */
    public function quit(): void
    {
        $this->backend->quit();
    }

    /** Runs the event loop until the last window closes (or quit()). */
    public function run(): void
    {
        $this->backend->run();
    }

    /** The active backend, for backend-specific APIs. */
    public function backend(): Backend
    {
        return $this->backend;
    }
}
