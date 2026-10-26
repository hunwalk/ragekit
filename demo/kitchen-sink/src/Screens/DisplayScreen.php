<?php

namespace Demo\Screens;

/** Text styles, labels and images, and feedback elements, in three tabs. */
final class DisplayScreen extends Page
{
    public int $tab = 0;

    public static function template(): string
    {
        return 'templates/Screens/Display.rk';
    }
}
