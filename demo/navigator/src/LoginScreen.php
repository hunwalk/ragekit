<?php

namespace Demo;

use RageKit\Screen;

/** The first screen while nobody is signed in. */
final class LoginScreen extends Screen implements GalleryContext
{
    public string $name = '';

    public static function template(): string
    {
        return 'templates/Login.rk';
    }

    public static function window(): string
    {
        return AppWindow::class;
    }

    public function title(): string
    {
        return 'Sign in';
    }
}
