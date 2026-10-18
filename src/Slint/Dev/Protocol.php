<?php

declare(strict_types=1);

namespace RageKit\Slint\Dev;

/**
 * The dev shell's message format (native/slint/devshell/protocol.cc is the C++ side): one
 * message per line, fields separated by tabs, with backslash, tab, newline and CR escaped.
 */
final class Protocol
{
    private const ESCAPE = ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r'];

    public static function encode(string ...$fields): string
    {
        return implode("\t", array_map(fn(string $field) => strtr($field, self::ESCAPE), $fields)) . "\n";
    }

    /** @return list<string> */
    public static function decode(string $line): array
    {
        $line = substr($line, -1) === "\n" ? substr($line, 0, -1) : $line;
        return array_map(fn(string $field) => strtr($field, array_flip(self::ESCAPE)), explode("\t", $line));
    }
}
