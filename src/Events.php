<?php

namespace RageKit;

/**
 * Events from native modules (e.g. a sound finished), delivered on the UI thread like clicks:
 * after the handlers run, the open windows re-read their values.
 */
final class Events
{
    /** @var array<string, list<\Closure>> "module:handle:kind" => handlers, each fn(string $data) */
    private static array $handlers = [];

    public static function on(string $module, int $handle, string $kind, \Closure $fn): void
    {
        self::$handlers[$module . ':' . $handle . ':' . $kind][] = $fn;
    }

    /** Removes every handler of one module handle (e.g. an unloaded sound). */
    public static function off(string $module, int $handle): void
    {
        $prefix = $module . ':' . $handle . ':';
        foreach (array_keys(self::$handlers) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset(self::$handlers[$key]);
            }
        }
    }

    public static function deliver(string $module, int $handle, string $kind, string $data): void
    {
        $key = $module . ':' . $handle . ':' . $kind;
        if (!isset(self::$handlers[$key])) {
            return;
        }
        foreach (self::$handlers[$key] as $fn) {
            $fn($data);
        }
    }
}
