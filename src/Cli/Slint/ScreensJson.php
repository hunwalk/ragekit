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
    /** @param list<ScreenModel> $screens */
    public static function emit(array $screens): string
    {
        $out = [];
        foreach ($screens as $screen) {
            $values = [];
            foreach ($screen->bindings as $binding) {
                $values[] = ['id' => $binding->id, 'owner' => $binding->owner, 'source' => $binding->source, 'name' => $binding->name, 'type' => $binding->type];
            }
            $actions = [];
            foreach ($screen->actions as $action) {
                $actions[] = $action->kind === 'set'
                    ? ['id' => $action->id, 'owner' => $action->owner, 'kind' => 'set', 'target' => $action->target, 'binding' => $action->binding->id, 'type' => $action->binding->type]
                    : ['id' => $action->id, 'owner' => $action->owner, 'kind' => 'call', 'target' => $action->target, 'args' => $action->args, 'params' => $action->params ?? $screen->class->handlers[$action->target] ?? []];
            }
            $out[] = [
                'class' => $screen->class->name,
                'name' => $screen->class->short,
                'layout' => $screen->layout?->name,
                'frameless' => $screen->layout !== null,
                'values' => $values,
                'actions' => $actions,
            ];
        }
        return json_encode(['screens' => $out], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}
