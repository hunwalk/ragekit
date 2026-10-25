<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\ClassInfo;
use RageKit\Cli\Templates\Element;
use RageKit\Cli\Templates\TemplateException;

/** The app's Collection subclasses, and one RowType per item class they hold. */
final class RowTypes
{
    /** @var array<string, RowType> by item class */
    private array $types = [];

    /** @param array<string, ClassInfo> $collections by collection class */
    public function __construct(private readonly array $collections = [])
    {
    }

    /** The rows of collection class $type (a PHP type as introspection reports it), or null when it isn't one. */
    public function for(string $type, Element $at): ?RowType
    {
        $collection = $this->collections[$type] ?? null;
        if ($collection === null) {
            return null;
        }
        if ($collection->of === '') {
            throw TemplateException::at($at, "{$collection->short}::OF is empty; set public const OF = Item::class");
        }
        if (!$collection->ofFound) {
            throw TemplateException::at($at, "{$collection->short}::OF names {$collection->of}, which doesn't exist");
        }
        if (!isset($this->types[$collection->of])) {
            $slash = strrpos($collection->of, '\\');
            $base = 'RkRow' . ($slash === false ? $collection->of : substr($collection->of, $slash + 1));
            $taken = array_map(fn(RowType $t) => $t->struct, $this->types);
            $struct = $base;
            for ($n = 2; in_array($struct, $taken, true); $n++) {
                $struct = $base . $n;
            }
            $this->types[$collection->of] = new RowType($collection, $collection->of, $struct);
        }
        return $this->types[$collection->of];
    }

    /** @return list<RowType> */
    public function all(): array
    {
        return array_values($this->types);
    }

    /** Every struct's declaration ('' when no template loops over a collection). */
    public function slint(): string
    {
        return implode("\n\n", array_map(fn(RowType $t) => $t->slint(), $this->all()));
    }
}
