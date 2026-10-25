<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Stub;
use RageKit\Cli\Templates\AssetRegistry;

/**
 * RageKit\Generated\Screens: statically typed PHP that reads the shown values of a window (its
 * own, the top screen's) and runs its actions — no dynamic property access, so TypePHP (and
 * Nano) compile it directly.
 */
final class BinderEmitter
{
    /**
     * @param list<WindowModel> $windows
     * @param string|null $rootStore the app's RootStore subclass, which $store bindings read through get()
     * @param AssetRegistry|null $assets the app's registered images: assetIndex() maps their paths
     * @param list<string> $modules the native modules the app enables: with http, fetchImage() downloads
     */
    public static function emit(array $windows, ?string $rootStore = null, ?AssetRegistry $assets = null, array $modules = []): string
    {
        $components = $pages = $news = $values = $dispatch = $helpers = [];
        foreach ($windows as $window) {
            $frame = '\\' . ($window->frame?->name ?? 'RageKit\\Window');
            foreach ($window->pages as $screen) {
                $class = '\\' . $screen->class->name;
                $short = $screen->class->short;
                $components[] = "if (\$screen instanceof $class) {\n    return '{$window->name}';\n}";
                $pages[] = "if (\$screen instanceof $class) {\n    return {$screen->page};\n}";
                $news[] = "if (\$screen instanceof $class) {\n    return new $frame();\n}";
                // Its values and actions also reach the window object, typed as its class.
                $values[] = rtrim(self::stub('binder-window-call', ['class' => $class, 'window' => $frame, 'method' => "values$short", 'args' => '$w, $s', 'fallback' => '[]']), "\n");
                $dispatch[] = rtrim(self::stub('binder-window-call', ['class' => $class, 'window' => $frame, 'method' => "dispatch$short", 'args' => '$w, $s, $action, $arg', 'fallback' => '0']), "\n");
                $helpers[] = self::valuesMethod($screen, $frame, $rootStore);
                $helpers[] = self::dispatchMethod($screen, $frame, $rootStore);
            }
        }
        foreach (self::rowTypes($windows) as $row) {
            $helpers[] = self::rowsMethod($row);
        }
        return self::stub('binder', [
            'components' => implode("\n", $components),
            'pages' => implode("\n", $pages),
            'windows' => implode("\n", $news),
            'values' => implode("\n", $values),
            'dispatch' => implode("\n", $dispatch),
            'helpers' => implode("\n", $helpers),
            'assetIndex' => self::assetIndex($assets ?? AssetRegistry::empty()),
            'fetchImage' => in_array('http', $modules, true) ? self::stub('binder-fetch-image', []) : 'return false;',
        ]);
    }

    /** if ($source === 'assets/logo.png') { return 0; } … for every registered image. */
    private static function assetIndex(AssetRegistry $assets): string
    {
        $lines = [];
        foreach ($assets->paths() as $index => $path) {
            $lines[] = 'if ($source === ' . var_export($path, true) . ") {\n    return $index;\n}";
        }
        return implode("\n", $lines);
    }

    /** A page's values: the window's (its own, top screen, toasts) and the page's, by id. */
    private static function valuesMethod(ScreenModel $screen, string $frame, ?string $rootStore): string
    {
        $bindings = [...array_values($screen->window->bindings), ...array_values($screen->bindings)];
        usort($bindings, fn(Binding $a, Binding $b) => $a->id <=> $b->id);
        $entries = [];
        $owners = [];
        foreach ($bindings as $binding) {
            $owners[] = $binding->owner;
            $member = self::member($binding->owner, $binding->name);
            $read = $binding->source === 'method' ? "$member()" : $member;
            if ($binding->type === 'list') {
                $read = "self::strings($read)";
            }
            if ($binding->type === 'image') {
                $read = "new \\RageKit\\Slint\\ImageValue((string) $read)";
            }
            if ($binding->type === 'images') {
                $read = "self::images($read)";
            }
            if ($binding->type === 'rows') {
                $read = "self::rows{$binding->row->struct}($read)";
            }
            $entries[] = "{$binding->id} => $read,";
        }
        return self::stub('binder-values', [
            'short' => $screen->class->short,
            'class' => $screen->class->name,
            'window' => ltrim($frame, '\\'),
            'root' => self::root($owners, $rootStore),
            'entries' => implode("\n", $entries),
        ]);
    }

