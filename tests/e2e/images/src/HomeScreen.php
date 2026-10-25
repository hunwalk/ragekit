<?php

namespace E2e;

use E2e\Assets\Images;
use RageKit\Screen;

final class HomeScreen extends Screen
{
    /** The rows keep their height when empty, so the buttons stay put. */
    public const STYLES = ['rows' => ['flexDirection' => 'row', 'gap' => 8, 'height' => 64]];

    /** Switched by toggle(): an embedded image chosen at runtime. */
    public string $state = Images::RED;

    /** Served by images.sh (php -S); a fixed URL because Nano has no getenv. */
    public string $web = 'http://127.0.0.1:18766/green.png';

    /** A row each: an asset, the web image, and none (its fallback shows). */
    public Devices $devices;

    public function __construct()
    {
        $this->devices = new Devices();
        $this->add();
    }

    public static function template(): string
    {
        return 'templates/HomeScreen.rk';
    }

    /** Refills the rows (a scan). */
    public function add(): void
    {
        $this->devices->clear();
        $this->devices[] = new Device('red', Images::RED);
        $this->devices->add(new Device('web', $this->web));
        $this->devices->add(new Device('none'));
    }

    /** Empties the rows (a rescan starting). */
    public function clear(): void
    {
        $this->devices->clear();
    }

    public function toggle(): void
    {
        $this->state = $this->state === Images::RED ? Images::BLUE : Images::RED;
    }
}
