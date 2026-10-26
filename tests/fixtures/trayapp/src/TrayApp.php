<?php

namespace Fix;

use RageKit\App;
use RageKit\Screen;
use RageKit\Tray\Tray;

/** Lives in the tray: closing the window hides it, the icon brings it back, the menu quits. */
final class TrayApp extends App
{
    protected function firstScreen(): Screen
    {
        return new ResultScreen();
    }

    protected function title(): string
    {
        return 'trayapp';
    }

    protected function start(): void
    {
        echo 'tray ' . (Tray::available() ? 'yes' : 'no: ' . Tray::why()) . "\n";
        Tray::show('assets/tray.png', 'trayapp', function (): void {
            echo "clicked\n";
            $this->main()->show();
        })
            ->item('Open', function (): void {
                $this->main()->show();
            })
            ->separator()
            ->item('Quit', function (): void {
                echo "quit\n";
                $this->quit();
            });
    }

    protected function mainWindowClosed(): void
    {
        echo "hidden\n";
        $this->main()->hide();
    }
}
