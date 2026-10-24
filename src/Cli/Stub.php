<?php

declare(strict_types=1);

namespace RageKit\Cli;

/**
 * A stub: a file of generated code with {{ name }} placeholders (Laravel's stubs). A placeholder
 * alone on its line takes a block: every line of the value is indented like the placeholder,
 * and an empty value removes the line. Elsewhere a placeholder takes a one-line value.
 * A placeholder without a value is an error, and so is a value without a placeholder unless
 * $strict is false (an app's own stubs may leave values out).
 */
final class Stub
{
    /** @var array<string, string> path => contents */
    private static array $cache = [];

    /** @param array<string, string> $values */
    public static function render(string $path, array $values, bool $strict = true): string
    {
        // CRLF (a Windows checkout) would hide a block placeholder behind its \r.
        $stub = self::$cache[$path] ??= str_replace("\r\n", "\n", (string) (@file_get_contents($path) ?: throw new RageKitException("stub not found: $path")));
        $used = [];
        $out = [];
        foreach (explode("\n", $stub) as $line) {
            if (preg_match('/^(\s*)\{\{ (\w[\w-]*) \}\}$/', $line, $m) === 1) {
                $value = rtrim(self::value($values, $m[2], $path, $used), "\n");
                if ($value !== '') {
                    foreach (explode("\n", $value) as $valueLine) {
                        $out[] = $valueLine === '' ? '' : $m[1] . $valueLine;
                    }
                }
                continue;
            }
            $out[] = preg_replace_callback('/\{\{ (\w[\w-]*) \}\}/', function (array $m) use ($values, $path, &$used): string {
                $value = self::value($values, $m[1], $path, $used);
                if (str_contains($value, "\n")) {
                    throw new RageKitException("stub $path: {{ {$m[1]} }} is inside a line, so its value must be one line");
                }
                return $value;
            }, $line);
        }
        $unused = array_diff(array_keys($values), array_keys($used));
        if ($strict && $unused !== []) {
            throw new RageKitException("stub $path has no placeholder for: " . implode(', ', $unused));
        }
        return implode("\n", $out);
    }

    /**
     * @param array<string, string> $values
     * @param array<string, true> $used
     */
    private static function value(array $values, string $name, string $path, array &$used): string
    {
        if (!array_key_exists($name, $values)) {
            throw new RageKitException("stub $path: no value for {{ $name }}");
        }
        $used[$name] = true;
        return $values[$name];
    }
}
