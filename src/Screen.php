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

    /** @var list<string> toasts on screen */
    private array $toastTexts = [];

    /** @var list<float> when each toast on screen goes (now() seconds) */
    private array $toastExpiries = [];

    /**
     * @internal The window's toasts (this screen's and its layout's), read on every sync: new ones
     * get 3 seconds, expired ones are dropped. While any is shown the window ticks (action -2).
     * @return list<string>
     */
    public function toastTexts(): array
    {
        $now = $this->now();
        $new = $this->takeToasts();
        if ($this->layout !== null) {
            foreach ($this->layout->takeToasts() as $text) {
                $new[] = $text;
            }
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

    /** Seconds, for toast timing (tests override it). */
    protected function now(): float
    {
        return \microtime(true);
    }
}
