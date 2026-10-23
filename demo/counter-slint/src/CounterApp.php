<?php

namespace Demo;

use RageKit\App;
use RageKit\Screen;

/** A counter with a history; its About window closes with the main one. */
final class CounterApp extends App
{
    protected function firstScreen(): Screen
    {
        return new CounterScreen();
    }

    protected function title(): string
    {
        return 'RageKit · counter';
    }

    protected function width(): int
    {
        return 360;
    }

    protected function height(): int
    {
        return 420;
    }
}
