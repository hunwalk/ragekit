<?php

declare(strict_types=1);

namespace RageKit\Generated;

use RageKit\Navigator;
use RageKit\Screen;
use RageKit\Slint\Dev\Session;

/**
 * Dev mode's stand-in for the binder `ragekit build` generates: the same API SlintWindow uses,
 * answered from screens.json, which `ragekit dev` rewrites on every template change.
 */
final class Screens
{
    public static function window(Screen $screen): string
    {
        return Session::bindings()->window($screen);
    }

    public static function page(Screen $screen): int
    {
        return Session::bindings()->page($screen);
    }

    /** Called once per window, just before slint_open(): the session learns the window's screen (and, after a restart, restores it). */
    public static function layout(Screen $screen): ?\RageKit\Layout
    {
        Session::pending($screen);
        Session::restore($screen);
        $layout = Session::bindings()->layout($screen);
        if ($layout instanceof \RageKit\Layout) {
            Session::restoreLayout($layout);
            return $layout;
        }
        return null;
    }

    /** @return array<int, mixed> property id => current value */
    public static function values(Navigator $n): array
    {
        Session::restoreStack($n); // after a restart: the screens that were on top of this window's first one
        return Session::bindings()->values($n);
    }

    public static function dispatch(Navigator $n, int $action, string $arg): int
    {
        return Session::bindings()->dispatch($n, $action, $arg);
    }
}
