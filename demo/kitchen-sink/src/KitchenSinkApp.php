<?php

namespace Demo;

use Demo\Screens\InputsScreen;
use RageKit\App;
use RageKit\Screen;

/** Every core element and the desktop features, by section, in a window with its own title bar. */
final class KitchenSinkApp extends App
{
    protected function firstScreen(): Screen
    {
        return new InputsScreen();
    }

    protected function title(): string
    {
        return 'RageKit · kitchen sink';
    }

    protected function width(): int
    {
        return 900;
    }

    protected function height(): int
    {
        return 640;
    }
}
