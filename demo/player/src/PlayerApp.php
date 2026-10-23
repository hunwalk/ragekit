<?php

namespace Demo;

use RageKit\App;
use RageKit\Screen;

/** Plays a sound with the audio module. */
final class PlayerApp extends App
{
    protected function firstScreen(): Screen
    {
        return new PlayerScreen();
    }

    protected function title(): string
    {
        return 'RageKit · player';
    }

    protected function width(): int
    {
        return 420;
    }

    protected function height(): int
    {
        return 340;
    }
}