    /**
     * The row types the windows' bindings use, each once.
     *
     * @param list<WindowModel> $windows
     * @return list<RowType>
     */
    private static function rowTypes(array $windows): array
    {
        $types = [];
        foreach ($windows as $window) {
            $bindings = array_values($window->bindings);
            foreach ($window->pages as $page) {
                $bindings = [...$bindings, ...array_values($page->bindings)];
            }
            foreach ($bindings as $binding) {
                if ($binding->row !== null) {
                    $types[$binding->row->struct] = $binding->row;
                }
            }
        }
        ksort($types);
        return array_values($types);
    }

    /** rows<Struct>(): a collection's items as rows of cells, narrowed to the item class (TypePHP needs the type). */
    private static function rowsMethod(RowType $row): string
    {
        $cells = [];
        foreach ($row->fields() as $field) {
            $read = '$item->' . $field->member . ($field->source === 'method' ? '()' : '');
            $cells[] = match ($field->type) {
                'image' => "new \\RageKit\\Slint\\ImageValue((string) $read)",
                default => "({$field->type}) $read",
            };
        }
        return rtrim(self::stub('binder-rows', ['item' => $row->item, 'struct' => $row->struct, 'cells' => implode(', ', $cells)]), "\n");
    }

    /** A page's actions: the window's (its own, top screen) and the page's; other pages' ids fall to default. */
    private static function dispatchMethod(ScreenModel $screen, string $frame, ?string $rootStore): string
    {
        $actions = [...$screen->window->actions, ...$screen->actions];
        usort($actions, fn(Action $a, Action $b) => $a->id <=> $b->id);
        $cases = [];
        $owners = [];
        foreach ($actions as $action) {
            $owners[] = $action->owner;
            $member = self::member($action->owner, $action->target);
            if ($action->kind === 'set') {
                $cases[] = "case {$action->id}:\n"
                    . "    $member = " . self::convert('$arg', $action->binding->type) . ";\n"
                    . "    return {$action->binding->id};";
                continue;
            }
            $params = $action->params ?? $screen->class->handlers[$action->target] ?? [];
            $args = [];
            foreach ($action->args as $i => [$kind, $value]) {
                $args[] = match ($kind) {
                    'arg' => self::convert('$arg', $params[$i] ?? 'string'),
                    'expr' => self::convert("self::part(\$arg, $value)", $params[$i] ?? 'string'),
                    default => self::literal((string) $value, $params[$i] ?? 'string'),
                };
            }
            $cases[] = "case {$action->id}:\n"
                . "    $member(" . implode(', ', $args) . ");\n"
                . '    return 0;';
        }
        return self::stub('binder-dispatch', [
            'short' => $screen->class->short,
            'class' => $screen->class->name,
            'window' => ltrim($frame, '\\'),
            'root' => self::root($owners, $rootStore),
            'cases' => implode("\n", $cases),
        ]);
    }

    /** A binding's or action's member: the window's ($w), the app's stores ($r, "session.userName"), else the screen's ($s). */
    private static function member(string $owner, string $name): string
    {
        return match ($owner) {
            'window' => "\$w->$name",
            'store' => '$r->' . str_replace('.', '->', $name),
            default => "\$s->$name",
        };
    }

    /**
     * `$r = \App\AppStore::get();` when one of $owners is the store, else nothing.
     *
     * @param list<string> $owners
     */
    private static function root(array $owners, ?string $rootStore): string
    {
        if (!in_array('store', $owners, true)) {
            return '';
        }
        if ($rootStore === null) {
            throw new \LogicException('$store bindings need the app\'s RootStore class');
        }
        return "\$r = \\$rootStore::get();";
    }

    /** @param array<string, string> $values */
    private static function stub(string $name, array $values): string
    {
        return Stub::render(__DIR__ . "/stubs/$name.php.stub", $values);
    }

    /** A template's quoted literal as a PHP literal of the parameter's type, converted at build time. */
    private static function literal(string $value, string $type): string
    {
        return match (ltrim($type, '?')) {
            'bool' => in_array(strtolower($value), ['true', '1'], true) ? 'true' : 'false',
            'int' => (string) (int) $value,
            'float' => self::floatLiteral((float) $value),
            default => var_export($value, true),
        };
    }

    private static function floatLiteral(float $value): string
    {
        $text = var_export($value, true);
        return str_contains($text, '.') || str_contains($text, 'E') || str_contains($text, 'N') || str_contains($text, 'I') ? $text : $text . '.0';
    }

    private static function convert(string $expression, string $type): string
    {
        return match (ltrim($type, '?')) {
            'int' => "(int) $expression",
            'float' => "(float) $expression",
            'bool' => "$expression === '1'",
            default => $expression,
        };
    }
}
