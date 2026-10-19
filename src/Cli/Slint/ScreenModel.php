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

    /** @param ClassInfo|null $layout the Layout framing this screen's window (null: none) */
    public function __construct(public readonly ClassInfo $class, public readonly ?ClassInfo $layout = null)
    {
    }

    public function bind(string $source, string $name, string $type, string $owner = 'screen'): Binding
    {
        return $this->bindings["$owner:$source:$name"] ??= new Binding(count($this->bindings) + 1, $source, $name, $type, false, $owner);
    }

    /**
     * @param list<array{0: 'literal'|'arg', 1: string}> $args
     * @param list<string>|null $params
     */
    public function action(string $kind, string $target, array $args, ?Binding $binding = null, string $owner = 'screen', ?array $params = null): Action
    {
        $action = new Action(count($this->actions) + 1, $kind, $target, $args, $binding, $owner, $params);
        $this->actions[] = $action;
        return $action;
    }
}
