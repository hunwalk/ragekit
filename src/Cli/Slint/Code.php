<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Stub;

/** Small helpers for building generated source text. */
final class Code
{
    /** Renders stubs/<name>.slint.stub (see Stub). @param array<string, string> $values */
    public static function stub(string $name, array $values): string
    {
        return Stub::render(__DIR__ . "/stubs/$name.slint.stub", $values);
    }

    /**
     * The most common shape: `[prefix]Component { lines… children… }` (stubs/element.slint.stub).
     * $prefix puts it under a Slint `if …` / `for …`, e.g. "if root.p3 : ".
     *
     * @param list<string> $lines properties and callbacks, one Slint statement each
     */
    public static function block(string $component, array $lines, string $children = '', string $prefix = ''): string
    {
        return self::stub('element', ['prefix' => $prefix, 'component' => $component, 'lines' => implode("\n", $lines), 'children' => $children]);
    }

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
