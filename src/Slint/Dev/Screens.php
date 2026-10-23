<?php

declare(strict_types=1);

namespace RageKit\Generated;

use RageKit\Screen;
use RageKit\Slint\Dev\Session;
use RageKit\Window;

/**
 * Dev mode's stand-in for the binder `ragekit build` generates: the same API SlintWindow uses,
 * answered from screens.json, which `ragekit dev` rewrites on every template change.
 */
final class Screens
{
    public static function component(Screen $screen): string
    {
        return Session::bindings()->component($screen);
    }

    public static function page(Screen $screen): int
    {
        return Session::bindings()->page($screen);
    }

    /**
     * Called once per window, just before slint_open(): the session learns the window's screen
     * (and, after a restart, restores it, the stores and the window's state).
     */
    public static function newWindow(Screen $screen): Window
    {
        return Session::windowFor($screen);
    }

    /** @return array<int, mixed> property id => current value */
    public static function values(Window $w): array
    {
        Session::adopt($w);
        Session::restoreStack($w->navigator()); // after a restart: the screens that were on top of this window's first one
        return Session::bindings()->values($w);
    }

    public static function dispatch(Window $w, int $action, string $arg): int
    {
        return Session::bindings()->dispatch($w, $action, $arg);
    }
}
