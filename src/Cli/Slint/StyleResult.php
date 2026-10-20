<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/** One element's resolved styles: Slint property name => Slint expression, by where they apply. */
final class StyleResult
{
    /**
     * @param array<string, string>     $box    any element's outer box (width, opacity, …)
     * @param array<string, string>     $view   a <View>'s rectangle (background, border, …)
     * @param array<string, string>     $layout flex container (spacing, padding, alignment, …)
     * @param array<string, string>     $text   a <Label>'s text (color, font-size, …)
     * @param list<string>|null         $margin top, right, bottom, left (null: no margin)
     * @param array<string, string>     $offsets top/right/bottom/left for position: absolute
     */
    public function __construct(
        public readonly array $box = [],
        public readonly array $view = [],
        public readonly array $layout = [],
        public readonly array $text = [],
        public readonly ?array $margin = null,
        public readonly ?string $flex = null,
        public readonly ?string $position = null,
        public readonly array $offsets = [],
        public readonly ?int $transition = null,
    ) {
    }

    /** @return list<string> Slint properties whose value changes at runtime (animated by transition) */
    public function animated(): array
    {
        $out = [];
        foreach ([$this->box, $this->view, $this->layout, $this->text] as $map) {
            foreach ($map as $property => $value) {
                if (str_contains($value, 'root.') || str_contains($value, '?')) {
                    $out[] = $property;
                }
            }
        }
        return $out;
    }

    /** @return list<string> `animate` lines for the animated properties of $properties, if a transition is set */
    public function animations(array $properties): array
    {
        if ($this->transition === null) {
            return [];
        }
        $lines = [];
        foreach (array_intersect($this->animated(), $properties) as $property) {
            $lines[] = "animate $property { duration: {$this->transition}ms; easing: ease-out; }";
        }
        return $lines;
    }
}
