<?php

namespace KitDemo;

use RageKit\Screen;

/** A fake player: three image buttons from the project's own `icons` kit change the status. */
final class PlayerScreen extends Screen
{
    public string $status = 'Stopped';

    public static function template(): string
    {
        return 'templates/PlayerScreen.rk';
    }

    public function play(): void
    {
        $this->status = 'Playing';
    }

    public function pause(): void
    {
        $this->status = 'Paused';
    }

    public function stop(): void
    {
        $this->status = 'Stopped';
    }
}
