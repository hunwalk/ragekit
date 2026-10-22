<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/**
 * A PHP value (property or computed method) shown in a window, i.e. Slint property p<id>. $owner
 * says whose: 'screen' (a page's screen), 'layout', 'context' (the screen on top, read through
 * the layout's context interface) or 'window' (the window's own, e.g. its toasts).
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
