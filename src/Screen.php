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
}
