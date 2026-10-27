<?php

namespace Fix;

use RageKit\App;
use RageKit\Screen;

final class RichApp extends App
{
    protected function firstScreen(): Screen
    {
        return new RichScreen();
    }

    protected function title(): string
    {
        return 'richtext';
    }

    protected function width(): int
    {
        return 520;
    }

    protected function height(): int
    {
        return 420;
    }
}
