<?php

namespace RageKit;

/**
 * A window's stack of screens: the window shows the top one. Screens reach it as
 * $this->navigator(), the app class as $this->main()->navigator():
 *
 *     $this->navigator()->push(new PhotosScreen($album)); // show it, keep this one underneath
 *     $this->navigator()->back();                         // drop the top screen
 *
 * Covered screens keep their state: they stay objects on the stack. Every screen in one window
 * uses the same window class (the root screen's window()).
 */
final class Navigator
{
    /** @var list<Screen> bottom (the root) first */
    private array $stack = [];

    private string $component;

    /**
     * @param \Closure $windowOf (Screen): string — the compiled window component a screen shows in
     */
    public function __construct(private Window $window, Screen $root, private \Closure $windowOf)
    {
        $this->component = ($this->windowOf)($root);
        $this->window->useNavigator($this);
        $this->window->adopt($root);
        $this->stack[] = $root;
        $this->window->follow($root);
        $root->shown();
    }

    /** Shows $screen on top of the current one, which keeps its state underneath. */
    public function push(Screen $screen): void
    {
        $this->check($screen);
        $this->top()->hidden();
        $this->window->adopt($screen);
        $this->stack[] = $screen;
        $this->arrived();
    }

    /** Drops the top screen and shows the one under it; false (and nothing happens) on the root. */
    public function back(): bool
    {
        if (count($this->stack) < 2) {
            return false;
        }
        $this->top()->hidden();
        $this->window->collect(array_pop($this->stack));
        $this->arrived();
        return true;
    }

    /** Swaps the top screen for $screen. */
    public function replace(Screen $screen): void
    {
        $this->check($screen);
        $this->top()->hidden();
        $this->window->adopt($screen);
        $this->window->collect($this->top());
        $this->stack[count($this->stack) - 1] = $screen;
        $this->arrived();
    }

    /** Starts over: $screen becomes the only screen. */
    public function reset(Screen $screen): void
    {
        $this->check($screen);
        $this->top()->hidden();
        $this->window->adopt($screen);
        foreach ($this->stack as $dropped) {
            $this->window->collect($dropped);
        }
        $this->stack = [$screen];
        $this->arrived();
    }

    public function canGoBack(): bool
    {
        return count($this->stack) > 1;
    }

    /** The screen the window shows. */
    public function top(): Screen
    {
        return $this->stack[count($this->stack) - 1];
    }

    public function depth(): int
    {
        return count($this->stack);
    }

    /** @return list<Screen> bottom (the root) first */
    public function stack(): array
    {
        return $this->stack;
    }

    /** The window this stack is shown in. */
    public function window(): Window
    {
        return $this->window;
    }

    private function check(Screen $screen): void
    {
        $component = ($this->windowOf)($screen);
        if ($component !== $this->component) {
            $class = \get_class($screen);
            $slash = strrpos($class, '\\');
            $short = $slash === false ? $class : substr($class, $slash + 1);
            throw new \RuntimeException("$short uses window " . self::windowName($component) . '; this window is ' . self::windowName($this->component));
        }
    }

    /** rk-screens-AppWindow → AppWindow; rk-screens → Window. */
    private static function windowName(string $component): string
    {
        return $component === 'rk-screens' ? 'Window' : substr($component, strlen('rk-screens-'));
    }

    private function arrived(): void
    {
        $this->window->follow($this->top());
        $this->top()->shown();
    }
}
