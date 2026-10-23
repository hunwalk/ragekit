<?php

namespace RageKit;

/**
 * One OS window: its navigator (the stack of screens it shows), its toasts and, for a subclass
 * with a template, its frame. A screen names its window class with its static window().
 *
 * The plain Window has the OS's title bar. A subclass with its own .rk template (one <Content />
 * where the screen goes) is frameless and draws its own title bar (<WindowDrag>,
 * <WindowControls />), with state and handlers like any component. It sees the screen on top as
 * $this->context; a window that needs something from it names an interface in context(): every
 * screen using the window must implement it, and the template calls it as {{ $context->title() }}.
 *
 * The runtime creates one per OS window, so a subclass must be constructible without arguments.
 */
class Window extends Component
{
    /** Whether the window is maximised (also after the OS maximised or restored it). */
    public bool $maximized = false;

    /** The screen on top of the stack. Set before the first event. */
    protected Screen $context;

    /** @internal The backend's id of this window (0 until it's open). */
    public int $id = 0;

    private ?Navigator $navigator = null;

    /** @var list<string> toasts of screens that left the stack, not shown yet */
    private array $leftToasts = [];

    /** @var list<string> toasts on screen */
    private array $toastTexts = [];

    /** @var list<float> when each toast on screen goes (clock seconds) */
    private array $toastExpiries = [];

    /** The interface screens using this window must implement ('' = any screen). */
    public static function context(): string
    {
        return '';
    }

    /**
     * The stack of screens: `$window->navigator()->reset(new LoginScreen())`. Screens reach their
     * own as $this->navigator().
     */
    public function navigator(): Navigator
    {
        return $this->navigator ?? throw new \LogicException('this window has no navigator yet; it exists once the window is open');
    }

    /** Closes the window (the main window ends the app, see App::mainWindowClosed()). */
    public function close(): void
    {
        $this->app->closeRequested($this);
    }

    public function minimize(): void
    {
        $this->app->backend()->minimize($this->id);
    }

    public function toggleMaximize(): void
    {
        $this->maximized = !$this->maximized;
        $this->app->backend()->setMaximized($this->id, $this->maximized);
    }

    /** Goes back to the previous screen (a back button: onClick="back"). */
    public function back(): void
    {
        $this->navigator()->back();
    }

    /** Whether there's a screen to go back to, e.g. @if (canGoBack()) around a back button. */
    public function canGoBack(): bool
    {
        return $this->navigator !== null && $this->navigator->canGoBack();
    }

    /** @internal The window opened: its app and the backend's id. */
    public function attach(App $app, int $id): void
    {
        $this->mount($app);
        $this->id = $id;
    }

    /** @internal Set by the Navigator this window shows. */
    public function useNavigator(Navigator $navigator): void
    {
        $this->navigator = $navigator;
    }

    /** @internal A screen joins this window's stack. */
    public function adopt(Screen $screen): void
    {
        $screen->mount($this->app);
        $screen->useWindow($this);
    }

    /** @internal The screen on top of the stack: what $this->context is. */
    public function follow(Screen $top): void
    {
        $this->context = $top;
    }

    /** @internal A screen leaves the stack: its toasts (e.g. "Saved" right before back()) still show. */
    public function collect(Screen $leaving): void
    {
        foreach ($leaving->takeToasts() as $text) {
            $this->leftToasts[] = $text;
        }
    }

    /**
     * @internal The window's toasts (every screen's on the stack and its own), read on every
     * sync: new ones get 3 seconds, expired ones are dropped.
     * @return list<string>
     */
    public function toastTexts(): array
    {
        $now = $this->now();
        $new = $this->leftToasts;
        $this->leftToasts = [];
        if ($this->navigator !== null) {
            foreach ($this->navigator->stack() as $screen) {
                foreach ($screen->takeToasts() as $text) {
                    $new[] = $text;
                }
            }
        }
        foreach ($this->takeToasts() as $text) {
            $new[] = $text;
        }
        foreach ($new as $text) {
            $this->toastTexts[] = $text;
            $this->toastExpiries[] = $now + 3.0;
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

    /** Clock seconds, for toast timing. */
    protected function now(): float
    {
        return \microtime(true);
    }
}
