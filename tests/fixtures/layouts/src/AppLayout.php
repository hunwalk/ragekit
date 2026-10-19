<?php

namespace Fix;

use RageKit\Layout;

final class AppLayout extends Layout
{
    public string $query = '';

    public static function template(): string
    {
        return 'templates/AppLayout.rk';
    }

    public static function context(): string
    {
        return TitleBarContext::class;
    }

    public function search(): void
    {
    }
}
