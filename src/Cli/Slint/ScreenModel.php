<?php

declare(strict_types=1);

namespace RageKit\Cli\Slint;

use RageKit\Cli\Templates\ClassInfo;

/**
 * One screen of a window component (a page): its own bindings and actions (owner 'screen'). Other
 * owners (layout, context, window) are the window's (WindowModel), shared by all its pages.
 */
final class ScreenModel
{
    /** @var array<string, Binding> by "owner:source:name" */
    public array $bindings = [];

    /** @var list<Action> */
    public array $actions = [];

    public readonly WindowModel $window;

    /** This screen's page in its window: rk-page shows it while it has this value. */
    public readonly int $page;

    /**
     * Adds itself as the next page of $window (by default a window of its own).
     *
     * @param ClassInfo|null $layout the Layout framing this screen's window (null: none)
     */
    public function __construct(public readonly ClassInfo $class, public readonly ?ClassInfo $layout = null, ?WindowModel $window = null)
    {
        $this->window = $window ?? new WindowModel(WindowModel::nameFor($layout), $layout);
        $this->page = count($this->window->pages);
        $this->window->pages[] = $this;
    }

    public function bind(string $source, string $name, string $type, string $owner = 'screen'): Binding
    {
        if ($owner !== 'screen') {
            return $this->window->bind($source, $name, $type, $owner);
        }
        return $this->bindings["$owner:$source:$name"] ??= new Binding($this->window->nextBindingId(), $source, $name, $type, false, $owner);
    }

    /**
     * @param list<array{0: 'literal'|'arg', 1: string}> $args
     * @param list<string>|null $params
     */
    public function action(string $kind, string $target, array $args, ?Binding $binding = null, string $owner = 'screen', ?array $params = null): Action
    {
        if ($owner !== 'screen') {
            return $this->window->action($kind, $target, $args, $binding, $owner, $params);
        }
        $action = new Action($this->window->nextActionId(), $kind, $target, $args, $binding, $owner, $params);
        $this->actions[] = $action;
        return $action;
    }
}
