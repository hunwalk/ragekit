<?php

namespace Fix;

use RageKit\App;
use RageKit\RootStore;
use RageKit\Screen;

final class FixApp extends App
{
    protected function firstScreen(): Screen
    {
        return new HomeScreen();
    }

    protected function createStore(): RootStore
    {
        return new AppStore();
    }

    protected function title(): string
    {
        return 'layouts';
    }
}
