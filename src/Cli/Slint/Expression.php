<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/** Attribute values of .rk templates: literal text with {name} references, as Slint strings. */
final class Expression
{
    private const REF = '/\{([A-Za-z_][A-Za-z0-9_]*)\}/';

    /** @return list<array{0: 'text'|'ref', 1: string}> */
    public static function parts(string $raw): array
    {
        preg_match_all(self::REF, $raw, $matches, PREG_OFFSET_CAPTURE);
        $parts = [];
        $offset = 0;
        foreach ($matches[0] as $i => [$whole, $position]) {
            if ($position > $offset) {
                $parts[] = ['text', substr($raw, $offset, $position - $offset)];
            }
            $parts[] = ['ref', $matches[1][$i][0]];
            $offset = $position + strlen($whole);
        }
        if ($offset < strlen($raw)) {
            $parts[] = ['text', substr($raw, $offset)];
        }
        return $parts;
    }

    public static function hasRefs(string $raw): bool
    {
        return preg_match(self::REF, $raw) === 1;
    }

    /** Escapes text for the inside of a Slint string literal. */
    public static function escape(string $text): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $text);
    }

    public static function slintString(string $text): string
    {
        return '"' . self::escape($text) . '"';
    }
}
