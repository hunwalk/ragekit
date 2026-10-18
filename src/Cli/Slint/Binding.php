<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/** A PHP value (property or computed method) shown in a screen, i.e. Slint property p<id>. */
final class Binding
{
    public function __construct(
        public readonly int $id,
        public readonly string $source,
        public readonly string $name,
        public readonly string $type,
        public bool $twoWay = false,
    ) {
    }

    public function slintName(): string
    {
        return 'p' . $this->id;
    }
}
