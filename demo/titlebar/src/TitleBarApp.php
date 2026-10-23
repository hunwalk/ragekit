<?php

namespace Demo;

use RageKit\App;
use RageKit\Screen;

/** Windows that draw their own title bar (AppWindow). */
final class TitleBarApp extends App
{
    protected function firstScreen(): Screen
    {
        return new HomeScreen();
    }

    protected function title(): string
    {
        return 'RageKit · title bar';
    }

    protected function width(): int
    {
        return 640;
    }

    protected function height(): int
    {
        return 400;
    }
}
