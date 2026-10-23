<?php

namespace Demo;

use RageKit\Window;

/** The window frame: title, a search box and the window buttons, above the screen. */
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

    public function submit(): void
    {
        $this->context->search($this->query);
    }
}
