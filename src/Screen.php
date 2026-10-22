<?php

namespace RageKit;

/** A component that fills a window: what App::open() shows. Child components can't be opened. */
abstract class Screen extends Component
{
    /** The layout framing this screen's window, if layout() names one. Set before the first event. */
    protected ?Layout $layout = null;

    /**
     * The Layout class that frames this screen's window, e.g. `return AppLayout::class;`.
     * '' (the default) = an ordinary window with the OS's title bar.
     */
    public static function layout(): string
    {
        return '';
    }

    /** @internal */
    public function useLayout(Layout $layout): void
    {
        $this->layout = $layout;
    }

    /** @internal */
    public function layoutObject(): ?Layout
    {
        return $this->layout;
    }

    private ?Navigator $navigator = null;

    /**
     * The window's stack of screens: `$this->navigator()->push(new DetailsScreen($id))`,
     * `->back()`, `->replace(…)`, `->reset(…)`. It exists once the screen is shown in a window
     * (not yet in the constructor).
     */
    protected function navigator(): Navigator
    {
        if ($this->navigator === null) {
            $class = \get_class($this);
            $slash = strrpos($class, '\\');
            throw new \LogicException(($slash === false ? $class : substr($class, $slash + 1)) . ': no navigator yet; it exists once the screen is shown in a window');
        }
        return $this->navigator;
    }

    /** @internal */
    public function useNavigator(Navigator $navigator): void
    {
        $this->navigator = $navigator;
    }

    /** Called when this screen becomes the one the window shows (also after back() returns to it). */
    public function shown(): void
    {
    }

    /** Called when another screen covers this one, or it's dropped from the stack. */
    public function hidden(): void
    {
    }
}
