<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\ClassInfo;

/** Where a template element is compiled: its class, its screen model (null in children), each items. */
final class Scope
{
    /** @param array<string, Ref> $items */
    public function __construct(
        public readonly ClassInfo $class,
        public readonly ?ScreenModel $model,
        private readonly array $items = [],
    ) {
    }

    public function item(string $name): ?Ref
    {
        return $this->items[$name] ?? null;
    }

    public function hasItems(): bool
    {
        return $this->items !== [];
    }

    public function with(string $name, Ref $ref): self
    {
        return new self($this->class, $this->model, [$name => $ref] + $this->items);
    }
}
