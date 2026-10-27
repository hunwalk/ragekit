<?php

namespace Fix;

use RageKit\App;
use RageKit\Screen;

final class GalleryApp extends App
{
    protected function firstScreen(): Screen
    {
        return new GalleryScreen();
    }

    protected function title(): string
    {
        return 'gallery';
    }
}
