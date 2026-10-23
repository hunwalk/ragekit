<?php

namespace Demo;

use RageKit\App;
use RageKit\RootStore;
use RageKit\Screen;

/** Signing in leads to the albums, signing out back to the sign-in screen — wherever it happens. */
final class GalleryApp extends App
{
    protected function createStore(): RootStore
    {
        return new AppStore();
    }

    protected function firstScreen(): Screen
    {
        return AppStore::get()->session->signedIn() ? new AlbumsScreen() : new LoginScreen();
    }

    protected function start(): void
    {
        $session = AppStore::get()->session;
        $session->on('signedIn', function (): void {
            $this->main()->navigator()->reset(new AlbumsScreen());
        });
        $session->on('signedOut', function (): void {
            $this->main()->navigator()->reset(new LoginScreen());
        });
    }

    protected function title(): string
    {
        return 'RageKit · navigator';
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
