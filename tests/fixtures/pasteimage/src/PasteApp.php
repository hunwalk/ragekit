<?php

namespace Fix;

use RageKit\App;
use RageKit\Screen;

final class PasteApp extends App
{
    protected function firstScreen(): Screen
    {
        return new PasteScreen();
    }

    protected function title(): string
    {
        return 'pasteimage';
    }
}
