<?php

namespace Fix;

use RageKit\Clipboard;
use RageKit\Screen;

/** Prints the path of each pasted image, and the draft when Return is pressed. */
final class PasteScreen extends Screen
{
    public string $draft = '';
    public string $status = 'nothing pasted';

    public static function template(): string
    {
        return 'templates/PasteScreen.rk';
    }

    public function pasted(): void
    {
        $path = Clipboard::image();
        $this->status = 'pasted ' . $path;
        echo 'image ' . $path . "\n";
    }

    public function send(): void
    {
        echo 'draft ' . $this->draft . "\n";
    }
}
