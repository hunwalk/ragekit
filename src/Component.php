<?php

namespace RageKit;

/**
 * State and behaviour behind a .rk template; the static template() says where that file is.
 *
 * In the template, {name} shows a public property, a public method without parameters, a class
 * constant or an each item; onClick="method" calls a public method. After every event the
 * window re-reads the shown values, so handlers just change properties.
 */
abstract class Component
{
    /** The app this component runs in — open screens, quit, etc. Set before the first event. */
    protected App $app;

    /**
     * Path of this component's .rk template, relative to the project root (the folder with
     * project.yml) or absolute — e.g. `return 'templates/player.rk';`. Every component must
     * override it: `ragekit build` rejects components that don't. (Not abstract: TypePHP apps
     * print a warning at startup for abstract static methods.)
     */
    public static function template(): string
    {
        return '';
    }

    /** @var list<string> toasts fired since the window last read them */
    private array $pendingToasts = [];

    /**
     * Shows a short message in the window's bottom-right corner for about 3 seconds, e.g.
     * `$this->toast('Saved');`. Several stack up. Works in screens and layouts.
     */
    public function toast(string $text): void
    {
        $this->pendingToasts[] = $text;
    }

    /**
     * @internal
     * @return list<string>
     */
    public function takeToasts(): array
    {
        $toasts = $this->pendingToasts;
        $this->pendingToasts = [];
        return $toasts;
    }

    /** @internal */
    public function mount(App $app): void
    {
        $this->app = $app;
    }
}
