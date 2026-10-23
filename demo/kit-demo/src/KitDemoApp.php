<?php

namespace KitDemo;

use RageKit\App;
use RageKit\Screen;

/** An app kit (kits/icons) with image buttons. */
final class KitDemoApp extends App
{
    protected function firstScreen(): Screen
    {
        return new PlayerScreen();
    }

    protected function title(): string
    {
        return 'Kit demo';
    }

    protected function width(): int
    {
        return 380;
    }

    protected function height(): int
    {
        return 220;
    }
}
