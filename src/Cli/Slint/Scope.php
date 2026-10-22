<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;

/**
 * Where a template element is compiled: its class, its screen model (null in children), each
 * items, and whose template it is. In a layout's template ($owner 'layout', $class the layout),
 * $pages are what <Content /> shows: every screen of the window, one page each.
 */
final class Scope
{
    /**
     * @param array<string, Ref> $items
     * @param list<Page> $pages
     */
    public function __construct(
        public readonly ClassInfo $class,
        public readonly ?ScreenModel $model,
        private readonly array $items = [],
        public readonly string $owner = 'screen',
        public readonly array $pages = [],
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
        return new self($this->class, $this->model, [$name => $ref] + $this->items, $this->owner, $this->pages);
    }
}
