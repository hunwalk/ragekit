<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/** Small helpers for building generated source text. */
final class Code
{
    /** Indents every non-empty line by $levels × 4 spaces; the result ends with a newline. */
    public static function indent(string $block, int $levels): string
    {
        $block = rtrim($block, "\n");
        if ($block === '') {
            return '';
        }
        $pad = str_repeat('    ', $levels);
        $out = '';
        foreach (explode("\n", $block) as $line) {
            $out .= ($line === '' ? '' : $pad . $line) . "\n";
        }
        return $out;
    }
}
