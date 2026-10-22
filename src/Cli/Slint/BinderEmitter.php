<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Stub;

/**
 * RageKit\Generated\Screens: statically typed PHP that reads the shown values of a window (its
 * layout's, the top screen's) and runs its actions — no dynamic property access, so TypePHP (and
 * Nano) compile it directly.
 */
final class BinderEmitter
{
    /** @param list<WindowModel> $windows */
    public static function emit(array $windows): string
    {
        $names = $pages = $layouts = $values = $dispatch = $helpers = [];
        foreach ($windows as $window) {
            foreach ($window->pages as $screen) {
                $class = '\\' . $screen->class->name;
                $short = $screen->class->short;
                $names[] = "if (\$screen instanceof $class) {\n    return '{$window->name}';\n}";
                $pages[] = "if (\$screen instanceof $class) {\n    return {$screen->page};\n}";
                if ($screen->layout === null) {
                    $values[] = "if (\$s instanceof $class) {\n    return self::values$short(\$n, \$s);\n}";
                    $dispatch[] = "if (\$s instanceof $class) {\n    return self::dispatch$short(\$n, \$s, \$action, \$arg);\n}";
                } else {
                    // A screen with a layout: its values and actions also reach the layout object.
                    $layout = '\\' . $screen->layout->name;
                    $layouts[] = "if (\$screen instanceof $class) {\n    return new $layout();\n}";
                    $values[] = rtrim(self::stub('binder-layout-call', ['class' => $class, 'layout' => $layout, 'method' => "values$short", 'args' => '$n, $s, $l', 'fallback' => '[]']), "\n");
                    $dispatch[] = rtrim(self::stub('binder-layout-call', ['class' => $class, 'layout' => $layout, 'method' => "dispatch$short", 'args' => '$n, $s, $l, $action, $arg', 'fallback' => '0']), "\n");
                }
                $helpers[] = self::valuesMethod($screen);
                $helpers[] = self::dispatchMethod($screen);
            }
        }
        return self::stub('binder', [
            'windows' => implode("\n", $names),
            'pages' => implode("\n", $pages),
            'layouts' => implode("\n", $layouts),
            'values' => implode("\n", $values),
            'dispatch' => implode("\n", $dispatch),
            'helpers' => implode("\n", $helpers),
        ]);
    }

    /** A page's values: the window's (layout, top screen, toasts) and its own, by id. */
    private static function valuesMethod(ScreenModel $screen): string
    {
        $bindings = [...array_values($screen->window->bindings), ...array_values($screen->bindings)];
        usort($bindings, fn(Binding $a, Binding $b) => $a->id <=> $b->id);
        $entries = [];
        foreach ($bindings as $binding) {
            $object = match ($binding->owner) {
                'layout' => '$l',
                'window' => '$n',
                default => '$s',
            };
            $read = $binding->source === 'method' ? "$object->{$binding->name}()" : "$object->{$binding->name}";
            if ($binding->type === 'list') {
                $read = "self::strings($read)";
            }
            $entries[] = "{$binding->id} => $read,";
        }
        return self::stub('binder-values', [
            'short' => $screen->class->short,
            'class' => $screen->class->name,
            'layoutParam' => self::layoutParam($screen),
            'entries' => implode("\n", $entries),
        ]);
    }

    /** A page's actions: the window's (layout, top screen) and its own; other pages' ids fall to default. */
    private static function dispatchMethod(ScreenModel $screen): string
    {
        $actions = [...$screen->window->actions, ...$screen->actions];
        usort($actions, fn(Action $a, Action $b) => $a->id <=> $b->id);
        $cases = [];
        foreach ($actions as $action) {
            $object = $action->owner === 'layout' ? '$l' : '$s';
            if ($action->kind === 'set') {
                $cases[] = "case {$action->id}:\n"
                    . "    $object->{$action->target} = " . self::convert('$arg', $action->binding->type) . ";\n"
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
                . "    $object->{$action->target}(" . implode(', ', $args) . ");\n"
                . '    return 0;';
        }
        return self::stub('binder-dispatch', [
            'short' => $screen->class->short,
            'class' => $screen->class->name,
            'layoutParam' => self::layoutParam($screen),
            'cases' => implode("\n", $cases),
        ]);
    }

    /** @param array<string, string> $values */
    private static function stub(string $name, array $values): string
    {
        return Stub::render(__DIR__ . "/stubs/$name.php.stub", $values);
    }

    private static function layoutParam(ScreenModel $screen): string
    {
        return $screen->layout === null ? '' : ", \\{$screen->layout->name} \$l";
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
