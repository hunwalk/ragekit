<?php

namespace Demo;

use RageKit\Layout;

/** The window frame: title, a search box and the window buttons, above the screen. */
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

    public function submit(): void
    {
        $this->context->search($this->query);
    }
}
