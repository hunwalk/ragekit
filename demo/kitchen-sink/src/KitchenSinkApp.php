<?php

namespace Demo;

use RageKit\App;
use RageKit\Screen;

/** Every core element, in a window with its own title bar. */
final class KitchenSinkApp extends App
{
    protected function firstScreen(): Screen
    {
        return new KitchenSinkScreen();
    }

    protected function title(): string
    {
        return 'RageKit · kitchen sink';
    }

    protected function width(): int
    {
        return 680;
    }

    protected function height(): int
    {
        return 600;
    }
}
