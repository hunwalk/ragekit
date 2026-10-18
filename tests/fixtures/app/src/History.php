<?php

namespace Demo;

use RageKit\Component;

final class History extends Component
{
    /** @var list<string> */
    public array $entries = [];

    public static function template(): string
    {
        return 'ui/screens/../shared/history.rk';
    }
}
