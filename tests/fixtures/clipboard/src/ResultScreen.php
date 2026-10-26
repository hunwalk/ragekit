<?php

namespace Fix;

use RageKit\Screen;

final class ResultScreen extends Screen
{
    public string $text = 'clipboard';

    public static function template(): string
    {
        return 'templates/ResultScreen.rk';
    }
}
