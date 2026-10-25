<?php

namespace Fix;

use RageKit\Screen;

final class ClockScreen extends Screen
{
    public int $ticks = 0;

    public string $status = 'waiting';

    public static function template(): string
    {
        return 'templates/ClockScreen.rk';
    }
}
