<?php

namespace Demo\Screens;

use Demo\KitchenSinkWindow;
use RageKit\Screen;

/** A section of the kitchen sink: shown inside its window, next to the sidebar. */
abstract class Page extends Screen
{
    public static function window(): string
    {
        return KitchenSinkWindow::class;
    }
}
