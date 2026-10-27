<?php

declare(strict_types=1);

namespace RageKit\Slint\Dev;

use RageKit\RootStore;
use RageKit\Window;

/**
 * What RageKit\Generated\Screens does in release builds, read from screens.json instead of
 * generated code: each window component's pages, the shown values of a window (its own, the
 * screen on top's, the stores') and what its actions run (same conversions as
 * Cli/Slint/BinderEmitter.php).
 */
final class Bindings
{
    /**
     * @param array<string, array> $pages by screen class: the page, plus 'window' (its window entry)
     * @param array<string, array<int, string>> $types by window component: each value id's type
     */
    private function __construct(private readonly array $pages, private readonly array $types = [])
    {
    }

    public static function load(string $json): self
    {
        $pages = [];
        $types = [];
        foreach (json_decode($json, true, flags: JSON_THROW_ON_ERROR)['windows'] as $window) {
            foreach ([$window, ...$window['pages']] as $entry) {
                foreach ($entry['values'] as $value) {
                    $types[$window['name']][$value['id']] = $value['type'];
                }
            }
            $window['actions'] = self::byId($window['actions']);
            foreach ($window['pages'] as $page) {
                $page['actions'] = self::byId($page['actions']);
                $page['window'] = $window;
                unset($page['window']['pages']);
                $pages[$page['class']] = $page;
            }
        }
        return new self($pages, $types);
    }

    /** The type of value $id in window component $component ('' if it has none). */
    public function type(string $component, int $id): string
    {
        return $this->types[$component][$id] ?? '';
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

    public function component(object $screen): string
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

    /** A new Window of the class $screen's window() names (Window itself for a plain one). */
    public function newWindow(object $screen): Window
    {
        $class = $this->entry($screen)['window']['window'] ?? null;
        return $class === null ? new Window() : new $class();
    }

    /** @return array<int, mixed> property id => current value: the window's and the top screen's */
    public function values(Window $w): array
    {
        $page = $this->entry($w->navigator()->top());
        $values = [];
        foreach ([...$page['window']['values'], ...$page['values']] as $value) {
            [$object, $name] = self::owner($w, $value, $value['name']);
            if ($object === null) {
                return [];
            }
            $read = $value['source'] === 'method' ? $object->{$name}() : $object->{$name};
            $values[$value['id']] = match ($value['type']) {
                'list' => array_map('strval', array_values((array) $read)),
                'image' => new \RageKit\Slint\ImageValue((string) $read),
                'images' => new \RageKit\Slint\RowsValue(array_map(fn($item) => [new \RageKit\Slint\ImageValue((string) $item)], array_values((array) $read))),
                'rows' => self::rows($read, $value['row']),
                default => $read,
            };
        }
        ksort($values);
        return $values;
    }

    /** A collection's items as rows, field by field (as the generated rows helpers do). */
    private static function rows(mixed $collection, array $row): \RageKit\Slint\RowsValue
    {
        $rows = [];
        foreach ($collection instanceof \RageKit\Collection ? $collection->all() : [] as $item) {
            if (!$item instanceof $row['item']) {
                continue;
            }
            $cells = [];
            foreach ($row['fields'] as $field) {
                $read = $field['source'] === 'method' ? $item->{$field['member']}() : $item->{$field['member']};
                $cells[] = match ($field['type']) {
                    'image' => new \RageKit\Slint\ImageValue((string) $read),
                    'strings' => \RageKit\Slint\RowsValue::strings((array) $read),
                    'images' => new \RageKit\Slint\ImageList(\RageKit\Slint\RowsValue::strings((array) $read)),
                    'int' => (int) $read,
                    'float' => (float) $read,
                    'bool' => (bool) $read,
                    default => (string) $read,
                };
            }
            $rows[] = $cells;
        }
        return new \RageKit\Slint\RowsValue($rows);
    }

    /**
     * Runs an action of the window or of the screen on top (another page's action does nothing);
     * returns the id of a property the UI already shows (two-way input), else 0.
     */
    public function dispatch(Window $w, int $action, string $arg): int
    {
        $page = $this->entry($w->navigator()->top());
        $spec = $page['window']['actions'][$action] ?? $page['actions'][$action] ?? null;
        [$object, $target] = $spec === null ? [null, ''] : self::owner($w, $spec, $spec['target']);
        if ($spec === null || $object === null) {
            return 0;
        }
        if ($spec['kind'] === 'set') {
            $object->{$target} = self::convert($arg, $spec['type']);
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
        $object->{$target}(...$args);
        return 0;
    }

    /**
     * Whose value or action an entry is, and the member's name there: the window's, a store's
     * ("session.userName" → the root's $session, userName), else the screen on top's.
     *
     * @return array{0: object|null, 1: string}
     */
    private static function owner(Window $w, array $entry, string $name): array
    {
        switch ($entry['owner'] ?? 'screen') {
            case 'window':
                return [$w, $name];
            case 'store':
                [$store, $member] = explode('.', $name, 2);
                $root = RootStore::current();
                return [$root === null ? null : $root->{$store}, $member];
            default:
                return [$w->navigator()->top(), $name];
        }
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
