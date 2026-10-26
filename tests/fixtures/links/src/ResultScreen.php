<?php

namespace Fix;

use RageKit\Screen;

final class ResultScreen extends Screen
{
    public string $text = 'crypto';

    public static function template(): string
    {
        return 'templates/ResultScreen.rk';
    }
}
