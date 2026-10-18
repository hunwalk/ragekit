<?php

declare(strict_types=1);

namespace RageKit\Cli\Templates;

/** One element of a parsed .rk template. */
final class Element
{
    /**
     * @param ?string               $namespace  the kit name for prefixed tags (xmlns:ui="shadcn" → "shadcn")
     * @param array<string, string> $attributes
     * @param list<Element>         $children
     */
    public function __construct(
        public readonly string $tag,
        public readonly ?string $namespace,
        public readonly array $attributes,
        public readonly array $children,
        public readonly int $line,
        public readonly string $file,
    ) {
    }
}
