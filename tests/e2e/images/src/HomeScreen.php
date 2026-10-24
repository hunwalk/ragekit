<?php

namespace E2e;

use E2e\Assets\Images;
use RageKit\Screen;

final class HomeScreen extends Screen
{
    /** Switched by toggle(): an embedded image chosen at runtime. */
    public string $state = Images::RED;

    /** Served by images.sh (php -S); a fixed URL because Nano has no getenv. */
    public string $web = 'http://127.0.0.1:18766/green.png';

    public static function template(): string
    {
        return 'templates/HomeScreen.rk';
    }

    public function toggle(): void
    {
        $this->state = $this->state === Images::RED ? Images::BLUE : Images::RED;
    }
}
