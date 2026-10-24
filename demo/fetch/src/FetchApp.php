<?php

namespace Demo;

use Demo\Assets\Images;
use RageKit\App;
use RageKit\Screen;

/** HTTP requests on the http module: a GitHub user lookup and a download. */
final class FetchApp extends App
{
    protected function firstScreen(): Screen
    {
        return new FetchScreen();
    }

    protected function assets(): array
    {
        return ['images' => Images::class];
    }

    protected function title(): string
    {
        return 'RageKit · fetch';
    }

    protected function width(): int
    {
        return 460;
    }

    protected function height(): int
    {
        return 380;
    }
}
