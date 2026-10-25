<?php

namespace Fix;

use RageKit\App;
use RageKit\Screen;

final class RowsApp extends App
{
    protected function firstScreen(): Screen
    {
        return new ShelfScreen();
    }
}
