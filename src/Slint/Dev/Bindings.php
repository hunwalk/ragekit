<?php

declare(strict_types=1);

namespace RageKit\Slint\Dev;

use RageKit\Navigator;

/**
 * What RageKit\Generated\Screens does in release builds, read from screens.json instead of
 * generated code: each window component's pages, the shown values of a window (its layout's,
 * the screen on top's) and what its actions run (same conversions as Cli/Slint/BinderEmitter.php).
 */
final class Bindings
{
    /**
     * @param array<string, array> $pages by screen class: the page, plus 'window' (its window entry)
     */
    private function __construct(private readonly array $pages)
    {
    }

    public static function load(string $json): self
    {
        $pages = [];
        foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR)['windows'] as $window) {
            $window['actions'] = self::byId($window['actions']);
            foreach ($window['pages'] as $page) {
                $page['actions'] = self::byId($page['actions']);
                $page['window'] = $window;
                unset($page['window']['pages']);
                $pages[$page['class']] = $page;
            }
        }
        return new self($pages);
    }

    /** @param list<array> $actions @return array<int, array> */
    private static function byId(array $actions): array
    {
        $out = [];
        foreach ($actions as $action) {
            $out[$action['id']] = $action;
        }
        return $out;
    }

    public function window(object $screen): string
    {
        return $this->entry($screen)['window']['name'];
    }

    public function page(object $screen): int
    {
        return $this->entry($screen)['page'];
    }

    /** $screen's page entry of screens.json, with its window's under 'window'. */
    private function entry(object $screen): array
    {
        return $this->pages[get_class($screen)] ?? throw new \RuntimeException('No template for ' . get_class($screen));
    }

    /** A new instance of the Layout framing $screen's window, or null. */
    public function layout(object $screen): ?object
    {
        $class = $this->entry($screen)['window']['layout'] ?? null;
        return $class === null ? null : new $class();
    }

    /** @return array<int, mixed> property id => current value: the window's and the top screen's */
    public function values(Navigator $n): array
    {
        $page = $this->entry($n->top());
        $values = [];
        foreach ([...$page['window']['values'], ...$page['values']] as $value) {
            $object = self::owner($n, $value);
            if ($object === null) {
                return [];
            }
            $read = $value['source'] === 'method' ? $object->{$value['name']}() : $object->{$value['name']};
            $values[$value['id']] = $value['type'] === 'list' ? array_map('strval', array_values((array) $read)) : $read;
        }
        ksort($values);
        return $values;
    }

    /**
     * Runs an action of the window or of the screen on top (another page's action does nothing);
     * returns the id of a property the UI already shows (two-way input), else 0.
     */
    public function dispatch(Navigator $n, int $action, string $arg): int
    {
        $page = $this->entry($n->top());
        $spec = $page['window']['actions'][$action] ?? $page['actions'][$action] ?? null;
        $object = $spec === null ? null : self::owner($n, $spec);
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
            $args[] = match ($kind) {
                'arg' => self::convert($arg, $type),
                'expr' => self::convert(explode("\x1F", $arg)[(int) $value] ?? '', $type),
                default => self::literal((string) $value, $type),
            };
        }
        $object->{$spec['target']}(...$args);
        return 0;
    }

    /** Whose value or action an entry is: the layout's, the window's (navigator), else the screen on top. */
    private static function owner(Navigator $n, array $entry): ?object
    {
        return match ($entry['owner'] ?? 'screen') {
            'layout' => $n->layout(),
            'window' => $n,
            default => $n->top(),
        };
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
