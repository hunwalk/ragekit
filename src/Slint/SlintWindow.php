<?php

namespace RageKit\Slint;

use RageKit\App;
use RageKit\Generated\Screens;
use RageKit\Navigator;
use RageKit\Screen;
use RageKit\Window;

/**
 * One open window: its Window object (the navigator with the screens it shows, its frame and
 * toasts) and the values last pushed to the compiled Slint window component. Property 0 is the
 * page: which screen of the component is shown.
 */
final class SlintWindow
{
    public int $id = 0;

    public Window $window;

    /** @var array<int, mixed> property id => value last sent */
    private array $sent = [];

    public function __construct(private App $app, Screen $screen, string $title, int $width, int $height)
    {
        $component = Screens::component($screen);
        $this->window = Screens::newWindow($screen); // one per window: its state is this window's
        $this->id = \slint_open($component, $title, $width, $height);
        if ($this->id === 0) {
            throw new \RuntimeException("The Slint backend has no compiled window $component (rebuild with ragekit)");
        }
        $this->window->attach($app, $this->id);
        new Navigator($this->window, $screen, function (Screen $s): string {
            return Screens::component($s);
        });
        $this->sync();
    }

    /** The OS asked to close the window: the App decides what that means. */
    public function closeRequested(): void
    {
        $this->app->closeRequested($this->window);
    }

    /** The OS maximised or restored the window (also by snapping): $maximized follows. */
    public function maximizedChanged(bool $maximized): void
    {
        $this->window->maximized = $maximized;
    }

    /** Runs the handler behind $action. A two-way input's value is already on screen: don't echo it. */
    public function handle(int $action, string $arg): void
    {
        $shown = Screens::dispatch($this->window, $action, $arg);
        if ($shown !== 0) {
            $values = $this->values();
            $this->sent[$shown] = $values[$shown];
        }
    }

    /** Back with the keyboard (Alt+Left): the previous screen, if there is one. */
    public function back(): void
    {
        $this->window->navigator()->back();
    }

    /** @return array<int, mixed> property id => value: the page (0), then the window's and the top screen's */
    private function values(): array
    {
        // Values first: reading them may change the stack (dev mode puts saved screens back then).
        $values = Screens::values($this->window);
        return [0 => Screens::page($this->window->navigator()->top())] + $values;
    }

    /** Pushes every value again, e.g. after `ragekit dev` replaced the window's UI. */
    public function resync(): void
    {
        $this->sent = [];
        $this->sync();
    }

    /** Pushes every value that changed since the last sync. Images compare by what they show (Images::state()). */
    public function sync(): void
    {
        foreach ($this->values() as $id => $value) {
            if ($value instanceof ImageValue) {
                $state = Images::state($this->app->backend(), $value->source);
                if (array_key_exists($id, $this->sent) && $this->sent[$id] === $state) {
                    continue;
                }
                $this->pushImage($id, $value->source, $state);
                $this->sent[$id] = $state;
                continue;
            }
            if (array_key_exists($id, $this->sent) && $this->sent[$id] === $value) {
                continue;
            }
            self::push($this->id, $id, $value);
            $this->sent[$id] = $value;
        }
    }

    /** $state from Images::state(): 'none', 'asset:<index>' or 'file:<path>'. */
    private function pushImage(int $id, string $source, string $state): void
    {
        if (str_starts_with($state, 'asset:')) {
            \slint_set_image_asset($this->id, $id, (int) substr($state, 6));
            return;
        }
        $path = str_starts_with($state, 'file:') ? substr($state, 5) : '';
        if (!\slint_set_image_file($this->id, $id, $path)) {
            Images::loadFailed($this->app->backend(), $source);
        }
    }

    private static function push(int $window, int $id, mixed $value): void
    {
        if (is_bool($value)) {
            \slint_set_bool($window, $id, $value);
        } elseif (is_int($value)) {
            \slint_set_int($window, $id, $value);
        } elseif (is_float($value)) {
            \slint_set_float($window, $id, $value);
        } elseif (is_array($value)) {
            \slint_list_begin($window, $id);
            foreach ($value as $item) {
                \slint_list_append((string) $item);
            }
            \slint_list_end();
        } else {
            \slint_set_string($window, $id, (string) $value);
        }
    }
}
