<?php

namespace Fix;

use RageKit\App;
use RageKit\Screen;

final class PressApp extends App
{
    protected function firstScreen(): Screen
    {
        return new PeopleScreen();
    }

    protected function title(): string
    {
        return 'pressable';
    }

    protected function width(): int
    {
        return 420;
    }

    protected function height(): int
    {
        return 360;
    }
}
