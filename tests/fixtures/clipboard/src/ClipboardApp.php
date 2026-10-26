<?php

namespace Fix;

use RageKit\App;
use RageKit\Clipboard;
use RageKit\Screen;
use RageKit\Timer;

/** A second after start: pastes what's on the clipboard, then puts its own text there. */
final class ClipboardApp extends App
{
    protected function firstScreen(): Screen
    {
        return new ResultScreen();
    }

    protected function title(): string
    {
        return 'clipboardapp';
    }

    protected function start(): void
    {
        Timer::after(1, function (): void {
            echo 'pasted: ' . Clipboard::paste() . "\n";
            Clipboard::copy("from RageKit ✓\nsecond line");
            echo "copied\n";
        });
    }
}
