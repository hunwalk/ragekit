<?php

namespace RageKit\Slint;

use RageKit\App;
use RageKit\Backend;
use RageKit\Screen;
use RageKit\Window;

/**
 * Slint UI compiled ahead of time: `ragekit build` turns every Screen's .rk template into a Slint
 * component in libragekit_slint, plus RageKit\Generated\Screens to feed and dispatch it.
 */
final class SlintBackend implements Backend
{
    /** @var array<int, SlintWindow> open windows by id */
    private array $windows = [];

    /** The app, once it opened a window: exceptions from handlers go to it. */
    private ?App $app = null;

    public function __construct()
    {
        \slint_set_dispatcher(function (int $window, int $action, string $arg): void {
            $this->dispatch($window, $action, $arg);
        });
        \slint_set_event_dispatcher(function (string $module, int $handle, string $kind, string $data): void {
            try {
                \RageKit\Events::deliver($module, $handle, $kind, $data);
            } catch (\Throwable $e) {
                $this->report($e);
            }
            $this->syncAll();
        });
    }

    public function open(App $app, Screen $screen, string $title, int $width, int $height): Window
    {
        $this->app = $app;
        $window = new SlintWindow($app, $screen, $title, $width, $height);
        $this->windows[$window->id] = $window;
        return $window->window;
    }

    public function close(Window $window): void
    {
        if (!isset($this->windows[$window->id])) {
            return;
        }
        unset($this->windows[$window->id]);
        \slint_close($window->id);
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
        foreach ($this->windows as $id => $open) {
            unset($this->windows[$id]);
            \slint_close($id);
        }
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

    public function printError(string $line): void
    {
        \slint_print_error($line);
    }

    public function startTimer(int $id, int $milliseconds, bool $repeat): void
    {
        \slint_timer_start($id, $milliseconds, $repeat);
    }

    public function stopTimer(int $id): void
    {
        \slint_timer_stop($id);
    }

    /** Pushes every window's values again (dev mode, after a template reload). */
    public function resync(): void
    {
        foreach ($this->windows as $window) {
            $window->resync();
        }
    }

    /**
     * Every UI event: action 0 is the OS closing the window (the App decides: the main window
     * ends the app), -1 reports its maximised state (arg "1"/"0"), -2 is the toast timer's tick,
     * -3 is back (Alt+Left), others run a handler; then all windows re-sync. An exception nobody
     * caught goes to the app (App::uncaughtException()) instead of ending it.
     */
    private function dispatch(int $window, int $action, string $arg): void
    {
        $target = $this->windows[$window] ?? null;
        if ($target === null) {
            return;
        }
        try {
            if ($action === 0) {
                $target->closeRequested();
            } elseif ($action === -1) {
                $target->maximizedChanged($arg === '1');
            } elseif ($action === -2) {
                // A toast tick: no handler; the re-sync below drops expired toasts.
            } elseif ($action === -3) {
                $target->back();
            } else {
                $target->handle($action, $arg);
            }
        } catch (\Throwable $e) {
            $this->report($e);
        }
        $this->syncAll();
    }

    /** Every window re-reads its values (a computed value may throw too). */
    private function syncAll(): void
    {
        foreach ($this->windows as $open) {
            try {
                $open->sync();
            } catch (\Throwable $e) {
                $this->report($e);
            }
        }
    }

    private function report(\Throwable $e): void
    {
        if ($this->app === null) {
            throw $e;
        }
        $this->app->exceptionThrown($e);
    }
}
