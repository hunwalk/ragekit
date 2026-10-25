<?php

namespace E2e;

use E2e\Assets\Images;
use RageKit\App;
use RageKit\Screen;

final class ImagesApp extends App
{
    protected function firstScreen(): Screen
    {
        return new HomeScreen();
    }

    protected function assets(): array
    {
        return ['images' => Images::class];
    }

    protected function title(): string
    {
        return 'images';
    }

    protected function width(): int
    {
        return 320;
    }

    protected function height(): int
    {
        return 260;
    }
}
