<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/**
 * A PHP value (property or computed method) shown in a window, i.e. Slint property p<id>. $owner
 * says whose: 'screen' (a page's screen), 'window' (the Window subclass's own, and its toasts) or
 * 'context' (the screen on top, read through the window's context interface). A 'rows' binding
 * (a collection) carries its RowType; an 'images' binding is a list of strings shown as images.
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
        public readonly ?RowType $row = null,
    ) {
    }

    public function slintName(): string
    {
        return 'p' . $this->id;
    }
}
