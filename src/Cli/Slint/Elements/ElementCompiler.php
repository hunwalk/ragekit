<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint\Elements;

use RageKit\Cli\Slint\Scope;
use RageKit\Cli\Templates\Element;

/** Compiles one kind of template element (<Dialog>, <DropdownMenu>, …) into Slint. */
interface ElementCompiler
{
    /** @return list<string> the tags it handles (no namespace prefix) */
    public function tags(): array;

    /** $axis: the direction of the layout the element sits in ('v' or 'h'). */
    public function compile(Element $element, Scope $scope, string $axis): string;
}
