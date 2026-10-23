<?php

namespace Demo;

use RageKit\Window;

/** The window's own title bar (frameless window): drag it, double-click it, use its buttons. */
final class KitchenSinkWindow extends Window
{
    public static function template(): string
    {
        return 'templates/KitchenSinkWindow.rk';
    }
}
