<?php

declare(strict_types=1);

namespace RageKit\Generated;

use RageKit\Screen;
use RageKit\Slint\Dev\Session;

/**
 * Dev mode's stand-in for the binder `ragekit build` generates: the same API SlintWindow uses,
 * answered from screens.json, which `ragekit dev` rewrites on every template change.
 */
final class Screens
{
    public static function name(Screen $screen): string
    {
        Session::pending($screen);
        Session::restore($screen);
        return Session::bindings()->name($screen);
    }

    /** @return array<int, mixed> property id => current value */
    public static function values(Screen $screen): array
    {
        return Session::bindings()->values($screen);
    }

    public static function dispatch(Screen $screen, int $action, string $arg): int
    {
        return Session::bindings()->dispatch($screen, $action, $arg);
    }
}
