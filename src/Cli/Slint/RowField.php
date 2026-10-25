<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/**
 * One field of a row struct: an item member a template reads ($item->member or $item->member()),
 * as the Slint field $name (f0, f1, …). A string member shown as an image is a field of its own
 * with type 'image'.
 */
final class RowField
{
    public function __construct(
        public readonly string $member,
        public readonly string $source,
        public readonly string $type,
        public readonly string $name,
    ) {
    }
}
