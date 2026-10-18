<?php

namespace Demo;

use RageKit\Screen;

final class AboutScreen extends Screen
{
    public static function template(): string
    {
        return 'templates/AboutScreen.rk';
    }
}
