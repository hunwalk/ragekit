<?php

namespace RageKit\Slint;

use RageKit\App;
use RageKit\Generated\Screens;
use RageKit\Screen;

/**
 * One open window: its Screen (and the Layout framing it, if the screen names one) and the values
 * last pushed to the compiled Slint component.
 */
final class SlintWindow
{
    public int $id = 0;

    /** @var array<int, mixed> property id => value last sent */
    private array $sent = [];

    public function __construct(App $app, private Screen $screen, string $title, int $width, int $height)
    {
        $name = Screens::name($screen);
        $layout = Screens::layout($screen); // one per window: its state is this window's
        if ($layout !== null) {
            $layout->mount($app);
            $screen->useLayout($layout);
        }
        $this->id = \slint_open($name, $title, $width, $height);
        if ($this->id === 0) {
            throw new \RuntimeException("The Slint backend has no compiled screen $name (rebuild with ragekit)");
        }
        if ($layout !== null) {
            $layout->attach($screen, $this->id);
        }
        $this->sync();
    }

    /** The OS maximised or restored the window (also by snapping): the layout's $maximized follows. */
    public function maximizedChanged(bool $maximized): void
    {
        $layout = $this->screen->layoutObject();
        if ($layout !== null) {
            $layout->maximized = $maximized;
        }
    }

    /** Runs the handler behind $action. A two-way input's value is already on screen: don't echo it. */
    public function handle(int $action, string $arg): void
    {
        $shown = Screens::dispatch($this->screen, $action, $arg);
        if ($shown !== 0) {
            $values = Screens::values($this->screen);
            $this->sent[$shown] = $values[$shown];
        }
    }

    /** Pushes every value again, e.g. after `ragekit dev` replaced the window's UI. */
    public function resync(): void
    {
        $this->sent = [];
        $this->sync();
    }

    /** Pushes every value that changed since the last sync. */
    public function sync(): void
    {
        foreach (Screens::values($this->screen) as $id => $value) {
            if (array_key_exists($id, $this->sent) && $this->sent[$id] === $value) {
                continue;
            }
            self::push($this->id, $id, $value);
            $this->sent[$id] = $value;
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
