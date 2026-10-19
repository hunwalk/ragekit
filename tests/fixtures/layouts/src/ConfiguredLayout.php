<?php

namespace Fix;

use RageKit\Layout;

final class ConfiguredLayout extends Layout
{
    public function __construct(public string $name)
    {
    }

    public static function template(): string
    {
        return 'templates/AppLayout.rk';
    }
}
