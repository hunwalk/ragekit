<?php

declare(strict_types=1);

namespace RageKit\Slint\Dev;

/**
 * What RageKit\Generated\Screens does in release builds, read from screens.json instead of
 * generated code: a screen's shown values and what its actions run (same conversions as
 * cli/Slint/BinderEmitter.php).
 */
final class Bindings
{
    /** @param array<string, array> $screens by class name */
    private function __construct(private readonly array $screens)
    {
    }

    public static function load(string $json): self
    {
        $screens = [];
        foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR)['screens'] as $screen) {
            $actions = [];
            foreach ($screen['actions'] as $action) {
                $actions[$action['id']] = $action;
            }
            $screen['actions'] = $actions;
            $screens[$screen['class']] = $screen;
        }
        return new self($screens);
    }

    public function name(object $screen): string
    {
        return $this->screen($screen)['name'];
    }

    /** A new instance of the Layout framing $screen's window, or null. */
    public function layout(object $screen): ?object
    {
        $class = $this->screen($screen)['layout'] ?? null;
        return $class === null ? null : new $class();
    }

    /** @return array<int, mixed> property id => current value */
    public function values(object $screen): array
    {
        $values = [];
        foreach ($this->screen($screen)['values'] as $value) {
            $object = self::owner($screen, $value);
            if ($object === null) {
                return [];
            }
            $read = $value['source'] === 'method' ? $object->{$value['name']}() : $object->{$value['name']};
            $values[$value['id']] = $value['type'] === 'list' ? array_map('strval', array_values((array) $read)) : $read;
        }
        return $values;
    }

    /** Runs an action; returns the id of a property the UI already shows (two-way input), else 0. */
    public function dispatch(object $screen, int $action, string $arg): int
    {
        $spec = $this->screen($screen)['actions'][$action] ?? null;
        $object = $spec === null ? null : self::owner($screen, $spec);
        if ($spec === null || $object === null) {
            return 0;
        }
        if ($spec['kind'] === 'set') {
            $object->{$spec['target']} = self::convert($arg, $spec['type']);
            return $spec['binding'];
        }
        $args = [];
        foreach ($spec['args'] as $i => [$kind, $value]) {
            $type = $spec['params'][$i] ?? 'string';
            $args[] = $kind === 'arg' ? self::convert($arg, $type) : self::literal((string) $value, $type);
        }
        $object->{$spec['target']}(...$args);
        return 0;
    }

    /** Whose value or action an entry is: the screen's, or its layout's (null before it has one). */
    private static function owner(object $screen, array $entry): ?object
    {
        if (($entry['owner'] ?? 'screen') !== 'layout') {
            return $screen;
        }
        return method_exists($screen, 'layoutObject') ? $screen->layoutObject() : null;
    }

    private function screen(object $screen): array
    {
        return $this->screens[get_class($screen)] ?? throw new \RuntimeException('No template for ' . get_class($screen));
    }

    private static function convert(string $value, string $type): mixed
    {
        return match (ltrim($type, '?')) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => $value === '1',
            default => $value,
        };
    }

    private static function literal(string $value, string $type): mixed
    {
        return match (ltrim($type, '?')) {
            'bool' => in_array(strtolower($value), ['true', '1'], true),
            'int' => (int) $value,
            'float' => (float) $value,
            default => $value,
        };
    }
}
