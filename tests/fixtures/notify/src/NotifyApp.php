<?php

namespace Fix;

use RageKit\App;
use RageKit\Notifications\Notification;
use RageKit\Screen;
use RageKit\Timer;

/** Shows a notification; when it's clicked the window says so, and a second after it closed the app quits. */
final class NotifyApp extends App
{
    private ResultScreen $screen;

    protected function firstScreen(): Screen
    {
        return $this->screen = new ResultScreen();
    }

    protected function title(): string
    {
        return 'notify';
    }

    protected function start(): void
    {
        echo 'available ' . (Notification::available() ? 'yes' : 'no: ' . Notification::why()) . "\n";
        $n = Notification::show('Bob', 'Are you <there>?', function (): void {
            $this->screen->text = 'clicked';
            echo "clicked\n";
        }, function (): void {
            echo "closed\n";
            Timer::after(1, function (): void { // time for the test to see the window
                $this->quit();
            });
        });
        echo 'shown ' . $n->id . "\n";
    }
}
