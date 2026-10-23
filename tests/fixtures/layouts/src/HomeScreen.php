<?php

namespace Fix;

use RageKit\Screen;

final class HomeScreen extends Screen implements TitleBarContext
{
    public int $count = 0;

    public static function template(): string
    {
        return 'templates/HomeScreen.rk';
    }

    public static function window(): string
    {
        return AppWindow::class;
    }

    public function title(): string
    {
        return 'Home';
    }

    public function refresh(string $why = ''): void
    {
    }
}
