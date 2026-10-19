<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;

/**
 * Where a template element is compiled: its class, its screen model (null in children), each
 * items, and whose template it is. In a layout's template ($owner 'layout', $class the layout),
 * $screen and $screenRoot are what <Content /> shows.
 */
final class Scope
{
    /** @param array<string, Ref> $items */
    public function __construct(
        public readonly ClassInfo $class,
        public readonly ?ScreenModel $model,
        private readonly array $items = [],
        public readonly string $owner = 'screen',
        public readonly ?Scope $screen = null,
        public readonly ?Element $screenRoot = null,
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

    public function inLayout(): bool
    {
        return $this->owner === 'layout';
    }

    public function with(string $name, Ref $ref): self
    {
        return new self($this->class, $this->model, [$name => $ref] + $this->items, $this->owner, $this->screen, $this->screenRoot);
    }
}
