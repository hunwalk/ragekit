<?php

namespace Demo;

use RageKit\Layout;

/** The window's frame: a back button (when there's a screen to go back to), the title, the window buttons. */
final class AppLayout extends Layout
{
    public static function template(): string
    {
        return 'templates/AppLayout.rk';
    }

    public static function context(): string
    {
        return GalleryContext::class;
    }
}
