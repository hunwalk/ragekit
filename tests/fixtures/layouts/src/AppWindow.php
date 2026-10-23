<?php

namespace Fix;

use RageKit\Window;

final class AppWindow extends Window
{
    public string $query = '';

    public static function template(): string
    {
        return 'templates/AppWindow.rk';
    }

    public static function context(): string
    {
        return TitleBarContext::class;
    }

    public function search(): void
    {
    }
}
