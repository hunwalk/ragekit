<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

/**
 * screens.json for dev mode: the same facts RageKit\Generated\Screens encodes as PHP code (which
 * value feeds which Slint property, which action runs which handler), as data the dev runtime
 * reloads without restarting PHP.
 */
final class ScreensJson
{
    /** @param list<WindowModel> $windows */
    public static function emit(array $windows): string
    {
        $out = [];
        foreach ($windows as $window) {
            $pages = [];
            foreach ($window->pages as $screen) {
                $pages[] = [
                    'class' => $screen->class->name,
                    'name' => $screen->class->short,
                    'page' => $screen->page,
                    'values' => self::values($screen->bindings),
                    'actions' => self::actions($screen->actions, $screen),
                ];
            }
            $out[] = [
                'name' => $window->name,
                'window' => $window->frame?->name,
                'frameless' => $window->frame !== null,
                'values' => self::values($window->bindings),
                'actions' => self::actions($window->actions, $window->pages[0] ?? null),
                'pages' => $pages,
            ];
        }
        return json_encode(['windows' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * @param array<string, Binding> $bindings
     * @return list<array<string, mixed>>
     */
    private static function values(array $bindings): array
    {
        $values = [];
        foreach ($bindings as $binding) {
            $values[] = ['id' => $binding->id, 'owner' => $binding->owner, 'source' => $binding->source, 'name' => $binding->name, 'type' => $binding->type];
        }
        return $values;
    }

    /**
     * @param list<Action> $actions
     * @return list<array<string, mixed>>
     */
    private static function actions(array $actions, ?ScreenModel $screen): array
    {
        $out = [];
        foreach ($actions as $action) {
            $out[] = $action->kind === 'set'
                ? ['id' => $action->id, 'owner' => $action->owner, 'kind' => 'set', 'target' => $action->target, 'binding' => $action->binding->id, 'type' => $action->binding->type]
                : ['id' => $action->id, 'owner' => $action->owner, 'kind' => 'call', 'target' => $action->target, 'args' => $action->args, 'params' => $action->params ?? $screen?->class->handlers[$action->target] ?? []];
        }
        return $out;
    }
}
