<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/**
 * A {name} resolved to a Slint expression of a known type. 'rows' (a collection) and 'row' (one
 * of its @foreach items) carry their RowType. $origin is what a bound list or collection reads
 * ([source, name, owner]); a @foreach item over a bound list keeps its list's $origin and the
 * loop's $index variable.
 */
final class Ref
{
    public function __construct(
        public readonly string $type,
        public readonly string $slint,
        public readonly ?string $itemType = null,
        public readonly ?RowType $row = null,
        public readonly ?array $origin = null,
        public readonly ?string $index = null,
    ) {
    }
}
