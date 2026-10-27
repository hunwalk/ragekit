<?php

namespace RageKit\Slint;

/**
 * A collection (or a list shown as images) as the generated binder reads it: one list of cells
 * per row, in the row struct's field order. A cell is a string, int, float, bool, an ImageValue
 * for an image field, a list of strings for an array member, or an ImageList for one shown as images.
 */
final class RowsValue
{
    /** @param list<list<mixed>> $rows */
    public function __construct(public array $rows)
    {
    }

    /**
     * An array member as a list of strings (what a row's inner @foreach shows).
     *
     * @return list<string>
     */
    public static function strings(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $out[] = (string) $item;
        }
        return $out;
    }
}
