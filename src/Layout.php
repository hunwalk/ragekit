<?php

namespace RageKit;

/**
 * A window's frame around screens: its own .rk template (with one <Content /> where the screen
 * goes), state and handlers. A screen opts in with its static layout(); its window is then
 * frameless and the layout draws the title bar (<WindowDrag>, <WindowControls />).
 *
 * The layout sees the screen it wraps as $this->context. A layout that needs something from it
 * names an interface in context(): every screen using the layout must implement it, and the
 * template can show its methods as {context.title} or call them with onClick="context.refresh".
 */
abstract class Layout extends Component
{
    /** Whether the window is maximised (also after the OS maximised or restored it). */
    public bool $maximized = false;

    /** The screen this layout wraps. Set before the first event. */
    protected Screen $context;

    /** The window this layout frames. */
    protected int $window = 0;

    /** The interface screens using this layout must implement ('' = any screen). */
    public static function context(): string
    {
        return '';
    }

    public function minimize(): void
    {
        $this->app->backend()->minimize($this->window);
    }

    public function toggleMaximize(): void
    {
        $this->maximized = !$this->maximized;
        $this->app->backend()->setMaximized($this->window, $this->maximized);
    }

    public function close(): void
    {
        $this->app->close($this->window);
    }

    /** Goes back to the previous screen of this window (a back button: onClick="back"). */
    public function back(): void
    {
        $this->navigator()->back();
    }

    /** Whether there's a screen to go back to, e.g. @if (canGoBack()) around a back button. */
    public function canGoBack(): bool
    {
        return $this->navigator !== null && $this->navigator->canGoBack();
    }

    private ?Navigator $navigator = null;

    private function navigator(): Navigator
    {
        return $this->navigator ?? throw new \LogicException('this layout has no navigator yet; it exists once its window is open');
    }

    /** @internal The window's navigator (set when the window opens). */
    public function useNavigator(Navigator $navigator): void
    {
        $this->navigator = $navigator;
    }

    /** @internal The screen on top of the window's stack: what $this->context is. */
    public function follow(Screen $top): void
    {
        $this->context = $top;
    }

    /** @internal */
    public function attach(Screen $context, int $window): void
    {
        $this->context = $context;
        $this->window = $window;
    }
}
