<?php

namespace Fix;

use RageKit\Screen;

final class ShelfScreen extends Screen
{
    public Devices $devices;

    /** @var list<string> */
    public array $photos = ['a.png'];

    public function __construct()
    {
        $this->devices = new Devices([new Device('mouse', '', 0.5, 2, true)]);
    }

    public static function template(): string
    {
        return 'templates/ShelfScreen.rk';
    }

    public function pick(string $name): void
    {
    }
}
