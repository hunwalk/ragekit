<?php

namespace Demo;

use RageKit\Component;

/** Child component: shows the entries its parent passes in. */
final class History extends Component
{
    /** @var list<string> */
    public array $entries = [];

    public static function template(): string
    {
        return 'templates/History.rk';
    }
}
