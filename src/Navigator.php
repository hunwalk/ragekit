<?php

namespace RageKit;

/**
 * A window's stack of screens: it shows the top one. Screens and layouts reach it as
 * $this->navigator():
 *
 *     $this->navigator()->push(new PhotosScreen($album)); // show it, keep this one underneath
 *     $this->navigator()->back();                         // drop the top screen
 *
 * Covered screens keep their state: they stay objects on the stack. Every screen in one window
 * uses the window's layout (the root screen's layout()). The window's toasts live here too, so
 * a toast stays up across push and back.
 */
final class Navigator
{
    /** @var list<Screen> bottom (the root) first */
    private array $stack = [];

    /** @var list<string> toasts of screens that left the stack, not shown yet */
    private array $leftToasts = [];

    /** @var list<string> toasts on screen */
    private array $toastTexts = [];

    /** @var list<float> when each toast on screen goes (clock seconds) */
    private array $toastExpiries = [];

    private string $window;

    /**
     * @param \Closure $windowOf (Screen): string — the compiled window component a screen shows in
     * @param \Closure|null $clock (): float — seconds, for toast timing (default microtime)
     */
    public function __construct(
        private App $app,
        Screen $root,
        private ?Layout $layout,
        private \Closure $windowOf,
        private ?\Closure $clock = null,
    ) {
        $this->window = ($this->windowOf)($root);
        $this->enter($root);
        $this->stack[] = $root;
        if ($this->layout !== null) {
            $this->layout->useNavigator($this);
            $this->layout->follow($root);
        }
        $root->shown();
    }

    /** Shows $screen on top of the current one, which keeps its state underneath. */
    public function push(Screen $screen): void
    {
        $this->check($screen);
        $this->top()->hidden();
        $this->enter($screen);
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
        $this->leave(array_pop($this->stack));
        $this->arrived();
        return true;
    }

    /** Swaps the top screen for $screen. */
    public function replace(Screen $screen): void
    {
        $this->check($screen);
        $this->top()->hidden();
        $this->enter($screen);
        $this->leave($this->top());
        $this->stack[count($this->stack) - 1] = $screen;
        $this->arrived();
    }

    /** Starts over: $screen becomes the only screen. */
    public function reset(Screen $screen): void
    {
        $this->check($screen);
        $this->top()->hidden();
        $this->enter($screen);
        foreach ($this->stack as $dropped) {
            $this->leave($dropped);
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

    /** The window's layout, if its screens use one. */
    public function layout(): ?Layout
    {
        return $this->layout;
    }

    /**
     * @internal The window's toasts (every screen's on the stack and the layout's), read on every
     * sync: new ones get 3 seconds, expired ones are dropped.
     * @return list<string>
     */
    public function toastTexts(): array
    {
        $now = $this->clock === null ? \microtime(true) : (float) ($this->clock)();
        foreach ($this->leftToasts as $text) {
            $this->toastTexts[] = $text;
            $this->toastExpiries[] = $now + 3.0;
        }
        $this->leftToasts = [];
        foreach ($this->stack as $screen) {
            foreach ($screen->takeToasts() as $text) {
                $this->toastTexts[] = $text;
                $this->toastExpiries[] = $now + 3.0;
            }
        }
        if ($this->layout !== null) {
            foreach ($this->layout->takeToasts() as $text) {
                $this->toastTexts[] = $text;
                $this->toastExpiries[] = $now + 3.0;
            }
        }
        $texts = [];
        $expiries = [];
        foreach ($this->toastTexts as $i => $text) {
            if ($this->toastExpiries[$i] > $now) {
                $texts[] = $text;
                $expiries[] = $this->toastExpiries[$i];
            }
        }
        $this->toastTexts = $texts;
        $this->toastExpiries = $expiries;
        return $texts;
    }

    private function check(Screen $screen): void
    {
        $window = ($this->windowOf)($screen);
        if ($window !== $this->window) {
            $class = \get_class($screen);
            $slash = strrpos($class, '\\');
            $short = $slash === false ? $class : substr($class, $slash + 1);
            throw new \RuntimeException("$short uses layout " . self::layoutName($window) . "; this window's layout is " . self::layoutName($this->window));
        }
    }

    /** rk-screens-AppLayout → AppLayout; rk-screens → no layout. */
    private static function layoutName(string $window): string
    {
        return $window === 'rk-screens' ? 'no layout' : substr($window, strlen('rk-screens-'));
    }

    private function enter(Screen $screen): void
    {
        $screen->mount($this->app);
        $screen->useNavigator($this);
        if ($this->layout !== null) {
            $screen->useLayout($this->layout);
        }
    }

    /** A screen leaves the stack: its toasts (e.g. "Saved" right before back()) still show. */
    private function leave(Screen $screen): void
    {
        foreach ($screen->takeToasts() as $text) {
            $this->leftToasts[] = $text;
        }
    }

    private function arrived(): void
    {
        if ($this->layout !== null) {
            $this->layout->follow($this->top());
        }
        $this->top()->shown();
    }
}
