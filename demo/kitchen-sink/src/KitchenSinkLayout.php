<?php

namespace Demo;

use RageKit\Layout;

/** The window's own title bar (frameless window): drag it, double-click it, use its buttons. */
final class KitchenSinkLayout extends Layout
{
    public static function template(): string
    {
        return 'templates/KitchenSinkLayout.rk';
    }
}
