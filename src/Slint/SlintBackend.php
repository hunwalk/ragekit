<?php

namespace RageKit\Slint;

use RageKit\App;
use RageKit\Backend;
use RageKit\Screen;

/**
 * Slint UI compiled ahead of time: `ragekit build` turns every Screen's .rk template into a Slint
 * component in libragekit_slint, plus RageKit\Generated\Screens to feed and dispatch it.
 */
final class SlintBackend implements Backend
{
    /** @var array<int, SlintWindow> open windows by id */
    private array $windows = [];

    public function __construct()
    {
        \slint_set_dispatcher(function (int $window, int $action, string $arg): void {
            $this->dispatch($window, $action, $arg);
        });
        \slint_set_event_dispatcher(function (string $module, int $handle, string $kind, string $data): void {
            \RageKit\Events::deliver($module, $handle, $kind, $data);
            foreach ($this->windows as $open) {
                $open->sync();
            }
        });
    }

    public function open(App $app, Screen $screen, string $title, int $width, int $height): int
    {
        $screen->mount($app);
        $window = new SlintWindow($screen, $title, $width, $height);
        $this->windows[$window->id] = $window;
        return $window->id;
    }

    public function close(int $window): void
    {
        if (!isset($this->windows[$window])) {
            return;
        }
        unset($this->windows[$window]);
        \slint_close($window);
        if (count($this->windows) === 0) {
            \slint_quit();
        }
    }

    public function minimize(int $window): void
    {
        \slint_window_minimize($window);
    }

    public function setMaximized(int $window, bool $maximized): void
    {
        \slint_window_set_maximized($window, $maximized);
    }

    public function quit(): void
    {
        \slint_quit();
    }

    public function run(): void
    {
        \slint_run();
    }

    public function appDir(): string
    {
        return \slint_app_dir();
    }

    /** Pushes every window's values again (dev mode, after a template reload). */
    public function resync(): void
    {
        foreach ($this->windows as $window) {
            $window->resync();
        }
    }

    /** Every UI event: action 0 closes the window, others run a handler; then all windows re-sync. */
    private function dispatch(int $window, int $action, string $arg): void
    {
        if ($action === 0) {
            $this->close($window);
            return;
        }
        $target = $this->windows[$window] ?? null;
        if ($target === null) {
            return;
        }
        $target->handle($action, $arg);
        foreach ($this->windows as $open) {
            $open->sync();
        }
    }
}
