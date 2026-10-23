<?php

namespace Demo;

use RageKit\Window;

/**
 * The window's frame: a back button (when there's a screen to go back to), the title, who is
 * signed in with a Sign out button, the window buttons.
 */
final class AppWindow extends Window
{
    public static function template(): string
    {
        return 'templates/AppWindow.rk';
    }

    public static function context(): string
    {
        return GalleryContext::class;
    }
}
