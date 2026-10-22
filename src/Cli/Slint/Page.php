<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\Element;

/** One screen of a window component: its template's root element, compiled in $scope, shown while rk-page is $index. */
final class Page
{
    public function __construct(public readonly int $index, public readonly Scope $scope, public readonly Element $root)
    {
    }
}
