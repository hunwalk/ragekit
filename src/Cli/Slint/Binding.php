<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/**
 * A PHP value (property or computed method) shown in a screen, i.e. Slint property p<id>. $owner
 * says whose: the screen's, or (in a window with a layout) the layout's.
 */
final class Binding
{
    public function __construct(
        public readonly int $id,
        public readonly string $source,
        public readonly string $name,
        public readonly string $type,
        public bool $twoWay = false,
        public readonly string $owner = 'screen',
    ) {
    }

    public function slintName(): string
    {
        return 'p' . $this->id;
    }
}
