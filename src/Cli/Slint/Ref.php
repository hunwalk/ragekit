<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/** A {name} resolved to a Slint expression of a known type. */
final class Ref
{
    public function __construct(
        public readonly string $type,
        public readonly string $slint,
        public readonly ?string $itemType = null,
    ) {
    }
}
