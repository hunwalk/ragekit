<?php

namespace RageKit\Slint;

/**
 * A collection (or a list shown as images) as the generated binder reads it: one list of cells
 * per row, in the row struct's field order. A cell is a string, int, float, bool, or an
 * ImageValue for an image field.
 */
final class RowsValue
{
    /** @param list<list<mixed>> $rows */
    public function __construct(public array $rows)
    {
    }
}
