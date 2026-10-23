<?php

namespace Fix;

use RageKit\Window;

final class ConfiguredWindow extends Window
{
    public function __construct(public string $name)
    {
    }

    public static function template(): string
    {
        return 'templates/AppWindow.rk';
    }
}
