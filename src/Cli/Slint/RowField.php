<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/**
 * One field of a row struct: an item member a template reads ($item->member or $item->member()),
 * as the Slint field $name (f0, f1, …). A string member shown as an image is a field of its own
 * with type 'image'; an array member (a @foreach inside the row's) is 'strings', and 'images' when
 * its items are shown as images.
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

    /** The field's Slint type. */
    public function slintType(): string
    {
        return match ($this->type) {
            'strings' => '[string]',
            'images' => '[image]',
            default => $this->type,
        };
    }
}
