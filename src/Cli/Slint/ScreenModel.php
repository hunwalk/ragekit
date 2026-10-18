<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\ClassInfo;

/** Bindings and actions collected while compiling one Screen's template. */
final class ScreenModel
{
    /** @var array<string, Binding> by "source:name" */
    public array $bindings = [];

    /** @var list<Action> */
    public array $actions = [];

    public function __construct(public readonly ClassInfo $class)
    {
    }

    public function bind(string $source, string $name, string $type): Binding
    {
        return $this->bindings["$source:$name"] ??= new Binding(count($this->bindings) + 1, $source, $name, $type);
    }

    /** @param list<array{0: 'literal'|'arg', 1: string}> $args */
    public function action(string $kind, string $target, array $args, ?Binding $binding = null): Action
    {
        $action = new Action(count($this->actions) + 1, $kind, $target, $args, $binding);
        $this->actions[] = $action;
        return $action;
    }
}
